@extends('layouts.dashboard')

@section('title', 'Edit Produk')
@section('page-title', 'Edit Produk: ' . $product->name)

@section('content')
<form action="{{ route('admin.products.update', $product) }}" method="POST" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card card-stat mb-4">
                <div class="card-header bg-white fw-semibold">Informasi Produk</div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Nama Produk <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" value="{{ old('name', $product->name) }}" class="form-control @error('name') is-invalid @enderror" required autofocus>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="sku" class="form-label fw-semibold">SKU <span class="text-danger">*</span></label>
                            <input type="text" name="sku" id="sku" value="{{ old('sku', $product->sku) }}" class="form-control @error('sku') is-invalid @enderror" required>
                            @error('sku')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text mb-2">Kode alternatif saat scan kamera bila barcode kosong.</div>

                            <label for="barcode" class="form-label fw-semibold">Barcode</label>
                            <input type="text" name="barcode" id="barcode" value="{{ old('barcode', $product->barcode) }}" class="form-control @error('barcode') is-invalid @enderror"
                                   placeholder="Contoh: 8991234567890 (opsional)" maxlength="64">
                            @error('barcode')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="category_id" class="form-label fw-semibold">Kategori <span class="text-danger">*</span></label>
                            <select name="category_id" id="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
                                <option value="">-- Pilih Kategori --</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" {{ (string) old('category_id', $product->category_id) === (string) $category->id ? 'selected' : '' }}>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('category_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="brand" class="form-label fw-semibold">Merek <span class="text-secondary fw-normal">(opsional)</span></label>
                            <input type="text" name="brand" id="brand" value="{{ old('brand', $product->brand) }}" class="form-control @error('brand') is-invalid @enderror">
                            @error('brand')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="unit" class="form-label fw-semibold">Satuan <span class="text-danger">*</span></label>
                            <input type="text" name="unit" id="unit" value="{{ old('unit', $product->unit) }}" class="form-control @error('unit') is-invalid @enderror" required>
                            @error('unit')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label fw-semibold">Deskripsi</label>
                        <textarea name="description" id="description" rows="4" class="form-control @error('description') is-invalid @enderror">{{ old('description', $product->description) }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="images" class="form-label fw-semibold">Tambah Gambar <span class="text-secondary fw-normal">(opsional, maks 8 gambar, masing-masing maks 3MB)</span></label>
                        <input type="file" name="images[]" id="images" class="form-control" multiple accept="image/*">
                        <small class="text-secondary">Gambar baru ditambahkan ke galeri. Kelola gambar utama/hapus melalui halaman detail produk.</small>
                        @error('images')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                        @error('images.*')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-stat mb-4">
                <div class="card-header bg-white fw-semibold">Harga & Stok</div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="price" class="form-label fw-semibold">Harga Jual (Rp) <span class="text-danger">*</span></label>
                        <input type="number" name="price" id="price" value="{{ old('price', $product->price) }}" min="0" step="1"
                               class="form-control @error('price') is-invalid @enderror" required>
                        @error('price')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="cost_price" class="form-label fw-semibold">Harga Modal (Rp) <span class="text-secondary fw-normal">(opsional)</span></label>
                        <input type="number" name="cost_price" id="cost_price" value="{{ old('cost_price', $product->cost_price) }}" min="0" step="1"
                               class="form-control @error('cost_price') is-invalid @enderror">
                        @error('cost_price')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="stock" class="form-label fw-semibold">Stok <span class="text-danger">*</span></label>
                        <input type="number" name="stock" id="stock" value="{{ old('stock', $product->stock) }}" min="0" step="1"
                               class="form-control @error('stock') is-invalid @enderror" required>
                        <small class="text-secondary">Stok tidak boleh negatif.</small>
                        @error('stock')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="min_stock" class="form-label fw-semibold">Batas Minimum Stok <span class="text-danger">*</span></label>
                        <input type="number" name="min_stock" id="min_stock" value="{{ old('min_stock', $product->min_stock) }}" min="0" step="1"
                               class="form-control @error('min_stock') is-invalid @enderror" required>
                        @error('min_stock')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="weight" class="form-label fw-semibold">Berat (gram) <span class="text-danger">*</span></label>
                        <input type="number" name="weight" id="weight" value="{{ old('weight', $product->weight) }}" min="0" step="1"
                               class="form-control @error('weight') is-invalid @enderror" required>
                        @error('weight')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input"
                               {{ old('is_active', $product->is_active ? '1' : '0') === '1' ? 'checked' : '' }}>
                        <label for="is_active" class="form-check-label">Status aktif (dijual)</label>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Perbarui Produk
                        </button>
                        <a href="{{ route('admin.products.show', $product) }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
