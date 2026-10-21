@extends('layouts.app')

@section('title', 'Keranjang Belanja')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold text-dark mb-1"><i class="bi bi-cart3 me-2 text-primary"></i>Keranjang Belanja</h1>
        <p class="text-muted small mb-0">Periksa kembali produk dan jumlah pesanan Anda.</p>
    </div>
    <a href="{{ route('products.index') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Lanjut Belanja
    </a>
</div>

@if(count($items) === 0)
    {{-- Empty state --}}
    <div class="card card-custom border-0">
        <div class="card-body text-center py-5 px-4">
            <div class="display-1 text-secondary opacity-25 mb-3">
                <i class="bi bi-cart-x"></i>
            </div>
            <h2 class="fw-bold text-dark fs-4">Keranjang Anda Masih Kosong</h2>
            <p class="text-muted mb-4" style="max-width: 460px; margin-left: auto; margin-right: auto;">
                Yuk, mulai pilih kebutuhan ATK Anda dari katalog. Tambahkan produk ke keranjang untuk melanjutkan belanja.
            </p>
            <a href="{{ route('products.index') }}" class="btn btn-accent-atk px-4 fw-semibold">
                <i class="bi bi-bag me-1"></i> Jelajahi Katalog Produk
            </a>
        </div>
    </div>
@else
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card card-custom border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 45%;">Produk</th>
                                    <th class="text-end">Harga Satuan</th>
                                    <th class="text-center" style="width: 160px;">Jumlah</th>
                                    <th class="text-end">Subtotal</th>
                                    <th class="text-center" style="width: 60px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($items as $item)
                                    @php($product = $item['product'])
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <a href="{{ route('products.show', $product) }}">
                                                    @if($product->primaryImage)
                                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->primaryImage->image_path) }}"
                                                             alt="{{ $product->name }}" class="rounded border" width="56" height="56" style="object-fit: cover;">
                                                    @else
                                                        <div class="rounded border bg-light d-flex align-items-center justify-content-center text-secondary" style="width: 56px; height: 56px;">
                                                            <i class="bi bi-card-image"></i>
                                                        </div>
                                                    @endif
                                                </a>
                                                <div>
                                                    <a href="{{ route('products.show', $product) }}" class="fw-semibold text-dark text-decoration-none d-block">
                                                        {{ $product->name }}
                                                    </a>
                                                    <small class="text-muted">
                                                        {{ $product->category?->name ?? '-' }} · Stok tersisa {{ $product->stock }} {{ $product->unit }}
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-end text-dark small">
                                            Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                                        </td>
                                        <td>
                                            <form action="{{ route('cart.update', $product) }}" method="POST" class="d-flex gap-1">
                                                @csrf
                                                @method('PATCH')
                                                <input type="number" name="quantity" value="{{ $item['quantity'] }}" min="1" max="{{ $product->stock }}"
                                                       class="form-control form-control-sm text-center @error('quantity') is-invalid @enderror"
                                                       style="max-width: 80px;" aria-label="Jumlah {{ $product->name }}">
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Perbarui jumlah" aria-label="Perbarui jumlah keranjang">
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end fw-semibold text-dark">
                                            Rp {{ number_format($item['subtotal'], 0, ',', '.') }}
                                        </td>
                                        <td class="text-center">
                                            <form action="{{ route('cart.destroy', $product) }}" method="POST"
                                                  onsubmit="return confirm({{ Illuminate\Support\Js::from('Hapus "' . $product->name . '" dari keranjang? Produk bisa ditambahkan kembali dari katalog.') }});">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus dari keranjang" aria-label="Hapus dari keranjang">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            @error('quantity')
                <div class="alert alert-danger mt-3 small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i> {{ $message }}
                </div>
            @enderror
        </div>

        {{-- Ringkasan --}}
        <div class="col-lg-4">
            <div class="card card-custom border-0">
                <div class="card-header bg-white fw-bold text-dark">
                    <i class="bi bi-receipt me-2 text-primary"></i>Ringkasan Keranjang
                </div>
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Jumlah produk</span>
                        <span class="fw-semibold text-dark">{{ $totalCount }} item</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3 pb-3 border-bottom">
                        <span class="text-muted">Total harga</span>
                        <span class="fw-bold fs-5 text-primary-atk">Rp {{ number_format($total, 0, ',', '.') }}</span>
                    </div>

                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-truck me-1"></i> Pengiriman tersedia di wilayah Kota & Kabupaten Kuningan.
                    </div>

                    <a href="{{ route('products.index') }}" class="btn btn-outline-primary w-100 mb-2">
                        <i class="bi bi-arrow-left me-1"></i> Lanjut Belanja
                    </a>

                    <a href="{{ route('checkout.index') }}" class="btn btn-accent-atk w-100 fw-semibold mb-2">
                        <i class="bi bi-bag-check me-1"></i> Lanjut ke Checkout
                    </a>

                    <small class="text-muted d-block text-center">
                        <i class="bi bi-lock me-1"></i> Alamat, metode pembayaran, dan ongkir dipilih di halaman checkout.
                    </small>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection
