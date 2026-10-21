@extends('layouts.dashboard')

@section('title', 'Detail Pembayaran')
@section('page-title', 'Detail Pembayaran: ' . $payment->payment_number)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <a href="{{ route('admin.payments.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Pembayaran
    </a>
    @include('partials.payment-status', ['status' => $payment->status])
</div>

@error('payment')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror
@error('rejection_reason')
    <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
@enderror

<div class="row g-4">
    <div class="col-lg-7">
        {{-- Info Pembayaran --}}
        <div class="card card-stat mb-4">
            <div class="card-header bg-white fw-semibold">Rincian Pembayaran</div>
            <div class="card-body p-4">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-secondary" style="width: 40%;">No. Pembayaran</td>
                            <td class="fw-semibold text-dark"><code>{{ $payment->payment_number }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Metode</td>
                            <td class="fw-semibold text-dark">
                                @if($payment->method === 'transfer')
                                    <i class="bi bi-bank me-1"></i> Transfer Bank
                                @elseif($payment->method === 'qris')
                                    <i class="bi bi-qr-code me-1"></i> QRIS
                                @else
                                    <i class="bi bi-cash-coin me-1"></i> COD (Bayar di Tempat)
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Jumlah</td>
                            <td class="fw-bold fs-5 text-dark">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Status</td>
                            <td class="fw-semibold text-dark">{{ strtoupper($payment->status) }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Diproses Oleh</td>
                            <td class="text-dark">{{ $payment->verifier?->name ?? '-' }} {{ $payment->verified_at ? 'pada ' . $payment->verified_at->format('d M Y H:i') : '' }}</td>
                        </tr>
                        @if($payment->status === 'rejected' && $payment->rejection_reason)
                            <tr>
                                <td class="text-secondary align-top">Alasan Penolakan</td>
                                <td class="text-danger">{{ $payment->rejection_reason }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Info Pesanan --}}
        <div class="card card-stat">
            <div class="card-header bg-white fw-semibold">Pesanan Terkait</div>
            <div class="card-body p-4">
                <table class="table table-borderless small mb-0">
                    <tbody>
                        <tr>
                            <td class="text-secondary" style="width: 40%;">No. Pesanan</td>
                            <td class="fw-semibold text-dark"><code>{{ $payment->order?->order_number ?? '-' }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Status Pesanan</td>
                            <td>
                                <span class="badge bg-light text-dark border">{{ strtoupper($payment->order?->status ?? '-') }}</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Pelanggan</td>
                            <td class="text-dark">{{ $payment->order?->user?->name ?? '-' }} ({{ $payment->order?->user?->email }})</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Alamat</td>
                            <td class="text-dark">{{ $payment->order?->address?->full_address ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Item</td>
                            <td class="text-dark">
                                @foreach($payment->order?->orderDetails ?? [] as $detail)
                                    <div>{{ $detail->quantity }} × {{ $detail->product_name }} — Rp {{ number_format((float) $detail->subtotal, 0, ',', '.') }}</div>
                                @endforeach
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        {{-- Bukti Pembayaran --}}
        <div class="card card-stat mb-4">
            <div class="card-header bg-white fw-semibold">Bukti Pembayaran</div>
            <div class="card-body p-4 text-center">
                @if($payment->proof_image)
                    <a href="{{ route('payments.proof', $payment) }}" target="_blank" rel="noopener">
                        <img src="{{ route('payments.proof', $payment) }}" alt="Bukti pembayaran {{ $payment->payment_number }}"
                             class="img-fluid rounded border" style="max-height: 340px;">
                    </a>
                    <div class="small text-muted mt-2">
                        <i class="bi bi-lock-shield me-1"></i> File tersimpan privat — hanya dapat diakses lewat halaman ini.
                    </div>
                @else
                    <div class="text-secondary py-4">
                        <i class="bi bi-image fs-1 d-block mb-2 opacity-50"></i>
                        @if($payment->method === 'cod')
                            Metode COD tidak memerlukan bukti unggahan.
                        @else
                            Belum ada bukti pembayaran yang diunggah pelanggan.
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Aksi Verifikasi / Penolakan --}}
        @if($payment->status === 'pending')
            <div class="card card-stat mb-4">
                <div class="card-header bg-white fw-semibold">Verifikasi Pembayaran</div>
                <div class="card-body p-4">
                    <form action="{{ route('admin.payments.verify', $payment) }}" method="POST"
                          onsubmit="return confirm('Verifikasi pembayaran {{ $payment->payment_number }}? Pesanan akan menjadi paid.');">
                        @csrf
                        @method('PATCH')
                        <p class="small text-secondary mb-3">
                            Dengan memverifikasi, status pembayaran menjadi <strong>verified</strong> dan pesanan
                            berubah dari <strong>pending</strong> menjadi <strong>paid</strong>.
                        </p>
                        <button type="submit" class="btn btn-success w-100 fw-semibold">
                            <i class="bi bi-check-circle me-1"></i> Verifikasi (Setujui)
                        </button>
                    </form>
                </div>
            </div>

            <div class="card card-stat">
                <div class="card-header bg-white fw-semibold">Tolak Pembayaran</div>
                <div class="card-body p-4">
                    <form action="{{ route('admin.payments.reject', $payment) }}" method="POST"
                          onsubmit="return confirm('Tolak pembayaran {{ $payment->payment_number }}?');">
                        @csrf
                        @method('PATCH')
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label fw-semibold">Alasan Penolakan <span class="text-danger">*</span></label>
                            <textarea name="rejection_reason" id="rejection_reason" rows="3"
                                      class="form-control @error('rejection_reason') is-invalid @enderror"
                                      placeholder="Contoh: Bukti transfer tidak jelas / nominal tidak sesuai..." required></textarea>
                            @error('rejection_reason')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-outline-danger w-100 fw-semibold">
                            <i class="bi bi-x-circle me-1"></i> Tolak Pembayaran
                        </button>
                    </form>
                </div>
            </div>
        @else
            <div class="alert alert-secondary small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Pembayaran ini sudah diproses (status: <strong>{{ strtoupper($payment->status) }}</strong>) dan tidak dapat diubah lagi.
            </div>
        @endif
    </div>
</div>
@endsection
