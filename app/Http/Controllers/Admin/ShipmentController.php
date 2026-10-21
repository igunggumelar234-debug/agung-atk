<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Support\ShipmentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    /**
     * Halaman admin: pesanan ready_to_ship (belum ada resi) + daftar shipment
     * dengan filter status.
     */
    public function index(Request $request): View
    {
        // Pesanan siap kirim yang BELUM memiliki shipment
        $readyOrders = Order::with(['user', 'orderDetails'])
            ->where('status', 'ready_to_ship')
            ->whereDoesntHave('shipment')
            ->latest()
            ->get();

        $couriers = $this->activeCouriers();

        // Daftar shipment + filter status
        $query = Shipment::with(['order.user', 'courier'])->latest();
        $statusFilter = null;

        if ($request->filled('status') && in_array($request->status, ShipmentStatus::STATUSES, true)) {
            $statusFilter = $request->status;
            $query->where('status', $statusFilter);
        }

        $shipments = $query->paginate(10)->withQueryString();

        $stats = [
            'ready_orders' => $readyOrders->count(),
            'total' => Shipment::count(),
            'waiting' => Shipment::where('status', 'waiting')->count(),
            'picked_up' => Shipment::where('status', 'picked_up')->count(),
            'shipping' => Shipment::where('status', 'shipping')->count(),
            'delivered' => Shipment::where('status', 'delivered')->count(),
        ];

        return view('admin.shipments.index', [
            'readyOrders' => $readyOrders,
            'couriers' => $couriers,
            'shipments' => $shipments,
            'statusFilter' => $statusFilter,
            'stats' => $stats,
            'statusLabels' => ShipmentStatus::labels(),
        ]);
    }

    /**
     * Buat shipment & tugaskan ke kurir (untuk pesanan ready_to_ship).
     */
    public function store(Request $request): RedirectResponse
    {
        $courierIds = $this->activeCouriers()->pluck('id');

        $data = $request->validate([
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')],
            'courier_id' => ['required', 'integer', Rule::in($courierIds)],
        ], [
            'order_id.required' => 'Pesanan harus dipilih.',
            'order_id.exists' => 'Pesanan tidak ditemukan.',
            'courier_id.required' => 'Kurir wajib dipilih.',
            'courier_id.in' => 'Kurir yang dipilih tidak valid atau tidak aktif.',
        ]);

        $order = Order::with('shipment')->findOrFail($data['order_id']);

        if ($order->status !== 'ready_to_ship') {
            return back()->withErrors([
                'order_id' => 'Hanya pesanan berstatus READY_TO_SHIP yang dapat dikirim. Status saat ini: '.strtoupper($order->status).'.',
            ]);
        }

        if ($order->shipment) {
            return back()->withErrors([
                'order_id' => 'Pesanan #'.$order->order_number.' sudah memiliki nomor resi.',
            ]);
        }

        $courier = User::findOrFail($data['courier_id']);

        // Kunci baris pesanan di dalam transaksi: pemeriksaan status & "belum
        // punya resi" diulang pada data terkunci sehingga penugasan ganda tidak
        // mungkin terjadi (sebelumnya hanya ditolak unique index -> 500).
        $result = DB::transaction(function () use ($order, $courier) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'ready_to_ship') {
                return 'status';
            }

            if ($locked->shipment()->exists()) {
                return 'exists';
            }

            Shipment::create([
                'order_id' => $locked->id,
                'courier_id' => $courier->id,
                'tracking_number' => $this->generateTrackingNumber(),
                'courier_name' => $courier->name,
                'status' => 'waiting',
                'recipient_name' => $locked->address?->recipient_name ?? $locked->user->name,
            ]);

            return 'ok';
        });

        if ($result === 'status') {
            $current = Order::whereKey($order->id)->value('status') ?? $order->status;

            return back()->withErrors([
                'order_id' => 'Hanya pesanan berstatus READY_TO_SHIP yang dapat dikirim. Status saat ini: '.strtoupper($current).'.',
            ]);
        }

        if ($result === 'exists') {
            return back()->withErrors([
                'order_id' => 'Pesanan #'.$order->order_number.' sudah memiliki nomor resi.',
            ]);
        }

        ActivityLog::record(
            'shipment.assigned',
            $order,
            'Pesanan #'.$order->order_number.' ditugaskan ke kurir '.$courier->name
        );

        return back()->with(
            'success',
            'Shipment untuk pesanan #'.$order->order_number.' dibuat dan ditugaskan ke kurir '.$courier->name.'. Menunggu kurir mengambil barang.'
        );
    }

    /**
     * Selesaikan pesanan: shipment delivered + order delivered → completed.
     * Kedua baris dikunci & statusnya dicek ulang di dalam transaksi.
     */
    public function complete(Shipment $shipment): RedirectResponse
    {
        if ($shipment->status !== 'delivered') {
            return back()->withErrors([
                'shipment' => 'Hanya shipment berstatus DELIVERED yang dapat menyelesaikan pesanan. Status saat ini: '.strtoupper($shipment->status).'.',
            ]);
        }

        $order = $shipment->order;

        if ($order->status !== 'delivered') {
            return back()->withErrors([
                'shipment' => 'Pesanan #'.$order->order_number.' belum berstatus DELIVERED (status: '.strtoupper($order->status).').',
            ]);
        }

        $completed = DB::transaction(function () use ($shipment) {
            $lockedShipment = Shipment::whereKey($shipment->id)->lockForUpdate()->first();

            if ($lockedShipment === null || $lockedShipment->status !== 'delivered') {
                return false;
            }

            $lockedOrder = Order::whereKey($lockedShipment->order_id)->lockForUpdate()->first();

            if ($lockedOrder === null || $lockedOrder->status !== 'delivered') {
                return false;
            }

            $lockedOrder->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return true;
        });

        if (! $completed) {
            return back()->withErrors([
                'shipment' => 'Pesanan tidak dapat diselesaikan — status shipment/pesanan baru saja berubah. Muat ulang halaman.',
            ]);
        }

        ActivityLog::record(
            'order.completed',
            $order,
            'Pesanan #'.$order->order_number.' diselesaikan (completed) setelah delivered'
        );

        return back()->with('success', 'Pesanan #'.$order->order_number.' diselesaikan (completed).');
    }

    /**
     * Daftar user dengan role kurir/courier yang masih aktif.
     */
    private function activeCouriers()
    {
        $roleIds = Role::whereIn('name', ['courier', 'kurir'])->pluck('id');

        return User::whereIn('role_id', $roleIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Nomor resi unik: SHIP-KNG-{Ymd}-{6 karakter}.
     */
    private function generateTrackingNumber(): string
    {
        do {
            $code = 'SHIP-KNG-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (Shipment::where('tracking_number', $code)->exists());

        return $code;
    }
}
