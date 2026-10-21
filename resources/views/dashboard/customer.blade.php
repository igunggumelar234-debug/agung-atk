@extends('layouts.dashboard')

@section('title', 'Dashboard Pelanggan')
@section('page-title', 'Akun & Aktivitas Belanja Saya')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Total Pembelian</span>
            <h3 class="fw-bold text-dark mt-2 mb-1">{{ $stats['total_orders'] }} Pesanan</h3>
            <div class="small text-muted">Riwayat belanja di Agung ATK</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Pesanan Sedang Berjalan</span>
            <h3 class="fw-bold text-primary mt-2 mb-1">{{ $stats['active_orders'] }} Pesanan</h3>
            <div class="small text-muted">Sedang diproses / dikirim</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Ulasan Diberikan</span>
            <h3 class="fw-bold text-warning mt-2 mb-1">{{ $stats['total_reviews'] }} Ulasan</h3>
            <div class="small text-muted">Review produk ATK</div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Riwayat Pesanan --}}
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h4 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-bag-check me-2 text-primary"></i>Pesanan Terakhir Saya</h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('orders.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-ul me-1"></i>Semua Pesanan</a>
                    <a href="{{ route('products.index') }}" class="btn btn-sm btn-primary-atk"><i class="bi bi-cart-plus me-1"></i>Belanja Lagi</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>No. Order</th>
                            <th>Item Barang</th>
                            <th>Total Tagihan</th>
                            <th>Status Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders as $order)
                            <tr>
                                <td>
                                    <a href="{{ route('orders.show', $order) }}" class="fw-bold text-primary text-decoration-none">
                                        {{ $order->order_number }}
                                    </a>
                                </td>
                                <td>
                                    <ul class="list-unstyled mb-0 small">
                                        @foreach($order->orderDetails as $detail)
                                            <li>• {{ $detail->product_name }} (x{{ $detail->quantity }})</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="fw-bold">Rp {{ number_format($order->grand_total, 0, ',', '.') }}</td>
                                <td>
                                    @include('partials.order-status', ['status' => $order->status])
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state py-2">
                                        <i class="bi bi-cart-x empty-state-icon"></i>
                                        <div class="empty-state-title">Anda belum memiliki riwayat pesanan.</div>
                                        <p class="empty-state-text">Belanja alat tulis favoritmu dan pesanan akan tampil di sini.</p>
                                        <a href="{{ route('products.index') }}" class="btn btn-sm btn-primary-atk mt-3">
                                            <i class="bi bi-bag me-1"></i> Mulai Belanja
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Daftar Alamat Tersimpan --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h4 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-geo-alt me-2 text-warning"></i>Alamat Pengiriman (Kuningan)</h4>
                <a href="{{ route('addresses.index') }}" class="btn btn-sm btn-outline-secondary">Kelola</a>
            </div>
            <div class="card-body p-3">
                @forelse($addresses as $addr)
                    <div class="p-3 border rounded-3 bg-light mb-2 small">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong class="text-dark">{{ $addr->recipient_name }}</strong>
                            @if($addr->is_primary)
                                <span class="badge bg-primary">Utama</span>
                            @endif
                        </div>
                        <div class="text-muted mb-1">{{ $addr->phone }}</div>
                        <div class="text-dark">{{ $addr->full_address }}</div>
                        <div class="text-secondary small mt-1">Kec. {{ $addr->district }}, Pos {{ $addr->postal_code }}</div>
                    </div>
                @empty
                    <p class="text-muted small mb-0 text-center py-3">Belum ada alamat tersimpan.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
