@extends('layouts.app')

@section('title', 'Katalog Produk')

@section('content')
{{-- Header katalog --}}
<div class="p-4 p-md-5 rounded-4 mb-4 text-white" style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="h3 fw-bold mb-1">Katalog Produk ATK</h1>
            <p class="mb-0 opacity-75 small">Alat Tulis Kerja lengkap untuk kebutuhan sekolah & kantor di Kuningan.</p>
        </div>
        <span class="badge bg-white text-primary px-3 py-2 rounded-pill">
            <i class="bi bi-box-seam me-1"></i> {{ $products->total() }} Produk Tersedia
        </span>
    </div>
</div>

{{-- Pencarian & Filter --}}
<form method="GET" action="{{ route('products.index') }}" class="card card-custom mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label for="q" class="form-label small fw-semibold text-dark">Cari Produk</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control"
                           placeholder="Nama, SKU, merek, atau deskripsi...">
                    <button type="button" class="btn btn-outline-secondary" id="catalogVisualBtn"
                            title="Cari produk dengan foto (Visual Search ATK)"
                            aria-label="Cari produk dengan foto barang ATK">
                        <i class="bi bi-camera" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-4">
                <label for="category_id" class="form-label small fw-semibold text-dark">Kategori</label>
                <select name="category_id" id="category_id" class="form-select">
                    <option value="">Semua Kategori</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" {{ (string) request('category_id') === (string) $category->id ? 'selected' : '' }}>
                            {{ $category->name }} ({{ $category->products_count }})
                        </option>
                    @endforeach
                </select>
            </div>
            {{-- col-md-2 membuat 2 tombol meluber di tablet (768-991px); tumpuk penuh di bawah lg, 2 kolom di lg+ --}}
            <div class="col-12 col-lg-2 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary-atk flex-fill">
                    <i class="bi bi-funnel me-1"></i> Terapkan
                </button>
                <a href="{{ route('products.index') }}" class="btn btn-outline-secondary" title="Reset filter">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
    // Tombol kamera pada pencarian = Visual Search ATK: foto barang ATK
    // dianalisis lalu dicarikan produk paling mirip di katalog (bukan scan barcode).
    document.addEventListener('DOMContentLoaded', function () {
        const visualBtn = document.getElementById('catalogVisualBtn');

        if (visualBtn && window.AtkVisualSearch) {
            visualBtn.addEventListener('click', function () {
                AtkVisualSearch.open('catalog');
            });
        }
    });
</script>
@endpush

{{-- Grid Produk --}}
@if($products->isEmpty())
    {{-- Empty state --}}
    <div class="text-center py-5">
        <div class="display-1 text-secondary opacity-25 mb-3">
            <i class="bi bi-search"></i>
        </div>
        <h2 class="fw-bold text-dark fs-4">Produk Tidak Ditemukan</h2>
        <p class="text-muted mb-3">Tidak ada produk yang cocok dengan pencarian atau filter Anda. Coba kata kunci lain atau lihat semua katalog.</p>
        <a href="{{ route('products.index') }}" class="btn btn-primary-atk">
            <i class="bi bi-collection me-1"></i> Lihat Semua Produk
        </a>
    </div>
@else
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3">
        @foreach($products as $product)
            <div class="col">
                <div class="card card-custom h-100 border-0">
                    <a href="{{ route('products.show', $product) }}" class="text-decoration-none">
                        <div class="bg-light rounded-top-4 d-flex align-items-center justify-content-center overflow-hidden" style="height: 170px;">
                            @if($product->primaryImage)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->primaryImage->image_path) }}"
                                     alt="{{ $product->name }}" class="w-100 h-100" style="object-fit: cover;">
                            @else
                                <i class="bi bi-card-image display-4 text-secondary opacity-25"></i>
                            @endif
                        </div>
                    </a>
                    <div class="card-body d-flex flex-column p-3">
                        <span class="badge bg-primary-subtle text-primary align-self-start mb-2 text-truncate" style="max-width: 100%;">
                            {{ $product->category?->name ?? 'Tanpa Kategori' }}
                        </span>
                        <a href="{{ route('products.show', $product) }}" class="text-decoration-none">
                            <h2 class="fw-bold text-dark mb-1 product-title fs-6">{{ $product->name }}</h2>
                        </a>
                        @if($product->brand)
                            <small class="text-muted mb-2">{{ $product->brand }}</small>
                        @endif

                        <div class="fw-bold text-primary-atk fs-5 mb-2">
                            Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                        </div>

                        <div class="mb-3 mt-auto">
                            @if($product->stock <= 0)
                                <span class="badge bg-danger">Stok Habis</span>
                            @elseif($product->stock <= $product->min_stock)
                                <span class="badge bg-warning text-dark">Sisa {{ $product->stock }} {{ $product->unit }}</span>
                            @else
                                <span class="badge bg-success-subtle text-success">Stok {{ $product->stock }} {{ $product->unit }}</span>
                            @endif
                        </div>

                        @auth
                            @if(auth()->user()->isCustomer())
                                <form action="{{ route('cart.store') }}" method="POST" class="mt-auto">
                                    @csrf
                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-accent-atk w-100 btn-sm fw-semibold"
                                            {{ $product->stock <= 0 ? 'disabled' : '' }}>
                                        <i class="bi bi-cart-plus me-1"></i>
                                        {{ $product->stock <= 0 ? 'Stok Habis' : 'Tambah ke Keranjang' }}
                                    </button>
                                </form>
                            @endif
                        @else
                            <a href="{{ route('login') }}" class="btn btn-outline-primary w-100 btn-sm">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Masuk untuk Membeli
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Pagination --}}
    @if($products->hasPages())
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 gap-2">
            <div class="text-secondary small">
                Menampilkan {{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ $products->total() }} produk
            </div>
            <div>{{ $products->links() }}</div>
        </div>
    @endif
@endif
@endsection

@push('styles')
<style>
    .product-title {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-height: 2.6em;
    }
</style>
@endpush
