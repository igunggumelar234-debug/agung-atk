<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * Status pesanan yang boleh dilihat penjual:
     * semua pesanan yang SUDAH DIBAYAR beserta status turunannya.
     * Pending (belum bayar) dan cancelled tidak termasuk.
     */
    private const VISIBLE_STATUSES = ['paid', 'processing', 'ready_to_ship', 'shipping', 'delivered', 'completed'];

    /**
     * Daftar pesanan (sudah dibayar) dengan filter status & pagination.
     */
    public function index(Request $request): View
    {
        $query = Order::with(['user', 'payment', 'orderDetails'])
            ->whereIn('status', self::VISIBLE_STATUSES)
            ->latest();

        $statusFilter = null;

        if ($request->filled('status') && in_array($request->status, self::VISIBLE_STATUSES, true)) {
            $statusFilter = $request->status;
            $query->where('status', $statusFilter);
        }

        $orders = $query->paginate(10)->withQueryString();

        $statusCounts = Order::whereIn('status', self::VISIBLE_STATUSES)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('seller.orders.index', [
            'orders' => $orders,
            'statusFilter' => $statusFilter,
            'statusCounts' => $statusCounts,
            'visibleStatuses' => self::VISIBLE_STATUSES,
            'statusLabels' => [
                'paid' => 'Sudah Dibayar',
                'processing' => 'Diproses',
                'ready_to_ship' => 'Siap Kirim',
                'shipping' => 'Dikirim',
                'delivered' => 'Terkirim',
                'completed' => 'Selesai',
            ],
        ]);
    }

    /**
     * Detail pesanan (read-only) + aksi proses sesuai status.
     */
    public function show(Order $order): View
    {
        abort_unless(in_array($order->status, self::VISIBLE_STATUSES, true), 404);

        $order->load(['user', 'address', 'orderDetails', 'payment']);

        return view('seller.orders.show', [
            'order' => $order,
        ]);
    }

    /**
     * Proses pesanan: paid → processing.
     * Status DIPERIKSA ULANG pada baris terkunci di dalam transaksi agar
     * pesanan yang baru dibatalkan/dibatalkan-admin tidak bisa balik menjadi processing.
     */
    public function processing(Order $order): RedirectResponse
    {
        abort_unless(in_array($order->status, self::VISIBLE_STATUSES, true), 404);

        $updated = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'paid') {
                return false;
            }

            $locked->update(['status' => 'processing']);

            return true;
        });

        if (! $updated) {
            return back()->withErrors([
                'order' => 'Hanya pesanan berstatus PAID yang dapat diproses. Status saat ini: '.strtoupper($order->fresh()->status ?? $order->status).'.',
            ]);
        }

        ActivityLog::record('order.processing', $order, 'Pesanan #'.$order->order_number.' ditandai diproses (processing)');

        return back()->with('success', 'Pesanan #'.$order->order_number.' diproses (processing).');
    }

    /**
     * Tandai siap kirim: processing → ready_to_ship.
     * Cek ulang status di dalam kunci baris (TOCTOU): pesanan yang dibatalkan
     * atau sudah maju bersamaan tidak bisa dipaksa kembali.
     */
    public function readyToShip(Order $order): RedirectResponse
    {
        abort_unless(in_array($order->status, self::VISIBLE_STATUSES, true), 404);

        $updated = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'processing') {
                return false;
            }

            $locked->update(['status' => 'ready_to_ship']);

            return true;
        });

        if (! $updated) {
            return back()->withErrors([
                'order' => 'Hanya pesanan berstatus PROCESSING yang dapat ditandai siap kirim. Status saat ini: '.strtoupper($order->fresh()->status ?? $order->status).'.',
            ]);
        }

        ActivityLog::record('order.ready_to_ship', $order, 'Pesanan #'.$order->order_number.' ditandai siap kirim (ready_to_ship)');

        return back()->with('success', 'Pesanan #'.$order->order_number.' siap dikirim (ready_to_ship).');
    }
}
