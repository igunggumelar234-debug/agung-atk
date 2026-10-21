@extends('layouts.app')

@section('title', 'Checkout')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1"><i class="bi bi-bag-check me-2 text-primary"></i>Checkout</h1>
        <p class="text-muted small mb-0">Periksa alamat, metode pembayaran, dan ringkasan pesanan Anda.</p>
    </div>
    <a href="{{ route('cart.index') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-cart3 me-1"></i> Kembali ke Keranjang
    </a>
</div>

<form action="{{ route('checkout.store') }}" method="POST">
    @csrf

    <div class="row g-4">
        <div class="col-lg-7">
            {{-- 1. Alamat Pengiriman --}}
            <div class="card card-custom border-0 mb-4">
                <div class="card-header bg-white fw-bold text-dark">
                    <span class="me-2">1.</span> Alamat Pengiriman
                </div>
                <div class="card-body p-4">
                    @error('address_id')
                        <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
                    @enderror
                    @error('cart')
                        <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
                    @enderror

                    @if($addresses->isEmpty())
                        <div class="alert alert-info small mb-3">
                            <i class="bi bi-info-circle me-1"></i> Anda belum memiliki alamat. Tambahkan alamat terlebih dahulu sebelum membuat pesanan.
                        </div>
                    @else
                        <div class="d-flex flex-column gap-2 mb-3">
                            @foreach($addresses as $address)
                                {{-- option-card.is-selected: di mode terang SAMA
                                     persis dgn gaya lama (border #1e3a8a + bg
                                     #eff6ff), di mode gelap ikut tema. --}}
                                <label class="option-card border rounded-3 p-3 d-flex gap-3 align-items-start {{ (int) $selectedAddressId === (int) $address->id ? 'is-selected' : '' }}">
                                    <input type="radio" name="address_id" value="{{ $address->id }}"
                                           class="form-check-input mt-1" {{ (int) $selectedAddressId === (int) $address->id ? 'checked' : '' }}>
                                    <div class="flex-grow-1">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="fw-semibold text-dark">{{ $address->recipient_name }}</span>
                                            <span class="badge bg-primary-subtle text-primary">{{ $address->label }}</span>
                                            @if($address->is_primary)
                                                <span class="badge bg-warning text-dark">Utama</span>
                                            @endif
                                        </div>
                                        <div class="text-muted small mt-1">
                                            {{ $address->full_address }}
                                            @if($address->district)<br>Kec. {{ $address->district }}@endif
                                            @if($address->city)<br>{{ $address->city }}@endif
                                            @if($address->postal_code) · POS {{ $address->postal_code }}@endif
                                        </div>
                                        <div class="text-muted small mt-1"><i class="bi bi-phone me-1"></i>{{ $address->phone }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <button class="btn btn-outline-primary btn-sm" type="button"
                            data-bs-toggle="modal" data-bs-target="#modalAlamat">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Alamat Baru
                    </button>
                </div>
            </div>

            {{-- Ringkasan item --}}
            <div class="card card-custom border-0">
                <div class="card-header bg-white fw-bold text-dark">Item dalam Pesanan</div>
                <div class="card-body p-4">
                    <div class="d-flex flex-column gap-3">
                        @foreach($items as $item)
                            <div class="d-flex justify-content-between gap-2">
                                <div class="d-flex gap-2">
                                    @if($item['product']->primaryImage)
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item['product']->primaryImage->image_path) }}"
                                             alt="{{ $item['product']->name }}" class="rounded border" width="48" height="48" style="object-fit: cover;">
                                    @endif
                                    <div>
                                        <div class="small fw-semibold text-dark">{{ $item['product']->name }}</div>
                                        <div class="small text-muted">{{ $item['quantity'] }} × Rp {{ number_format((float) $item['product']->price, 0, ',', '.') }}</div>
                                    </div>
                                </div>
                                <div class="small fw-semibold text-dark text-nowrap">Rp {{ number_format($item['subtotal'], 0, ',', '.') }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            {{-- 2. Metode Pembayaran --}}
            <div class="card card-custom border-0 mb-4">
                <div class="card-header bg-white fw-bold text-dark">
                    <span class="me-2">2.</span> Metode Pembayaran
                </div>
                <div class="card-body p-4">
                    @error('payment_method')
                        <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}</div>
                    @enderror

                    <div class="d-flex flex-column gap-2">
                        @php
                            $methodLabels = [
                                'transfer' => ['Transfer Bank', 'Transfer manual ke rekening toko. Verifikasi oleh admin setelah bukti dikirim.', 'bi-bank'],
                                'qris' => ['QRIS', 'Scan kode QRIS dari aplikasi pembayaran apa pun.', 'bi-qr-code'],
                                'cod' => ['COD (Bayar di Tempat)', 'Bayar tunai saat kurir tiba di alamat Anda.', 'bi-cash-coin'],
                            ];
                        @endphp
                        @foreach($paymentMethods as $method)
                            @php([$label, $desc, $icon] = $methodLabels[$method])
                            {{-- is-selected = option-card (tema terang/gelap,
                                 tampilan terang identik dgn gaya inline lama). --}}
                            <label class="option-card border rounded-3 p-3 d-flex gap-3 align-items-start {{ old('payment_method', $paymentMethods[0]) === $method ? 'is-selected' : '' }}">
                                <input type="radio" name="payment_method" value="{{ $method }}"
                                       class="form-check-input mt-1" {{ old('payment_method', $paymentMethods[0]) === $method ? 'checked' : '' }}>
                                <div>
                                    <div class="fw-semibold text-dark"><i class="bi {{ $icon }} me-1"></i>{{ $label }}</div>
                                    <div class="text-muted small">{{ $desc }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-3">
                        <label for="notes" class="form-label small fw-semibold">Catatan Pesanan <span class="text-secondary fw-normal">(opsional)</span></label>
                        <textarea name="notes" id="notes" rows="2" class="form-control @error('notes') is-invalid @enderror" placeholder="Instruksi khusus untuk pesanan Anda...">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            {{-- 3. Ringkasan Pesanan --}}
            <div class="card card-custom border-0">
                <div class="card-header bg-white fw-bold text-dark">
                    <span class="me-2">3.</span> Ringkasan Pesanan
                </div>
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Subtotal ({{ array_sum(array_column($items, 'quantity')) }} item)</span>
                        <span class="fw-semibold text-dark">Rp {{ number_format($subtotal, 0, ',', '.') }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Ongkir</span>
                        <span class="fw-semibold text-dark">Rp {{ number_format($shippingFee, 0, ',', '.') }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3 pb-3 border-bottom">
                        <span class="text-muted small">Diskon</span>
                        <span class="fw-semibold text-success">- Rp {{ number_format($discount, 0, ',', '.') }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="fw-bold text-dark">Total</span>
                        <span class="fw-bold fs-4 text-primary-atk">Rp {{ number_format($total, 0, ',', '.') }}</span>
                    </div>

                    <button type="submit" class="btn btn-accent-atk w-100 fw-semibold" {{ $addresses->isEmpty() ? 'disabled' : '' }}>
                        <i class="bi bi-check2-circle me-1"></i> Buat Pesanan
                    </button>
                    @if($addresses->isEmpty())
                        <small class="text-danger d-block text-center mt-2">
                            <i class="bi bi-info-circle me-1"></i> Tambahkan alamat terlebih dahulu.
                        </small>
                    @endif
                    <small class="text-muted d-block text-center mt-2">
                        <i class="bi bi-shield-check me-1"></i> Pesanan berstatus <strong>pending</strong> sampai pembayaran diverifikasi.
                    </small>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Modal: Tambah Alamat Baru (form terpisah agar tidak bersarang) --}}
<div class="modal fade" id="modalAlamat" tabindex="-1" aria-labelledby="modalAlamatLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form action="{{ route('addresses.store') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalAlamatLabel"><i class="bi bi-geo-alt me-2 text-primary"></i>Tambah Alamat Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="addr-label" class="form-label small fw-semibold">Label <span class="text-danger">*</span></label>
                            <input id="addr-label" type="text" name="label" value="{{ old('label') }}" class="form-control @error('label') is-invalid @enderror" placeholder="Rumah / Kantor" required>
                            @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="addr-recipient" class="form-label small fw-semibold">Nama Penerima <span class="text-danger">*</span></label>
                            <input id="addr-recipient" type="text" name="recipient_name" value="{{ old('recipient_name', auth()->user()->name) }}" class="form-control @error('recipient_name') is-invalid @enderror" required>
                            @error('recipient_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="addr-phone" class="form-label small fw-semibold">No. Telepon <span class="text-danger">*</span></label>
                            <input id="addr-phone" type="text" name="phone" value="{{ old('phone') }}" class="form-control @error('phone') is-invalid @enderror" placeholder="08xxxxxxxxxx" required>
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="addr-city" class="form-label small fw-semibold">Kota / Kabupaten <span class="text-danger">*</span></label>
                            <input id="addr-city" type="text" name="city" value="{{ old('city', 'Kab. Kuningan') }}" class="form-control @error('city') is-invalid @enderror" required>
                            @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="addr-full-address" class="form-label small fw-semibold">Alamat Lengkap <span class="text-danger">*</span></label>
                            <textarea id="addr-full-address" name="full_address" rows="2" class="form-control @error('full_address') is-invalid @enderror" placeholder="Nama jalan, RT/RW, kelurahan..." required>{{ old('full_address') }}</textarea>
                            @error('full_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="addr-district" class="form-label small fw-semibold">Kecamatan <span class="text-danger">*</span></label>
                            <input id="addr-district" type="text" name="district" value="{{ old('district') }}" class="form-control @error('district') is-invalid @enderror" required>
                            @error('district')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="addr-postal-code" class="form-label small fw-semibold">Kode Pos <span class="text-secondary fw-normal">(opsional)</span></label>
                            <input id="addr-postal-code" type="text" name="postal_code" value="{{ old('postal_code') }}" class="form-control @error('postal_code') is-invalid @enderror">
                            @error('postal_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="addr-notes" class="form-label small fw-semibold">Patokan / Catatan <span class="text-secondary fw-normal">(opsional)</span></label>
                            <input id="addr-notes" type="text" name="notes" value="{{ old('notes') }}" class="form-control" placeholder="Contoh: dekat masjid besar">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Simpan Alamat
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @if($errors->hasAny(['label', 'recipient_name', 'phone', 'full_address', 'city', 'district']))
        <script>
            new bootstrap.Modal(document.getElementById('modalAlamat')).show();
        </script>
    @endif
@endpush
