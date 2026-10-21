@extends('layouts.dashboard')

@section('title', 'Pengguna')
@section('page-title', 'Manajemen Pengguna')

@section('content')
{{-- Filter & pencarian --}}
<div class="card card-custom mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.users.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label for="q" class="form-label">Cari</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="q" value="{{ $search }}" class="form-control"
                           placeholder="Nama atau email pengguna">
                </div>
            </div>
            <div class="col-8 col-md-4">
                <label for="role" class="form-label">Role</label>
                <select name="role" id="role" class="form-select">
                    <option value="">Semua Role</option>
                    @foreach (['admin' => 'Administrator', 'anggota' => 'Anggota / Pegawai', 'kurir' => 'Kurir', 'pelanggan' => 'Pelanggan'] as $value => $label)
                        <option value="{{ $value }}" @selected($roleName === $value)>
                            {{ $label }} ({{ $roleCounts[$value] ?? 0 }})
                        </option>
                    @endforeach
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

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        Akun Anggota & Kurir dibuat oleh Admin di sini — role ditentukan sistem, bukan oleh pengguna.
    </div>
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary-atk fw-semibold">
        <i class="bi bi-person-plus me-1"></i> Tambah Pengguna / Pegawai
    </a>
</div>

<div class="card card-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people text-primary me-1"></i> Daftar Pengguna</span>
        <span class="badge bg-light text-dark border">{{ $users->total() }} pengguna</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>No. Pegawai</th>
                    <th>Telepon</th>
                    <th class="text-center">Role</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Bergabung</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td class="fw-semibold">{{ $user->name }}</td>
                        <td class="small">{{ $user->email }}</td>
                        <td class="small">
                            @if ($user->employee_code)
                                <span class="badge bg-light text-dark border">{{ $user->employee_code }}</span>
                                <div class="text-muted mt-1">{{ $user->position ?: '-' }}</div>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="small">{{ $user->phone ?: '-' }}</td>
                        <td class="text-center">
                            <span class="badge role-badge-{{ $user->role?->name }} px-2 py-1">
                                {{ $user->role?->label ?? ucfirst($user->role?->name) }}
                            </span>
                        </td>
                        <td class="text-center">
                            @if ($user->is_active)
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Aktif</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Nonaktif</span>
                            @endif
                        </td>
                        <td class="text-center small text-muted">{{ $user->created_at->translatedFormat('d/m/Y') }}</td>
                        <td class="text-center">
                            @if ($user->id === auth()->id())
                                <span class="badge bg-light text-muted border" title="Anda sedang masuk dengan akun ini">
                                    <i class="bi bi-person-check me-1"></i>Akun Anda
                                </span>
                            @else
                                <div class="d-flex flex-wrap gap-1 justify-content-center">
                                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="Ubah data">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <a href="{{ route('admin.users.activities', $user) }}" class="btn btn-sm btn-outline-secondary" title="Riwayat aktivitas">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                    <form action="{{ route('admin.users.toggle-status', $user) }}" method="POST"
                                          onsubmit="return confirm({{ Illuminate\Support\Js::from($user->is_active ? 'Nonaktifkan akun ' . $user->name . '? Pengguna tidak dapat masuk selama nonaktif.' : 'Aktifkan kembali akun ' . $user->name . '?') }});">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-sm {{ $user->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                            <i class="bi {{ $user->is_active ? 'bi-person-slash' : 'bi-person-check' }} me-1"></i>
                                            {{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state py-3">
                                <i class="bi bi-people empty-state-icon"></i>
                                <div class="empty-state-title">Tidak ada pengguna ditemukan</div>
                                <p class="empty-state-text">
                                    {{ $search || $roleName ? 'Coba ubah kata kunci atau pilihan role.' : 'Belum ada pengguna terdaftar.' }}
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
    {{ $users->links() }}
</div>
@endsection
