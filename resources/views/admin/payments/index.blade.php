@extends('layouts.dashboard')

@section('title', 'Manajemen Pembayaran')
@section('page-title', 'Manajemen Pembayaran')

@section('content')
{{-- Statistik --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Total Pembayaran</div>
            <div class="fw-bold fs-5 text-dark">{{ $stats['total'] }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Menunggu Verifikasi</div>
            <div class="fw-bold fs-5 text-warning">{{ $stats['pending'] }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Terverifikasi</div>
            <div class="fw-bold fs-5 text-success">{{ $stats['verified'] }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-stat">
            <div class="text-secondary small">Ditolak</div>
            <div class="fw-bold fs-5 text-danger">{{ $stats['rejected'] }}</div>
        </div>
    </div>
</div>

{{-- Pencarian & Filter --}}
<form method="GET" action="{{ route('admin.payments.index') }}" class="card card-stat mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="q" class="form-label small fw-semibold">Pencarian</label>
                <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control"
                       placeholder="No. pembayaran, no. pesanan, nama/email pelanggan...">
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label small fw-semibold">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="verified" {{ request('status') === 'verified' ? 'selected' : '' }}>Verified</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="bi bi-search me-1"></i> Terapkan
                </button>
                <a href="{{ route('admin.payments.index') }}" class="btn btn-outline-secondary btn-sm flex-fill">Reset</a>
            </div>
        </div>
    </div>
</form>

{{-- Tabel Pembayaran --}}
<div class="card card-stat">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 55px;">#</th>
                    <th>No. Pembayaran</th>
                    <th>Pesanan</th>
                    <th>Pelanggan</th>
                    <th>Metode</th>
                    <th class="text-end">Jumlah</th>
                    <th class="text-center" style="width: 115px;">Status</th>
                    <th class="text-center" style="width: 80px;">Bukti</th>
                    <th class="text-end" style="width: 90px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $index => $payment)
                    <tr>
                        <td class="text-secondary">{{ $payments->firstItem() + $index }}</td>
                        <td><code>{{ $payment->payment_number }}</code></td>
                        <td>
                            <code>{{ $payment->order?->order_number ?? '-' }}</code>
                            <div class="small text-secondary">{{ $payment->created_at->format('d M Y H:i') }}</div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark">{{ $payment->order?->user?->name ?? '-' }}</div>
                            <small class="text-secondary">{{ $payment->order?->user?->email }}</small>
                        </td>
                        <td>
                            @if($payment->method === 'transfer')
                                <span class="badge bg-info-subtle text-info-emphasis">Transfer</span>
                            @elseif($payment->method === 'qris')
                                <span class="badge bg-primary-subtle text-primary">QRIS</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">COD</span>
                            @endif
                        </td>
                        <td class="text-end">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</td>
                        <td class="text-center">
                            @include('partials.payment-status', ['status' => $payment->status])
                        </td>
                        <td class="text-center">
                            @if($payment->proof_image)
                                <a href="{{ route('payments.proof', $payment) }}" target="_blank" rel="noopener"
                                   class="btn btn-sm btn-outline-secondary" title="Lihat bukti">
                                    <i class="bi bi-image"></i>
                                </a>
                            @else
                                <span class="text-secondary small">-</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-sm btn-outline-primary" title="Detail & verifikasi">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-secondary py-4">
                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                            Belum ada pembayaran atau tidak ada yang cocok dengan filter Anda.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Pagination --}}
@if($payments->hasPages())
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="text-secondary small">
            Menampilkan {{ $payments->firstItem() }}–{{ $payments->lastItem() }} dari {{ $payments->total() }} pembayaran
        </div>
        <div>{{ $payments->links() }}</div>
    </div>
@endif
@endsection
