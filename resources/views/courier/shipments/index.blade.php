@extends('layouts.dashboard')

@section('title', 'Pengiriman Saya')
@section('page-title', 'Daftar Pengiriman Saya')

@section('content')
{{-- Statistik --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Total Tugas</span>
            <h3 class="fw-bold text-dark mt-2 mb-1">{{ $stats['total'] }}</h3>
            <div class="small text-muted">Ditugaskan ke saya</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Menunggu Diambil</span>
            <h3 class="fw-bold text-warning mt-2 mb-1">{{ $stats['waiting'] }}</h3>
            <div class="small text-muted">waiting</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Dalam Perjalanan</span>
            <h3 class="fw-bold text-primary mt-2 mb-1">{{ $stats['picked_up'] + $stats['shipping'] }}</h3>
            <div class="small text-muted">picked_up + shipping</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Terkirim</span>
            <h3 class="fw-bold text-success mt-2 mb-1">{{ $stats['delivered'] }}</h3>
            <div class="small text-muted">delivered</div>
        </div>
    </div>
</div>

@error('shipment')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror

{{-- Filter status --}}
<div class="d-flex flex-wrap gap-2 mb-4">
    <a href="{{ route('courier.shipments.index') }}"
       class="btn btn-sm {{ is_null($statusFilter) ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill fw-semibold">
        Semua <span class="badge bg-white text-primary ms-1">{{ $stats['total'] }}</span>
    </a>
    @foreach($statusLabels as $key => $label)
        <a href="{{ route('courier.shipments.index', ['status' => $key]) }}"
           class="btn btn-sm {{ $statusFilter === $key ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill fw-semibold">
            {{ $label }} <span class="badge bg-white text-primary ms-1">{{ $stats[$key] ?? 0 }}</span>
        </a>
    @endforeach
</div>

{{-- Kartu shipment (responsif untuk smartphone) --}}
<div class="row g-3">
    @forelse($shipments as $shipment)
        @php($address = $shipment->order?->address)
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="fw-bold text-dark text-break">
                            <code>{{ $shipment->tracking_number }}</code>
                        </div>
                        @include('shipments.partials.status-badge', ['status' => $shipment->status])
                    </div>

                    <div class="small text-muted mb-2">
                        <i class="bi bi-receipt me-1"></i>
                        Pesanan: <strong class="text-dark">{{ $shipment->order?->order_number ?? '-' }}</strong>
                    </div>

                    <div class="small mb-1 text-truncate">
                        <i class="bi bi-person me-1 text-secondary"></i>
                        {{ $address?->recipient_name ?? $shipment->recipient_name ?? $shipment->order?->user?->name ?? '-' }}
                    </div>

                    <div class="small mb-1">
                        <a href="tel:{{ $address?->phone }}" class="text-decoration-none">
                            <i class="bi bi-telephone me-1 text-secondary"></i>{{ $address?->phone ?? '-' }}
                        </a>
                    </div>

                    <div class="small text-muted mb-3" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                        <i class="bi bi-geo-alt me-1 text-secondary"></i>
                        {{ $address?->full_address ?? '-' }}, {{ $address?->city ?? '-' }}
                    </div>

                    <a href="{{ route('courier.shipments.show', $shipment) }}"
                       class="btn btn-primary btn-sm w-100 fw-semibold">
                        <i class="bi bi-eye me-1"></i> Lihat Detail & Proses
                    </a>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-truck fs-1 d-block mb-2"></i>
                    Tidak ada pengiriman{{ $statusFilter ? ' dengan status ' . ($statusLabels[$statusFilter] ?? $statusFilter) : '' }} saat ini.
                </div>
            </div>
        </div>
    @endforelse
</div>

{{-- Pagination --}}
@if($shipments->hasPages())
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="text-secondary small">
            Menampilkan {{ $shipments->firstItem() }}–{{ $shipments->lastItem() }} dari {{ $shipments->total() }} pengiriman
        </div>
        <div>{{ $shipments->links() }}</div>
    </div>
@endif
@endsection
