@extends('layouts.dashboard')

@section('title', $report['title'])
@section('page-title', 'Laporan & Analitik')

@section('content')
{{-- Tab jenis laporan --}}
<ul class="nav nav-pills flex-wrap gap-1 mb-3">
    @foreach($types as $key => $label)
        <li class="nav-item">
            <a class="nav-link {{ $report['type'] === $key ? 'active' : 'fw-semibold' }}"
               href="{{ route('admin.reports.index', array_merge($report['filters'], ['type' => $key])) }}">
                {{ $label }}
            </a>
        </li>
    @endforeach
</ul>

{{-- Filter & aksi export --}}
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="{{ route('admin.reports.index') }}" class="row g-2 align-items-end">
            <input type="hidden" name="type" value="{{ $report['type'] }}">

            <div class="col-6 col-md-2">
                <label for="start_date" class="form-label small fw-semibold text-dark mb-1">Tanggal Awal</label>
                <input type="date" class="form-control form-control-sm" id="start_date"
                       name="start_date" value="{{ $report['filters']['start_date'] }}">
            </div>

            <div class="col-6 col-md-2">
                <label for="end_date" class="form-label small fw-semibold text-dark mb-1">Tanggal Akhir</label>
                <input type="date" class="form-control form-control-sm" id="end_date"
                       name="end_date" value="{{ $report['filters']['end_date'] }}">
            </div>

            <div class="col-6 col-md-3">
                <label for="category_id" class="form-label small fw-semibold text-dark mb-1">Kategori</label>
                <select class="form-select form-select-sm" id="category_id" name="category_id">
                    <option value="">Semua kategori</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}"
                            {{ $report['filters']['category_id'] == $category->id ? 'selected' : '' }}>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-3">
                <label for="status" class="form-label small fw-semibold text-dark mb-1">Status Order</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    @foreach($statuses as $statusOption)
                        <option value="{{ $statusOption }}"
                            {{ $report['filters']['status'] === $statusOption ? 'selected' : '' }}>
                            {{ strtoupper($statusOption) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary-atk btn-sm fw-semibold w-100">
                    <i class="bi bi-funnel me-1"></i>Terapkan
                </button>
            </div>
        </form>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <a href="{{ route('admin.reports.export', array_merge($report['filters'], ['type' => $report['type']])) }}"
               class="btn btn-outline-success btn-sm fw-semibold">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <a href="{{ route('admin.reports.print', array_merge($report['filters'], ['type' => $report['type']])) }}"
               target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm fw-semibold">
                <i class="bi bi-printer me-1"></i>Cetak / Simpan PDF
            </a>
            <a href="{{ route('admin.reports.index', ['type' => $report['type']]) }}"
               class="btn btn-link btn-sm text-decoration-none">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
            </a>
        </div>
    </div>
</div>

{{-- Tabel laporan --}}
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0 fw-bold text-dark">
                <i class="bi bi-table me-2 text-primary"></i>{{ $report['title'] }}
            </h6>
            <span class="badge bg-primary-subtle text-primary border px-2 py-1">
                {{ $report['total_rows'] }} baris
            </span>
        </div>
        <div class="small text-muted mt-1">
            Periode: {{ $report['filter_labels']['period'] }}
            · Kategori: {{ $report['filter_labels']['category'] }}
            · Status: {{ $report['filter_labels']['status'] }}
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    @foreach($report['columns'] as $column)
                        <th class="text-nowrap">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($report['rows'] as $row)
                    <tr>
                        @foreach($report['columns'] as $index => $column)
                            <td>{{ $row[$index] ?? '-' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($report['columns']) }}" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-3 d-block mb-1 opacity-25"></i>
                            Tidak ada data untuk filter yang dipilih.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
