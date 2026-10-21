<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Services\OrderCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(private readonly OrderCancellationService $cancellation) {}

    /**
     * Daftar status pesanan yang sah (untuk filter).
     */
    private const STATUSES = [
        'pending',
        'paid',
        'processing',
        'ready_to_ship',
        'shipping',
        'delivered',
        'completed',
        'cancelled',
    ];

    /**
     * Riwayat pesanan milik pelanggan yang login (filter status opsional).
     */
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        $orders = Order::with(['orderDetails.product', 'payment', 'shipment'])
            ->where('user_id', $request->user()->id)
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $counts = Order::where('user_id', $request->user()->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('orders.index', [
            'orders' => $orders,
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    /**
     * Detail satu pesanan. Hanya pemilik yang boleh melihat (selain itu 404).
     */
    public function show(Request $request, Order $order): View
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        $order->load([
            'address',
            'payment',
            'shipment.courier',
            'orderDetails.product',
        ]);

        return view('orders.show', [
            'order' => $order,
        ]);
    }

    /**
     * Pelanggan membatalkan pesanan miliknya sendiri — hanya sebelum bayar
     * (status pending). Stok otomatis dikembalikan oleh service.
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        if ($order->status !== 'pending') {
            return back()->withErrors([
                'order' => 'Hanya pesanan yang belum dibayar (pending) yang dapat Anda batalkan. Status saat ini: '.strtoupper($order->status).'.',
            ]);
        }

        // Daftar status diteruskan & DIPERIKSA ULANG di dalam transaksi terkunci
        // (mencegah pembatalan+restock atas pesanan yang baru saja terverifikasi
        // atau terkirim oleh pihak lain — TOCTOU).
        if (! $this->cancellation->cancel($order, 'Dibatalkan oleh pelanggan sebelum pembayaran.', OrderCancellationService::CANCELLABLE_BY_CUSTOMER)) {
            return back()->withErrors([
                'order' => 'Pesanan tidak dapat dibatalkan — status sudah berubah (sudah dibatalkan sebelumnya atau sudah dibayar).',
            ]);
        }

        ActivityLog::record(
            'order.cancelled',
            $order,
            'Pelanggan membatalkan pesanan #'.$order->order_number.' sebelum pembayaran'
        );

        return back()->with('success', 'Pesanan #'.$order->order_number.' dibatalkan. Stok telah dikembalikan ke katalog.');
    }
}
