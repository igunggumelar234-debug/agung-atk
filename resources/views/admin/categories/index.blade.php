@extends('layouts.dashboard')

@section('title', 'Kelola Kategori')
@section('page-title', 'Kelola Kategori ATK')

@section('content')
{{-- Statistik singkat --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card-stat d-flex align-items-center gap-3">
            <div class="feature-icon-wrapper bg-primary-subtle text-primary">
                <i class="bi bi-tags fs-4"></i>
            </div>
            <div>
                <div class="text-secondary small">Total Kategori</div>
                <div class="fw-bold fs-5 text-dark">{{ $stats['total'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-stat d-flex align-items-center gap-3">
            <div class="feature-icon-wrapper bg-success-subtle text-success">
                <i class="bi bi-check-circle fs-4"></i>
            </div>
            <div>
                <div class="text-secondary small">Aktif</div>
                <div class="fw-bold fs-5 text-dark">{{ $stats['active'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-stat d-flex align-items-center gap-3">
            <div class="feature-icon-wrapper bg-secondary-subtle text-secondary">
                <i class="bi bi-pause-circle fs-4"></i>
            </div>
            <div>
                <div class="text-secondary small">Nonaktif</div>
                <div class="fw-bold fs-5 text-dark">{{ $stats['inactive'] }}</div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('admin.categories.create') }}" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1">
        <i class="bi bi-plus-lg"></i> Tambah Kategori
    </a>
</div>

{{-- Pencarian & Filter --}}
<form method="GET" action="{{ route('admin.categories.index') }}" class="card card-stat mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="q" class="form-label small fw-semibold">Pencarian</label>
                <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control" placeholder="Cari nama / deskripsi kategori...">
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label small fw-semibold">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="bi bi-search me-1"></i> Terapkan
                </button>
                <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary btn-sm flex-fill">Reset</a>
            </div>
        </div>
    </div>
</form>

{{-- Tabel Kategori --}}
<div class="card card-stat">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 55px;">#</th>
                    <th>Nama Kategori</th>
                    <th>Slug</th>
                    <th class="text-center" style="width: 90px;">Produk</th>
                    <th class="text-center" style="width: 100px;">Status</th>
                    <th class="text-end" style="width: 160px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $index => $category)
                    <tr>
                        <td class="text-secondary">{{ $categories->firstItem() + $index }}</td>
                        <td>
                            <div class="fw-semibold text-dark">{{ $category->name }}</div>
                            <small class="text-secondary">{{ \Illuminate\Support\Str::limit($category->description, 60) }}</small>
                        </td>
                        <td><code>{{ $category->slug }}</code></td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border">{{ $category->products_count }}</span>
                        </td>
                        <td class="text-center">
                            @if($category->is_active)
                                <span class="badge bg-success-subtle text-success">Aktif</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Nonaktif</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <form action="{{ route('admin.categories.destroy', $category) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm({{ Illuminate\Support\Js::from('Hapus kategori "' . $category->name . '"? Kategori yang dipakai produk tidak dapat dihapus.') }});">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus" aria-label="Hapus kategori">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-secondary py-4">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            Belum ada kategori atau tidak ada yang cocok dengan pencarian Anda.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pagination --}}
@if($categories->hasPages())
    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-secondary small">
            Menampilkan {{ $categories->firstItem() }}–{{ $categories->lastItem() }} dari {{ $categories->total() }} kategori
        </div>
        <div>{{ $categories->links() }}</div>
    </div>
@endif
@endsection
