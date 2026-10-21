@extends('layouts.dashboard')

@section('title', 'Detail Produk')
@section('page-title', 'Detail Produk: ' . $product->name)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Produk
    </a>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil-square me-1"></i> Edit Produk
        </a>
        <form action="{{ route('admin.products.destroy', $product) }}" method="POST" class="d-inline"
              onsubmit="return confirm('Hapus produk ini beserta seluruh gambarnya? Tindakan ini tidak dapat dibatalkan.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash me-1"></i> Hapus Produk
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    {{-- Informasi Produk --}}
    <div class="col-lg-7">
        <div class="card card-stat mb-4">
            <div class="card-header bg-white fw-semibold">Informasi Produk</div>
            <div class="card-body p-4">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-secondary" style="width: 35%;">Nama</td>
                            <td class="fw-semibold text-dark">{{ $product->name }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">SKU</td>
                            <td><code>{{ $product->sku }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Kategori</td>
                            <td>{{ $product->category?->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Merek</td>
                            <td>{{ $product->brand ?: '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Harga Jual</td>
                            <td class="fw-semibold text-dark">Rp {{ number_format((float) $product->price, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Harga Modal</td>
                            <td>{{ $product->cost_price ? 'Rp ' . number_format((float) $product->cost_price, 0, ',', '.') : '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Satuan</td>
                            <td>{{ $product->unit }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Berat</td>
                            <td>{{ $product->weight }} gram</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Status</td>
                            <td>
                                @if($product->is_active)
                                    <span class="badge bg-success-subtle text-success">Aktif</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">Nonaktif</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-secondary align-top">Deskripsi</td>
                            <td>{!! nl2br(e($product->description ?: '-')) !!}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Galeri Gambar --}}
        <div class="card card-stat">
            <div class="card-header bg-white fw-semibold">Galeri Gambar ({{ $product->productImages->count() }})</div>
            <div class="card-body p-4">
                @if($product->productImages->isEmpty())
                    <p class="text-secondary small mb-3">Belum ada gambar untuk produk ini.</p>
                @else
                    <div class="row g-3 mb-4">
                        @foreach($product->productImages as $image)
                            <div class="col-6 col-md-4 col-lg-3">
                                <div class="border rounded position-relative overflow-hidden">
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->image_path) }}"
                                         alt="Gambar produk {{ $product->name }}" class="w-100" style="height: 130px; object-fit: cover;">
                                    @if($image->is_primary)
                                        <span class="badge bg-primary position-absolute top-0 start-0 m-2">Utama</span>
                                    @endif
                                    <div class="d-flex gap-1 p-2 bg-light border-top">
                                        @unless($image->is_primary)
                                            <form action="{{ route('admin.products.images.primary', [$product, $image]) }}" method="POST" class="flex-grow-1">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-outline-primary w-100" title="Jadikan gambar utama" aria-label="Jadikan gambar utama">
                                                    <i class="bi bi-star"></i> Utamakan
                                                </button>
                                            </form>
                                        @endunless
                                        <form action="{{ route('admin.products.images.destroy', [$product, $image]) }}" method="POST"
                                              class="{{ $image->is_primary ? 'flex-grow-1' : '' }}"
                                              onsubmit="return confirm('Hapus gambar ini?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger {{ $image->is_primary ? 'w-100' : '' }}" title="Hapus gambar" aria-label="Hapus gambar produk">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <form action="{{ route('admin.products.images.store', $product) }}" method="POST" enctype="multipart/form-data" class="border-top pt-3">
                    @csrf
                    <label for="images" class="form-label fw-semibold">Upload Gambar Baru</label>
                    <input type="file" name="images[]" id="images" class="form-control @error('images') is-invalid @enderror" multiple accept="image/*">
                    <small class="text-secondary">Maks 8 gambar sekaligus, masing-masing maks 3MB (jpeg/png/jpg/webp).</small>
                    @error('images')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    @error('images.*')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <button type="submit" class="btn btn-primary btn-sm mt-2">
                        <i class="bi bi-upload me-1"></i> Unggah Gambar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        {{-- Stok --}}
        <div class="card card-stat mb-4">
            <div class="card-header bg-white fw-semibold">Kelola Stok</div>
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3 p-3 rounded-3 {{ $product->stock <= 0 ? 'bg-danger-subtle' : ($product->stock <= $product->min_stock ? 'bg-warning-subtle' : 'bg-success-subtle') }}">
                    <div>
                        <div class="small text-secondary">Stok Saat Ini</div>
                        <div class="fs-3 fw-bold {{ $product->stock <= 0 ? 'text-danger' : ($product->stock <= $product->min_stock ? 'text-warning' : 'text-success') }}">
                            {{ $product->stock }} <span class="fs-6 fw-normal">{{ $product->unit }}</span>
                        </div>
                    </div>
                    <div class="text-end small">
                        <div class="text-secondary">Minimum</div>
                        <div class="fw-semibold">{{ $product->min_stock }} {{ $product->unit }}</div>
                        <div class="mt-1">
                            @if($product->stock <= 0)
                                <span class="badge bg-danger">Stok Habis</span>
                            @elseif($product->stock <= $product->min_stock)
                                <span class="badge bg-warning text-dark">Stok Menipis</span>
                            @else
                                <span class="badge bg-success">Aman</span>
                            @endif
                        </div>
                    </div>
                </div>

                <form action="{{ route('admin.products.stock', $product) }}" method="POST">
                    @csrf
                    @method('PATCH')

                    <div class="mb-3">
                        <label for="stock" class="form-label fw-semibold">Ubah Jumlah Stok <span class="text-danger">*</span></label>
                        <input type="number" name="stock" id="stock" value="{{ old('stock', $product->stock) }}" min="0" step="1"
                               class="form-control @error('stock') is-invalid @enderror" required>
                        <small class="text-secondary">Masukkan jumlah total stok terbaru. Tidak boleh negatif.</small>
                        @error('stock')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="min_stock" class="form-label fw-semibold">Batas Minimum Stok <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="number" name="min_stock" id="min_stock" value="{{ old('min_stock', $product->min_stock) }}" min="0" step="1"
                               class="form-control @error('min_stock') is-invalid @enderror">
                        @error('min_stock')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-repeat me-1"></i> Perbarui Stok
                    </button>
                </form>
            </div>
        </div>

        {{-- Ringkasan Transaksi --}}
        <div class="card card-stat">
            <div class="card-header bg-white fw-semibold">Ringkasan Penjualan</div>
            <div class="card-body p-4">
                @php
                    $totalSold = (int) $product->orderDetails->sum('quantity');
                    $totalOrderLines = $product->orderDetails->count();
                @endphp
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-secondary">Total terjual</span>
                    <span class="fw-semibold text-dark">{{ $totalSold }} {{ $product->unit }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-secondary">Rincian pesanan</span>
                    <span class="fw-semibold text-dark">{{ $totalOrderLines }}</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-secondary">Jumlah gambar</span>
                    <span class="fw-semibold text-dark">{{ $product->productImages->count() }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
