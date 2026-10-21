@extends('layouts.dashboard')

@section('title', 'Stok Barang')
@section('page-title', 'Ketersediaan Stok Barang')

@section('content')
{{-- Peringatan stok rendah --}}
@if ($lowStockCount > 0)
    <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-exclamation-triangle me-1"></i> {{ $lowStockCount }} produk berada di bawah batas stok minimum.</span>
        <a href="{{ route('anggota.stock.index', ['low' => 1]) }}" class="btn btn-sm btn-warning fw-semibold">Lihat Stok Rendah</a>
    </div>
@endif

{{-- Filter & pencarian --}}
<div class="card card-custom mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('anggota.stock.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label for="q" class="form-label">Cari Barang</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="q" value="{{ $search }}" class="form-control" placeholder="Nama atau SKU produk">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="form-check mt-4">
                    <input type="checkbox" name="low" value="1" id="low" class="form-check-input" @checked($lowOnly)>
                    <label class="form-check-label" for="low">Stok rendah saja</label>
                </div>
            </div>
            <div class="col-6 col-md-3 d-grid">
                <button type="submit" class="btn btn-primary-atk"><i class="bi bi-funnel me-1"></i> Terapkan</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam text-primary me-1"></i> Daftar Barang & Ketersediaan Stok</span>
        <span class="badge bg-light text-dark border">{{ $products->total() }} produk</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>SKU</th>
                    <th class="text-center">Stok</th>
                    <th class="text-center">Min. Stok</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Catat Perubahan Stok</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td class="fw-semibold">{{ $product->name }}</td>
                        <td class="small text-muted">{{ $product->sku }}</td>
                        <td class="text-center fw-bold {{ $product->stock <= $product->min_stock ? 'text-danger' : 'text-success' }}">
                            {{ $product->stock }}
                        </td>
                        <td class="text-center">{{ $product->min_stock }}</td>
                        <td class="text-center">
                            @if ($product->stock <= 0)
                                <span class="badge bg-danger">Habis</span>
                            @elseif ($product->stock <= $product->min_stock)
                                <span class="badge bg-warning text-dark">Stok Rendah</span>
                            @else
                                <span class="badge bg-success">Tersedia</span>
                            @endif
                        </td>
                        <td>
                            <form action="{{ route('anggota.stock.update', $product) }}" method="POST" class="d-flex gap-2 justify-content-center">
                                @csrf
                                @method('PATCH')
                                <input type="number" name="stock" value="{{ $product->stock }}" min="0" required
                                       class="form-control form-control-sm" style="width: 90px;" aria-label="Stok baru {{ $product->name }}">
                                <input type="text" name="reason" maxlength="255" required
                                       class="form-control form-control-sm" style="min-width: 170px;"
                                       placeholder="Alasan perubahan" aria-label="Alasan perubahan stok {{ $product->name }}">
                                <button type="submit" class="btn btn-sm btn-primary text-nowrap"
                                        onclick="return confirm({{ Illuminate\Support\Js::from('Simpan perubahan stok ' . $product->name . '?') }});">
                                    <i class="bi bi-check2"></i> Simpan
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="empty-state py-4">
                                <i class="bi bi-box-seam empty-state-icon"></i>
                                <div class="empty-state-title">Tidak ada produk ditemukan</div>
                                <p class="empty-state-text">Coba ubah kata kunci pencarian atau matikan filter stok rendah.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    {{ $products->links() }}
</div>
@endsection
