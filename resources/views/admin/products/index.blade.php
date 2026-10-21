@extends('layouts.dashboard')

@section('title', 'Kelola Produk & Stok')
@section('page-title', 'Kelola Produk & Stok ATK')

@section('content')
{{-- Statistik --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Total Produk</div>
            <div class="fw-bold fs-5 text-dark">{{ $stats['total'] }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Aktif Dijual</div>
            <div class="fw-bold fs-5 text-success">{{ $stats['active'] }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Stok Menipis</div>
            <div class="fw-bold fs-5 text-warning">{{ $stats['low_stock'] }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Stok Habis</div>
            <div class="fw-bold fs-5 text-danger">{{ $stats['out_of_stock'] }}</div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1">
        <i class="bi bi-plus-lg"></i> Tambah Produk
    </a>
</div>

{{-- Pencarian & Filter --}}
<form method="GET" action="{{ route('admin.products.index') }}" class="card card-stat mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="q" class="form-label small fw-semibold">Pencarian</label>
                <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control" placeholder="Nama, SKU, merek, atau deskripsi...">
            </div>
            <div class="col-md-3">
                <label for="category_id" class="form-label small fw-semibold">Kategori</label>
                <select name="category_id" id="category_id" class="form-select">
                    <option value="">Semua Kategori</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" {{ (string) request('category_id') === (string) $category->id ? 'selected' : '' }}>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small fw-semibold">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">Semua</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="stock_condition" class="form-label small fw-semibold">Kondisi Stok</label>
                <select name="stock_condition" id="stock_condition" class="form-select">
                    <option value="">Semua</option>
                    <option value="low" {{ request('stock_condition') === 'low' ? 'selected' : '' }}>Menipis</option>
                    <option value="out" {{ request('stock_condition') === 'out' ? 'selected' : '' }}>Habis</option>
                    <option value="safe" {{ request('stock_condition') === 'safe' ? 'selected' : '' }}>Aman</option>
                </select>
            </div>
            <div class="col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100" title="Terapkan filter" aria-label="Terapkan filter pencarian produk">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </div>
        @if(request()->hasAny(['q', 'category_id', 'status', 'stock_condition']))
            <div class="mt-2">
                <a href="{{ route('admin.products.index') }}" class="text-secondary small">
                    <i class="bi bi-x-circle me-1"></i> Reset filter
                </a>
            </div>
        @endif
    </div>
</form>

{{-- Tabel Produk --}}
<div class="card card-stat">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 55px;">#</th>
                    <th style="width: 60px;">Gambar</th>
                    <th>Produk</th>
                    <th>Kategori</th>
                    <th class="text-end">Harga</th>
                    <th class="text-center" style="width: 110px;">Stok</th>
                    <th class="text-center" style="width: 95px;">Status</th>
                    <th class="text-end" style="width: 210px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $index => $product)
                    <tr>
                        <td class="text-secondary">{{ $products->firstItem() + $index }}</td>
                        <td>
                            @if($product->primaryImage)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->primaryImage->image_path) }}"
                                     alt="{{ $product->name }}" class="rounded border" style="width: 42px; height: 42px; object-fit: cover;">
                            @else
                                <div class="rounded border d-flex align-items-center justify-content-center text-secondary" style="width: 42px; height: 42px;">
                                    <i class="bi bi-image"></i>
                                </div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.products.show', $product) }}" class="fw-semibold text-dark text-decoration-none">
                                {{ $product->name }}
                            </a>
                            <div class="small text-secondary">
                                <code>{{ $product->sku }}</code>
                                @if($product->brand) <span class="ms-1">· {{ $product->brand }}</span> @endif
                            </div>
                        </td>
                        <td><span class="badge bg-light text-dark border">{{ $product->category?->name ?? '-' }}</span></td>
                        <td class="text-end">Rp {{ number_format((float) $product->price, 0, ',', '.') }}</td>
                        <td class="text-center">
                            @if($product->stock <= 0)
                                <span class="badge bg-danger">Habis</span>
                            @elseif($product->stock <= $product->min_stock)
                                <span class="badge bg-warning text-dark" title="Sisa {{ $product->stock }} (batas minimum {{ $product->min_stock }})">{{ $product->stock }} ↓</span>
                            @else
                                <span class="badge bg-success-subtle text-success">{{ $product->stock }}</span>
                            @endif
                            <div class="small text-secondary">{{ $product->unit }}</div>
                        </td>
                        <td class="text-center">
                            @if($product->is_active)
                                <span class="badge bg-success-subtle text-success">Aktif</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Nonaktif</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.products.show', $product) }}" class="btn btn-sm btn-outline-secondary" title="Detail">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <form action="{{ route('admin.products.toggle-status', $product) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Ubah status aktif/nonaktif" aria-label="Ubah status aktif/nonaktif produk">
                                    <i class="bi bi-toggle2-{{ $product->is_active ? 'on' : 'off' }}"></i>
                                </button>
                            </form>
                            <form action="{{ route('admin.products.destroy', $product) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm({{ Illuminate\Support\Js::from('Hapus produk "' . $product->name . '" beserta seluruh gambarnya? Tindakan ini tidak dapat dibatalkan.') }});">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus produk">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-secondary py-4">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            Belum ada produk atau tidak ada yang cocok dengan pencarian/filter Anda.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pagination --}}
@if($products->hasPages())
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="text-secondary small">
            Menampilkan {{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ $products->total() }} produk
        </div>
        <div>{{ $products->links() }}</div>
    </div>
@endif
@endsection
