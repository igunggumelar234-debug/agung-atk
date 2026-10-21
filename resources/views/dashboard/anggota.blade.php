@extends('layouts.dashboard')

@section('title', 'Dashboard Anggota Toko')
@section('page-title', 'Dashboard Anggota / Pegawai Toko')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Pesanan Masuk (Perlu Diproses)</span>
            <h3 class="fw-bold text-primary mt-2 mb-1">{{ $stats['orders_to_process'] }}</h3>
            <div class="small text-muted">Status: Paid / Processing</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Siap Diambil Kurir</span>
            <h3 class="fw-bold text-success mt-2 mb-1">{{ $stats['ready_to_ship'] }}</h3>
            <div class="small text-muted">Status: Ready to Ship</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Total Item Produk</span>
            <h3 class="fw-bold text-dark mt-2 mb-1">{{ $stats['total_products'] }}</h3>
            <div class="small text-muted">Katalog toko aktif</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card-stat">
            <span class="text-secondary small fw-semibold">Alert Stok Rendah</span>
            <h3 class="fw-bold text-danger mt-2 mb-1">{{ $stats['low_stock_count'] }}</h3>
            <div class="small text-muted">Perlu restock segera</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h4 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-box-seam me-2 text-success"></i>Daftar Pesanan yang Memerlukan Penyiapan Barang</h4>
        <a href="{{ route('anggota.orders.index') }}" class="btn btn-sm btn-outline-success fw-semibold">
            <i class="bi bi-arrow-right-circle me-1"></i> Kelola Semua Pesanan
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>No. Pesanan</th>
                    <th>Pelanggan</th>
                    <th>Item Produk</th>
                    <th>Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ordersToProcess as $order)
                    <tr>
                        <td class="fw-bold text-primary">{{ $order->order_number }}</td>
                        <td>
                            <div class="fw-semibold text-dark">{{ $order->user->name }}</div>
                            <div class="text-muted small">{{ $order->user->phone }}</div>
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
                        <td colspan="5" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            Tidak ada antrean pesanan baru yang perlu disiapkan saat ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Ketersediaan stok & peringatan stok rendah --}}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Peringatan Stok Rendah</h5>
                <a href="{{ route('anggota.stock.index') }}" class="btn btn-sm btn-outline-primary fw-semibold">
                    <i class="bi bi-clipboard-check me-1"></i> Catat Stok
                </a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush small">
                    @forelse($lowStockItems as $item)
                        <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                            <div>
                                <div class="fw-semibold text-dark">{{ $item->name }}</div>
                                <div class="text-muted small">SKU: {{ $item->sku }} · Min: {{ $item->min_stock }}</div>
                            </div>
                            <span class="badge bg-danger rounded-pill">Sisa: {{ $item->stock }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-center py-4 text-muted">
                            <i class="bi bi-check-circle text-success fs-4 d-block mb-1"></i>
                            Seluruh stok produk dalam kondisi aman.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    {{-- Riwayat pekerjaan & aktivitas sendiri --}}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold text-dark fs-6"><i class="bi bi-clock-history me-2 text-secondary"></i>Riwayat Aktivitas Saya</h5>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush small">
                    @forelse($recentActivities as $log)
                        <li class="list-group-item d-flex justify-content-between align-items-center py-3 gap-3">
                            <div>
                                <div class="fw-semibold text-dark">{{ $log->activity_type }}</div>
                                <div class="text-muted">{{ $log->summary }}</div>
                            </div>
                            <span class="text-muted text-nowrap">{{ $log->created_at->diffForHumans() }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-center py-4 text-muted">
                            <i class="bi bi-clock-history fs-4 d-block mb-1"></i>
                            Belum ada aktivitas tercatat untuk akun Anda.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
