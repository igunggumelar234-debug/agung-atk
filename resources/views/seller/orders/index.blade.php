@extends('layouts.dashboard')

@section('title', 'Pesanan Toko')
@section('page-title', 'Pesanan (Sudah Dibayar)')

@section('content')
{{-- Filter Status --}}
<div class="d-flex flex-wrap gap-2 mb-4">
    <a href="{{ route('seller.orders.index') }}"
       class="btn btn-sm {{ is_null($statusFilter) ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill fw-semibold">
        Semua <span class="badge bg-white text-primary ms-1">{{ $statusCounts->sum() }}</span>
    </a>
    @foreach($visibleStatuses as $status)
        <a href="{{ route('seller.orders.index', ['status' => $status]) }}"
           class="btn btn-sm {{ $statusFilter === $status ? 'btn-primary' : 'btn-outline-secondary' }} rounded-pill fw-semibold">
            {{ $statusLabels[$status] ?? strtoupper($status) }}
            <span class="badge bg-white text-primary ms-1">{{ $statusCounts[$status] ?? 0 }}</span>
        </a>
    @endforeach
</div>

@error('order')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror

{{-- Tabel Pesanan --}}
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-dark">
            <i class="bi bi-bag-check me-2 text-success"></i>Daftar Pesanan
            @if($statusFilter)
                <span class="badge bg-primary ms-2">Filter: {{ $statusLabels[$statusFilter] ?? $statusFilter }}</span>
            @endif
        </h6>
        <span class="badge bg-success-subtle text-success">Operasional Penjual</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th style="width: 55px;">#</th>
                    <th>No. Pesanan</th>
                    <th>Tanggal</th>
                    <th>Pelanggan</th>
                    <th>Item</th>
                    <th class="text-end">Total</th>
                    <th class="text-center">Pembayaran</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $index => $order)
                    <tr>
                        <td class="text-secondary">{{ $orders->firstItem() + $index }}</td>
                        <td class="fw-bold text-primary">{{ $order->order_number }}</td>
                        <td class="text-secondary">{{ $order->created_at->format('d M Y H:i') }}</td>
                        <td class="fw-semibold text-dark">{{ $order->user?->name ?? '-' }}</td>
                        <td>
                            {{ $order->orderDetails->sum('quantity') }} item
                            <div class="text-muted" style="font-size: 0.75rem;">
                                {{ \Illuminate\Support\Str::limit($order->orderDetails->pluck('product_name')->implode(', '), 45) }}
                            </div>
                        </td>
                        <td class="text-end fw-bold">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</td>
                        <td class="text-center">
                            @if($order->payment)
                                @include('partials.payment-status', ['status' => $order->payment->status])
                            @else
                                <span class="text-secondary small">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @include('seller.orders.partials.status-badge', ['status' => $order->status])
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('seller.orders.show', $order) }}" class="btn btn-sm btn-outline-primary" title="Detail">
                                <i class="bi bi-eye"></i>
                            </a>

                            @if($order->status === 'paid')
                                <form action="{{ route('seller.orders.processing', $order) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Proses pesanan {{ $order->order_number }} ke status processing?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-success"
                                            title="Proses Pesanan" aria-label="Proses pesanan">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                </form>
                            @elseif($order->status === 'processing')
                                <form action="{{ route('seller.orders.ready-to-ship', $order) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Tandai pesanan {{ $order->order_number }} siap dikirim?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-info text-white"
                                            title="Siap Kirim" aria-label="Tandai siap kirim">
                                        <i class="bi bi-truck"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            Tidak ada pesanan{{ $statusFilter ? ' dengan status ' . ($statusLabels[$statusFilter] ?? $statusFilter) : '' }} saat ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pagination --}}
@if($orders->hasPages())
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="text-secondary small">
            Menampilkan {{ $orders->firstItem() }}–{{ $orders->lastItem() }} dari {{ $orders->total() }} pesanan
        </div>
        <div>{{ $orders->links() }}</div>
    </div>
@endif
@endsection
