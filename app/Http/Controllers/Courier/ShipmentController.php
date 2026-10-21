<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\ShipmentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    /**
     * Daftar shipment yang ditugaskan ke kurir yang login (hanya miliknya).
     */
    public function index(Request $request): View
    {
        $query = Shipment::with(['order.user', 'order.address'])
            ->where('courier_id', $request->user()->id)
            ->latest();

        $statusFilter = null;

        if ($request->filled('status') && in_array($request->status, ShipmentStatus::STATUSES, true)) {
            $statusFilter = $request->status;
            $query->where('status', $statusFilter);
        }

        $shipments = $query->paginate(9)->withQueryString();

        $stats = [
            'total' => Shipment::where('courier_id', $request->user()->id)->count(),
            'waiting' => Shipment::where('courier_id', $request->user()->id)->where('status', 'waiting')->count(),
            'picked_up' => Shipment::where('courier_id', $request->user()->id)->where('status', 'picked_up')->count(),
            'shipping' => Shipment::where('courier_id', $request->user()->id)->where('status', 'shipping')->count(),
            'delivered' => Shipment::where('courier_id', $request->user()->id)->where('status', 'delivered')->count(),
        ];

        return view('courier.shipments.index', [
            'shipments' => $shipments,
            'statusFilter' => $statusFilter,
            'stats' => $stats,
            'statusLabels' => ShipmentStatus::labels(),
        ]);
    }

    /**
     * Detail pengiriman: pesanan, alamat & telepon penerima, aksi status.
     * Hanya shipment yang ditugaskan ke kurir ini (lainnya → 404).
     */
    public function show(Shipment $shipment): View
    {
        abort_unless($shipment->courier_id === auth()->id(), 404);

        $shipment->load(['order.user', 'order.address', 'order.orderDetails']);

        return view('courier.shipments.show', [
            'shipment' => $shipment,
            'statusLabels' => ShipmentStatus::labels(),
        ]);
    }

    /**
     * Ubah status pengiriman (hanya maju satu langkah):
     * waiting → picked_up → shipping → delivered.
     *
     * Saat picked_up/shipping: order ready_to_ship → shipping.
     * Saat delivered: order fase pengiriman → delivered.
     */
    public function updateStatus(Request $request, Shipment $shipment): RedirectResponse
    {
        abort_unless($shipment->courier_id === auth()->id(), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['picked_up', 'shipping', 'delivered'])],
        ], [
            'status.required' => 'Status pengiriman wajib dipilih.',
            'status.in' => 'Status pengiriman tidak valid.',
        ]);

        $expected = ShipmentStatus::next($shipment->status);

        if ($data['status'] !== $expected) {
            return back()->withErrors([
                'shipment' => 'Transisi status tidak valid. Dari '.strtoupper($shipment->status)
                    .($expected !== null ? ' hanya dapat menuju '.strtoupper($expected).'.' : ' — pengiriman sudah selesai.'),
            ]);
        }

        // Transisi divalidasi ULANG pada baris terkunci di dalam transaksi:
        // klik ganda / permintaan bersamaan tidak pernah melompati langkah.
        $updated = DB::transaction(function () use ($shipment, $data) {
            $locked = Shipment::whereKey($shipment->id)->lockForUpdate()->first();

            if ($locked === null || ShipmentStatus::next($locked->status) !== $data['status']) {
                return false;
            }

            $updates = ['status' => $data['status']];

            if ($data['status'] === 'picked_up') {
                $updates['shipped_at'] = now();
            }

            if ($data['status'] === 'delivered') {
                $updates['delivered_at'] = now();
            }

            $locked->update($updates);

            // Sinkronkan status pesanan (kunci baris pesanan agar pembatalan /
            // verifikasi yang berjalan bersamaan tidak saling menimpa).
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->first();

            if ($order === null) {
                return true;
            }

            if (in_array($data['status'], ['picked_up', 'shipping'], true) && $order->status === 'ready_to_ship') {
                $order->update(['status' => 'shipping']);
            }

            if ($data['status'] === 'delivered' && in_array($order->status, ['ready_to_ship', 'shipping'], true)) {
                $order->update(['status' => 'delivered']);
            }

            return true;
        });

        if (! $updated) {
            $current = Shipment::whereKey($shipment->id)->value('status') ?? $shipment->status;
            $expected = ShipmentStatus::next($current);

            return back()->withErrors([
                'shipment' => 'Transisi status tidak valid. Dari '.strtoupper($current)
                    .($expected !== null ? ' hanya dapat menuju '.strtoupper($expected).'.' : ' — pengiriman sudah selesai.'),
            ]);
        }

        ActivityLog::record(
            'shipment.status_updated',
            $shipment,
            'Kurir memperbarui pengiriman '.$shipment->tracking_number.' menjadi '.strtoupper($data['status'])
        );

        return back()->with(
            'success',
            'Status pengiriman '.$shipment->tracking_number.' diperbarui menjadi '.strtoupper($data['status']).'.'
        );
    }
}
