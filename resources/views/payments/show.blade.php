@extends('layouts.app')

@section('title', 'Detail Pembayaran')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1"><i class="bi bi-wallet2 me-2 text-primary"></i>Detail Pembayaran</h1>
        <p class="text-muted small mb-0">Nomor pembayaran: <code>{{ $payment->payment_number }}</code></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('orders.show', $payment->order) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-receipt me-1"></i> Detail Pesanan
        </a>
        <a href="{{ route('cart.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-cart3 me-1"></i> Keranjang
        </a>
    </div>
</div>

{{-- Status --}}
<div class="mb-4">
    @include('partials.payment-status', ['status' => $payment->status])
</div>

@if($payment->status === 'verified')
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-1"></i>
        Pembayaran sudah diverifikasi oleh admin pada {{ $payment->verified_at?->format('d M Y H:i') }}. Pesanan sedang diproses.
    </div>
@endif

@if($payment->status === 'rejected')
    <div class="alert alert-danger">
        <i class="bi bi-x-octagon-fill me-1"></i>
        <strong>Pembayaran ditolak.</strong> {{ $payment->rejection_reason }}
    </div>
@endif

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card card-custom border-0 mb-4">
            <div class="card-header bg-white fw-bold text-dark d-flex justify-content-between align-items-center">
                <span>Rincian Pembayaran</span>
                @include('partials.payment-status', ['status' => $payment->status])
            </div>
            <div class="card-body p-4">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width: 40%;">Nomor Pembayaran</td>
                            <td class="fw-semibold text-dark"><code>{{ $payment->payment_number }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Nomor Pesanan</td>
                            <td class="fw-semibold text-dark"><code>{{ $payment->order->order_number }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Metode Pembayaran</td>
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
                            <td class="text-muted">Tanggal Pesanan</td>
                            <td class="fw-semibold text-dark">{{ $payment->order->created_at->format('d M Y H:i') }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Jumlah Tagihan</td>
                            <td class="fw-bold fs-5 text-primary-atk">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</td>
                        </tr>
                        @if($payment->verified_at)
                            <tr>
                                <td class="text-muted">Diproses Pada</td>
                                <td class="fw-semibold text-dark">{{ $payment->verified_at->format('d M Y H:i') }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Bukti Pembayaran --}}
        @if($payment->proof_image)
            <div class="card card-custom border-0">
                <div class="card-header bg-white fw-bold text-dark">Bukti Pembayaran</div>
                <div class="card-body p-4 text-center">
                    <a href="{{ route('payments.proof', $payment) }}" target="_blank" rel="noopener">
                        <img src="{{ route('payments.proof', $payment) }}" alt="Bukti pembayaran {{ $payment->payment_number }}"
                             class="img-fluid rounded border" style="max-height: 360px;">
                    </a>
                    <div class="small text-muted mt-2">
                        <i class="bi bi-lock-shield me-1"></i> File hanya dapat diakses oleh Anda dan admin.
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-5">
        {{-- Instruksi --}}
        <div class="card card-custom border-0 mb-4">
            <div class="card-header bg-white fw-bold text-dark">Instruksi Pembayaran</div>
            <div class="card-body p-4">
                @if($payment->method === 'transfer')
                    <p class="small text-muted mb-2">
                        1. Transfer tepat sejumlah <strong class="text-dark">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</strong> ke rekening resmi toko.
                    </p>
                    <p class="small text-muted mb-3">
                        2. Unggah bukti transfer (gambar) di bawah ini. Admin akan memverifikasi pembayaran Anda.
                    </p>
                @elseif($payment->method === 'qris')
                    <p class="small text-muted mb-2">
                        1. Bayar sejumlah <strong class="text-dark">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</strong> menggunakan QRIS toko.
                    </p>
                    <p class="small text-muted mb-3">
                        2. Unggah tangkapan layar bukti pembayaran QRIS di bawah ini. Admin akan memverifikasinya.
                    </p>
                @else
                    <p class="small text-muted mb-2">
                        1. Bayar tunai sejumlah <strong class="text-dark">Rp {{ number_format((float) $payment->amount, 0, ',', '.') }}</strong> kepada kurir saat pesanan diterima.
                    </p>
                    <p class="small text-muted mb-3">
                        2. Metode COD tidak memerlukan unggahan bukti pembayaran.
                    </p>
                @endif
            </div>
        </div>

        {{-- Upload Bukti --}}
        @if(in_array($payment->method, ['transfer', 'qris'], true))
            <div class="card card-custom border-0">
                <div class="card-header bg-white fw-bold text-dark">
                    <i class="bi bi-upload me-1 text-primary"></i> Unggah Bukti Pembayaran
                </div>
                <div class="card-body p-4">
                    @if($payment->status === 'pending')
                        <form action="{{ route('payments.proof.upload', $payment) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label for="proof" class="form-label">Foto / Tangkapan Layar Bukti <span class="text-danger">*</span></label>
                                <input type="file" name="proof" id="proof" class="form-control @error('proof') is-invalid @enderror"
                                       accept="image/jpeg,image/png,image/webp" required>
                                <div class="form-text">Format: jpeg, png, jpg, webp. Maksimal 2MB.</div>
                                @error('proof')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-accent-atk w-100 fw-semibold">
                                <i class="bi bi-cloud-arrow-up me-1"></i> Unggah Bukti
                            </button>
                        </form>
                    @else
                        <div class="alert alert-secondary small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Unggahan bukti hanya tersedia selama pembayaran berstatus <strong>pending</strong>.
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
