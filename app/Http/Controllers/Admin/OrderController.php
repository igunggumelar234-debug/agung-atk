<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
     * Daftar seluruh pesanan toko (filter status + pencarian).
     */
    public function index(Request $request): View
    {
        $statuses = [
            'pending',
            'paid',
            'processing',
            'ready_to_ship',
            'shipping',
            'delivered',
            'completed',
            'cancelled',
        ];

        $status = $request->query('status');
        $status = in_array($status, $statuses, true) ? $status : null;
        $search = trim((string) $request->query('q', ''));

        $orders = Order::with(['user', 'payment', 'shipment'])
            ->withCount('orderDetails')
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q2) use ($search) {
                            $q2->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $counts = Order::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.orders.index', [
            'orders' => $orders,
            'status' => $status,
            'search' => $search,
            'counts' => $counts,
        ]);
    }

    /**
     * Rincian pesanan untuk admin (identitas pelanggan, pembayaran, pengiriman).
     */
    public function show(Order $order): View
    {
        $order->load([
            'user.role',
            'address',
            'payment.verifier',
            'shipment.courier',
            'orderDetails.product.category',
        ]);

        return view('admin.orders.show', [
            'order' => $order,
        ]);
    }

    /**
     * Batalkan pesanan (pending/paid/processing/ready_to_ship) beserta
     * pengembalian stok otomatis dalam satu transaksi.
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        if (! in_array($order->status, OrderCancellationService::CANCELLABLE_BY_ADMIN, true)) {
            return back()->withErrors([
                'order' => 'Hanya pesanan yang belum dikirim yang dapat dibatalkan. Status saat ini: '.strtoupper($order->status).'.',
            ]);
        }

        $data = $request->validate([
            'cancelled_reason' => ['required', 'string', 'max:500'],
        ], [
            'cancelled_reason.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        // Daftar status diperiksa ulang di dalam transaksi terkunci sehingga
        // pembatalan tidak pernah mengembalikan stok untuk pesanan yang maju
        // bersamaan (mis. baru saja diverifikasi / dikirim kurir).
        if (! $this->cancellation->cancel($order, $data['cancelled_reason'], OrderCancellationService::CANCELLABLE_BY_ADMIN)) {
            return back()->withErrors([
                'order' => 'Pesanan tidak dapat dibatalkan — status sudah berubah (sudah dibatalkan sebelumnya atau sudah dikirim).',
            ]);
        }

        ActivityLog::record(
            'order.cancelled',
            $order,
            'Admin membatalkan pesanan #'.$order->order_number.' — '.$data['cancelled_reason']
        );

        return back()->with('success', 'Pesanan #'.$order->order_number.' dibatalkan. Stok telah dikembalikan ke katalog.');
    }
}
