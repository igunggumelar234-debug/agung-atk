@extends('layouts.dashboard')

@section('title', 'Riwayat Aktivitas')
@section('page-title', 'Riwayat Aktivitas: '.$user->name)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="small text-muted">
        <i class="bi bi-person-badge me-1"></i>
        {{ $user->email }}
        @if ($user->employee_code)
            · No. Pegawai <span class="badge bg-light text-dark border">{{ $user->employee_code }}</span>
        @endif
        · Role <span class="badge role-badge-{{ $user->role?->name }}">{{ $user->role?->label ?? '-' }}</span>
    </div>
    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Data Pengguna
    </a>
</div>

<div class="card card-custom">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-clock-history text-primary me-1"></i> Aktivitas Tercatat</span>
        <form method="GET" action="{{ route('admin.users.activities', $user) }}" class="d-flex gap-2">
            <select name="type" class="form-select form-select-sm" style="width: auto;" aria-label="Filter jenis aktivitas">
                <option value="">Semua Jenis</option>
                @foreach ($activityTypes as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ $type }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Jenis Aktivitas</th>
                    <th>Objek</th>
                    <th>Ringkasan</th>
                    <th class="text-center">Role</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-nowrap">{{ $log->created_at->translatedFormat('d M Y H:i') }}</td>
                        <td><span class="badge bg-light text-dark border">{{ $log->activity_type }}</span></td>
                        <td class="small text-muted">{{ class_basename($log->subject_type ?? '-') }}#{{ $log->subject_id ?? '-' }}</td>
                        <td>{{ $log->summary }}</td>
                        <td class="text-center">
                            <span class="badge role-badge-{{ $log->role }}">{{ $log->role ?? '-' }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="empty-state py-4">
                                <i class="bi bi-clock-history empty-state-icon"></i>
                                <div class="empty-state-title">Belum ada aktivitas</div>
                                <p class="empty-state-text">Aktivitas pegawai ini akan tercatat otomatis saat ia melakukan aksi di sistem.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    {{ $logs->links() }}
</div>
@endsection
