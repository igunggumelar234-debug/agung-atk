@extends('layouts.dashboard')

@section('title', 'Pengiriman')
@section('page-title', 'Pengiriman & Penugasan Kurir')

@section('content')
{{-- Statistik --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Siap Dikirim</span>
            <h3 class="fw-bold text-warning mt-2 mb-1">{{ $stats['ready_orders'] }}</h3>
            <div class="small text-muted">ready_to_ship tanpa resi</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Menunggu Diambil</span>
            <h3 class="fw-bold text-danger mt-2 mb-1">{{ $stats['waiting'] }}</h3>
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

@error('order_id')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror
@error('shipment')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror

{{-- Panel 1: Pesanan Siap Dikirim --}}
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3">
        <h4 class="mb-0 fw-bold text-dark fs-6">
            <i class="bi bi-box-seam me-2 text-warning"></i>Pesanan Siap Dikirim
            <span class="badge bg-warning text-dark ms-1">{{ $stats['ready_orders'] }}</span>
        </h4>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>No. Pesanan</th>
                    <th>Pelanggan</th>
                    <th>Item</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($readyOrders as $order)
                    <tr>
                        <td class="fw-bold text-primary">{{ $order->order_number }}</td>
                        <td class="fw-semibold text-dark">{{ $order->user?->name ?? '-' }}</td>
                        <td>
                            {{ $order->orderDetails->sum('quantity') }} item
                            <div class="text-muted" style="font-size: 0.75rem;">
                                {{ \Illuminate\Support\Str::limit($order->orderDetails->pluck('product_name')->implode(', '), 40) }}
                            </div>
                        </td>
                        <td class="text-end fw-semibold">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-primary fw-semibold"
                                    data-bs-toggle="modal" data-bs-target="#assignModal"
                                    data-order-id="{{ $order->id }}"
                                    data-order-number="{{ $order->order_number }}">
                                <i class="bi bi-person-plus me-1"></i> Buat Shipment
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">
                            <i class="bi bi-check-circle fs-3 d-block mb-2"></i>
                            Tidak ada pesanan siap dikirim yang menunggu pembuatan resi.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Panel 2: Daftar Shipment --}}
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3">
        <h4 class="mb-0 fw-bold text-dark fs-6">
            <i class="bi bi-truck me-2 text-primary"></i>Daftar Shipment
            @if($statusFilter)
                <span class="badge bg-primary ms-2">Filter: {{ $statusLabels[$statusFilter] ?? $statusFilter }}</span>
            @endif
        </h4>
    </div>

    {{-- Filter status --}}
    <div class="bg-white px-3 pt-3 d-flex flex-wrap gap-2 border-bottom">
        <a href="{{ route('admin.shipments.index') }}"
           class="btn btn-sm {{ is_null($statusFilter) ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill fw-semibold">
            Semua <span class="badge bg-white text-primary ms-1">{{ $stats['total'] }}</span>
        </a>
        @foreach($statusLabels as $key => $label)
            <a href="{{ route('admin.shipments.index', ['status' => $key]) }}"
               class="btn btn-sm {{ $statusFilter === $key ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill fw-semibold">
                {{ $label }} <span class="badge bg-white text-primary ms-1">{{ $stats[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>No. Resi</th>
                    <th>Pesanan</th>
                    <th>Kurir</th>
                    <th>Dibuat</th>
                    <th class="text-center">Status Shipment</th>
                    <th class="text-center">Status Pesanan</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shipments as $shipment)
                    <tr>
                        <td class="fw-bold text-dark"><code>{{ $shipment->tracking_number }}</code></td>
                        <td>
                            <code>{{ $shipment->order?->order_number ?? '-' }}</code>
                            <div class="text-muted" style="font-size: 0.75rem;">{{ $shipment->order?->user?->name }}</div>
                        </td>
                        <td class="fw-semibold text-dark">{{ $shipment->courier?->name ?? $shipment->courier_name ?? '-' }}</td>
                        <td class="text-secondary">{{ $shipment->created_at->format('d M Y H:i') }}</td>
                        <td class="text-center">
                            @include('shipments.partials.status-badge', ['status' => $shipment->status])
                        </td>
                        <td class="text-center">
                            @include('partials.order-status', ['status' => $shipment->order?->status ?? 'pending'])
                        </td>
                        <td class="text-end">
                            @if($shipment->status === 'delivered' && $shipment->order?->status === 'delivered')
                                <form action="{{ route('admin.shipments.complete', $shipment) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Selesaikan pesanan {{ $shipment->order?->order_number }}?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-success fw-semibold">
                                        <i class="bi bi-check-circle me-1"></i> Selesaikan
                                    </button>
                                </form>
                            @else
                                <span class="text-secondary small">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            Belum ada shipment atau tidak ada yang cocok dengan filter.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pagination --}}
@if($shipments->hasPages())
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="text-secondary small">
            Menampilkan {{ $shipments->firstItem() }}–{{ $shipments->lastItem() }} dari {{ $shipments->total() }} shipment
        </div>
        <div>{{ $shipments->links() }}</div>
    </div>
@endif

{{-- Modal: Buat Shipment + Pilih Kurir --}}
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="{{ route('admin.shipments.store') }}" method="POST" id="assignForm">
                @csrf
                <input type="hidden" name="order_id" id="assignOrderId" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="assignModalLabel">
                        <i class="bi bi-truck me-2 text-primary"></i>Buat Shipment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Pesanan: <strong class="text-dark" id="assignOrderNumber">-</strong>
                    </p>

                    <label for="assignCourier" class="form-label fw-semibold small">
                        Pilih Kurir <span class="text-danger">*</span>
                    </label>
                    <select name="courier_id" id="assignCourier"
                            class="form-select @error('courier_id') is-invalid @enderror" required>
                        <option value="">— Pilih kurir —</option>
                        @foreach($couriers as $courier)
                            <option value="{{ $courier->id }}" {{ old('courier_id') == $courier->id ? 'selected' : '' }}>
                                {{ $courier->name }} ({{ $courier->email }})
                            </option>
                        @endforeach
                    </select>
                    @error('courier_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if($couriers->isEmpty())
                        <div class="form-text text-danger">Belum ada kurir aktif di sistem.</div>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="bi bi-check2-square me-1"></i> Buat & Tugaskan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('assignModal');
        if (modal) {
            modal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                if (!button) return;
                document.getElementById('assignOrderId').value = button.getAttribute('data-order-id');
                document.getElementById('assignOrderNumber').textContent = button.getAttribute('data-order-number');
                document.getElementById('assignCourier').value = '';
            });
        }
    });
</script>
@endpush
