@extends('layouts.dashboard')

@section('title', 'Dashboard Kurir')
@section('page-title', 'Dashboard Kurir Pengantaran Kuningan')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Tugas Aktif Diantar</span>
            <h3 class="fw-bold text-primary mt-2 mb-1">{{ $stats['active_tasks'] }}</h3>
            <div class="small text-muted">Waiting / Picked Up / Shipping</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Selesai Diantar Hari Ini</span>
            <h3 class="fw-bold text-success mt-2 mb-1">{{ $stats['completed_tasks'] }}</h3>
            <div class="small text-muted">Status: Delivered</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Pesanan Tersedia Diambil</span>
            <h3 class="fw-bold text-warning mt-2 mb-1">{{ $stats['available_orders'] }}</h3>
            <div class="small text-muted">Belum ada kurir yang mengambil</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h4 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-geo-alt-fill me-2 text-info"></i>Daftar Pengantaran Wilayah Kuningan</h4>
        <a href="{{ route('courier.shipments.index') }}" class="btn btn-sm btn-outline-info fw-semibold">
            <i class="bi bi-arrow-right-circle me-1"></i> Kelola Pengiriman
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>No. Resi & Order</th>
                    <th>Penerima & Kontak</th>
                    <th>Alamat Pengantaran (Kuningan)</th>
                    <th>Status Pengiriman</th>
                </tr>
            </thead>
            <tbody>
                @forelse($activeDeliveries as $shipment)
                    <tr>
                        <td>
                            <div class="fw-bold text-primary">{{ $shipment->tracking_number }}</div>
                            <div class="text-muted small">Order: {{ $shipment->order->order_number }}</div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark">{{ $shipment->order->address->recipient_name ?? $shipment->order->user->name }}</div>
                            <div class="text-muted small"><i class="bi bi-telephone me-1"></i>{{ $shipment->order->address->phone ?? $shipment->order->user->phone }}</div>
                        </td>
                        <td>
                            <div class="text-dark">{{ $shipment->order->address->full_address ?? '-' }}</div>
                            <span class="badge bg-light text-secondary border mt-1">Kec. {{ $shipment->order->address->district ?? 'Kuningan' }}</span>
                        </td>
                        <td>
                            @include('shipments.partials.status-badge', ['status' => $shipment->status])
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center py-4 text-muted">
                            <i class="bi bi-truck fs-3 d-block mb-2 text-secondary"></i>
                            Tidak ada tugas pengantaran aktif saat ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
