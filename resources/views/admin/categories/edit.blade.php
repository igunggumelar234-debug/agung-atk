@extends('layouts.dashboard')

@section('title', 'Edit Kategori')
@section('page-title', 'Edit Kategori: ' . $category->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card card-stat">
            <div class="card-body p-4">
                <form action="{{ route('admin.categories.update', $category) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" value="{{ old('name', $category->name) }}" class="form-control @error('name') is-invalid @enderror" required autofocus>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label fw-semibold">Slug <span class="text-secondary fw-normal">(opsional, dibuat otomatis dari nama jika kosong)</span></label>
                        <input type="text" name="slug" id="slug" value="{{ old('slug', $category->slug) }}" class="form-control @error('slug') is-invalid @enderror">
                        @error('slug')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label fw-semibold">Deskripsi</label>
                        <textarea name="description" id="description" rows="3" class="form-control @error('description') is-invalid @enderror">{{ old('description', $category->description) }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Gambar Saat Ini</label>
                        <div class="d-flex align-items-center gap-3">
                            @if($category->image && \Illuminate\Support\Facades\Storage::disk('public')->exists($category->image))
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($category->image) }}"
                                     alt="{{ $category->name }}" class="rounded border" style="width: 72px; height: 72px; object-fit: cover;">
                            @else
                                <div class="rounded border d-flex align-items-center justify-content-center text-secondary" style="width: 72px; height: 72px;">
                                    <i class="bi bi-image"></i>
                                </div>
                            @endif
                            <div class="flex-grow-1">
                                <input type="file" name="image" accept="image/*" aria-label="Gambar kategori baru" class="form-control @error('image') is-invalid @enderror">
                                <small class="text-secondary">Kosongkan jika tidak ingin mengganti gambar. Maks 2MB.</small>
                                @error('image')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="mb-4 form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input"
                               {{ old('is_active', $category->is_active ? '1' : '0') === '1' ? 'checked' : '' }}>
                        <label for="is_active" class="form-check-label">Kategori aktif (ditampilkan ke pelanggan)</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i> Perbarui Kategori
                        </button>
                        <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
