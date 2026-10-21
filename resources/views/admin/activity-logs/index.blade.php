@extends('layouts.dashboard')

@section('title', 'Audit Log')
@section('page-title', 'Audit Log Aktivitas')

@section('content')
<div class="alert alert-info small">
    <i class="bi bi-shield-check me-1"></i>
    Catatan siapa, peran, apa yang dilakukan, objek, dan kapan — tanpa kata sandi/token/data sensitif.
</div>

{{-- Filter --}}
<div class="card card-custom mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="q" class="form-label">Pengguna</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="q" value="{{ $search }}" class="form-control" placeholder="Nama atau email">
                </div>
            </div>
            <div class="col-12 col-md-3">
                <label for="type" class="form-label">Jenis Aktivitas</label>
                <select name="type" id="type" class="form-select">
                    <option value="">Semua Jenis</option>
                    @foreach ($activityTypes as $type)
                        <option value="{{ $type }}" @selected($activityType === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="from" class="form-label">Dari Tanggal</label>
                <input type="date" name="from" id="from" value="{{ $dateFrom }}" class="form-control">
            </div>
            <div class="col-6 col-md-2">
                <label for="to" class="form-label">Sampai</label>
                <input type="date" name="to" id="to" value="{{ $dateTo }}" class="form-control">
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button type="submit" class="btn btn-primary-atk" aria-label="Terapkan filter"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card card-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history text-primary me-1"></i> Riwayat Aktivitas</span>
        <span class="badge bg-light text-dark border">{{ $logs->total() }} catatan</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Pengguna</th>
                    <th class="text-center">Role</th>
                    <th>Jenis</th>
                    <th>Objek</th>
                    <th>Ringkasan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-nowrap">{{ $log->created_at->translatedFormat('d M Y H:i') }}</td>
                        <td class="fw-semibold">{{ $log->user?->name ?? 'Sistem' }}</td>
                        <td class="text-center">
                            <span class="badge role-badge-{{ $log->role }}">{{ $log->role ?? '-' }}</span>
                        </td>
                        <td><span class="badge bg-light text-dark border">{{ $log->activity_type }}</span></td>
                        <td class="small text-muted">
                            @if ($log->subject_type)
                                {{ class_basename($log->subject_type) }}#{{ $log->subject_id }}
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $log->summary }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="empty-state py-4">
                                <i class="bi bi-clock-history empty-state-icon"></i>
                                <div class="empty-state-title">Tidak ada aktivitas ditemukan</div>
                                <p class="empty-state-text">
                                    {{ $search || $activityType || $dateFrom || $dateTo ? 'Coba ubah kata kunci atau filter tanggal.' : 'Belum ada aktivitas tercatat.' }}
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
    {{ $logs->links() }}
</div>
@endsection
