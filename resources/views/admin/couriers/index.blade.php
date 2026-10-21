@extends('layouts.dashboard')

@section('title', 'Kurir')
@section('page-title', 'Manajemen Kurir')

@section('content')
{{-- Filter & pencarian --}}
<div class="card card-custom mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.couriers.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label for="q" class="form-label">Cari</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="q" value="{{ $search }}" class="form-control"
                           placeholder="Nama atau email kurir">
                </div>
            </div>
            <div class="col-8 col-md-4">
                <label for="aktif" class="form-label">Status</label>
                <select name="aktif" id="aktif" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="1" @selected($active === '1')>Aktif</option>
                    <option value="0" @selected($active === '0')>Nonaktif</option>
                </select>
            </div>
            <div class="col-4 col-md-2 d-grid">
                <button type="submit" class="btn btn-primary-atk">
                    <i class="bi bi-funnel me-1"></i> Terapkan
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card card-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bicycle text-primary me-1"></i> Daftar Kurir</span>
        <span class="badge bg-light text-dark border">{{ $couriers->total() }} kurir</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>Telepon</th>
                    <th class="text-center">Tugas Kirim</th>
                    <th class="text-center">Sedang Berjalan</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($couriers as $courier)
                    <tr>
                        <td class="fw-semibold">{{ $courier->name }}</td>
                        <td class="small">{{ $courier->email }}</td>
                        <td class="small">{{ $courier->phone ?: '-' }}</td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border">{{ $courier->courier_shipments_count }} pengiriman</span>
                        </td>
                        <td class="text-center">
                            @if ($courier->pending_shipments_count > 0)
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                    {{ $courier->pending_shipments_count }} berjalan
                                </span>
                            @else
                                <span class="text-muted small">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($courier->is_active)
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Aktif</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Nonaktif</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($courier->id === auth()->id())
                                <span class="badge bg-light text-muted border">
                                    <i class="bi bi-person-check me-1"></i>Akun Anda
                                </span>
                            @else
                                <form action="{{ route('admin.couriers.toggle-status', $courier) }}" method="POST"
                                      onsubmit="return confirm({{ Illuminate\Support\Js::from($courier->is_active ? 'Nonaktifkan kurir ' . $courier->name . '? Kurir tidak dapat menerima tugas baru selama nonaktif.' : 'Aktifkan kembali kurir ' . $courier->name . '?') }});">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm {{ $courier->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                        <i class="bi {{ $courier->is_active ? 'bi-person-slash' : 'bi-person-check' }} me-1"></i>
                                        {{ $courier->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state py-3">
                                <i class="bi bi-bicycle empty-state-icon"></i>
                                <div class="empty-state-title">Tidak ada kurir ditemukan</div>
                                <p class="empty-state-text">
                                    {{ $search || $active !== null ? 'Coba ubah kata kunci atau filter status.' : 'Belum ada akun kurir terdaftar.' }}
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    {{ $couriers->links() }}
</div>
@endsection
