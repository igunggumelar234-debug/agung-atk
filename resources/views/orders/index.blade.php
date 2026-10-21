@extends('layouts.dashboard')

@section('title', 'Pesanan Saya')
@section('page-title', 'Pesanan Saya')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-muted small mb-0">
        <i class="bi bi-receipt me-1"></i>Riwayat seluruh pesanan Anda di Agung ATK.
    </p>
    <a href="{{ route('products.index') }}" class="btn btn-primary-atk btn-sm">
        <i class="bi bi-bag-plus me-1"></i> Belanja Lagi
    </a>
</div>

{{-- Filter status --}}
<div class="card card-custom mb-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('orders.index') }}"
               class="btn btn-sm {{ $status === null ? 'btn-primary-atk' : 'btn-outline-secondary' }}">
                Semua
                <span class="badge bg-white text-dark ms-1">{{ $counts->sum() }}</span>
            </a>
            @foreach (['pending' => 'Belum Dibayar', 'paid' => 'Sudah Dibayar', 'processing' => 'Diproses', 'ready_to_ship' => 'Siap Kirim', 'shipping' => 'Dikirim', 'delivered' => 'Terkirim', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'] as $value => $label)
                @if (($counts[$value] ?? 0) > 0 || $status === $value)
                    <a href="{{ route('orders.index', ['status' => $value]) }}"
                       class="btn btn-sm {{ $status === $value ? 'btn-primary-atk' : 'btn-outline-secondary' }}">
                        {{ $label }}
                        <span class="badge bg-white text-dark ms-1">{{ $counts[$value] ?? 0 }}</span>
                    </a>
                @endif
            @endforeach
        </div>
    </div>
</div>

{{-- Daftar pesanan --}}
@if ($orders->isEmpty())
    <div class="card card-custom">
        <div class="card-body">
            <div class="empty-state">
                <i class="bi bi-receipt-cutoff empty-state-icon"></i>
                <div class="empty-state-title">
                    {{ $status ? 'Belum ada pesanan dengan status ini' : 'Anda belum memiliki pesanan' }}
                </div>
                <p class="empty-state-text">
                    {{ $status ? 'Coba pilih filter status lainnya.' : 'Mulai belanja alat tulis favorit Anda dan pesanan akan tampil di sini.' }}
                </p>
                <a href="{{ route('products.index') }}" class="btn btn-primary-atk mt-3">
                    <i class="bi bi-bag me-1"></i> Mulai Belanja
                </a>
            </div>
        </div>
    </div>
@else
    <div class="row g-3">
        @foreach ($orders as $order)
            <div class="col-12">
                <div class="card card-custom">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <a href="{{ route('orders.show', $order) }}"
                                   class="fw-bold text-dark text-decoration-none fs-6">
                                    <i class="bi bi-receipt me-1 text-primary"></i>{{ $order->order_number }}
                                </a>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-calendar3 me-1"></i>{{ $order->created_at->translatedFormat('d M Y H:i') }}
                                    &middot; {{ $order->orderDetails->count() }} produk
                                </div>
                            </div>
                            @include('partials.order-status', ['status' => $order->status])
                        </div>

                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-md-7">
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach ($order->orderDetails->take(3) as $detail)
                                        <span class="badge bg-light text-dark border px-2 py-1 fw-normal">
                                            {{ $detail->product->name ?? 'Produk' }} &times; {{ $detail->quantity }}
                                        </span>
                                    @endforeach
                                    @if ($order->orderDetails->count() > 3)
                                        <span class="badge bg-light text-muted border px-2 py-1 fw-normal">
                                            +{{ $order->orderDetails->count() - 3 }} lainnya
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="col-12 col-md-5 text-md-end">
                                <div class="small text-muted">Total Belanja</div>
                                <div class="fw-bold text-primary fs-5">
                                    Rp {{ number_format($order->grand_total, 0, ',', '.') }}
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-primary-atk">
                                <i class="bi bi-eye me-1"></i> Lihat Detail
                            </a>
                            @if ($order->payment)
                                <a href="{{ route('payments.show', $order->payment) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-wallet2 me-1"></i> Status Pembayaran
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4">
        {{ $orders->links() }}
    </div>
@endif
@endsection
