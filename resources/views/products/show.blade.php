@extends('layouts.app')

@section('title', $product->name)

@section('content')
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-decoration-none">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}" class="text-decoration-none">Katalog</a></li>
        @if($product->category)
            <li class="breadcrumb-item">
                <a href="{{ route('products.index', ['category_id' => $product->category_id]) }}" class="text-decoration-none">
                    {{ $product->category->name }}
                </a>
            </li>
        @endif
        <li class="breadcrumb-item active text-truncate" style="max-width: 220px;">{{ $product->name }}</li>
    </ol>
</nav>

<div class="row g-4">
    {{-- Galeri Gambar --}}
    <div class="col-lg-6">
        <div class="card card-custom border-0 overflow-hidden">
            <div class="bg-light d-flex align-items-center justify-content-center" style="height: 380px;">
                @if($product->productImages->isNotEmpty())
                    <img id="mainImage"
                         src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->productImages->first()->image_path) }}"
                         alt="{{ $product->name }}" class="w-100 h-100" style="object-fit: contain;">
                @else
                    <div class="text-center text-secondary">
                        <i class="bi bi-card-image display-3 opacity-25 d-block mb-2"></i>
                        <span class="small">Belum ada gambar</span>
                    </div>
                @endif
            </div>

            @if($product->productImages->count() > 1)
                <div class="card-body d-flex gap-2 flex-wrap p-3">
                    @foreach($product->productImages as $image)
                        <button type="button" class="btn p-0 border rounded overflow-hidden thumbnail-btn"
                                onclick="document.getElementById('mainImage').src = '{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->image_path) }}'">
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->image_path) }}"
                                 alt="Gambar {{ $loop->iteration }}" width="64" height="64" style="object-fit: cover; display: block;">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Informasi Produk --}}
    <div class="col-lg-6">
        <div class="card card-custom border-0 p-4">
            <div class="d-flex flex-wrap gap-2 mb-2">
                @if($product->category)
                    <span class="badge bg-primary-subtle text-primary">{{ $product->category->name }}</span>
                @endif
                @if($product->brand)
                    <span class="badge bg-light text-dark border">{{ $product->brand }}</span>
                @endif
                <span class="badge bg-light text-secondary border">SKU: {{ $product->sku }}</span>
            </div>

            @if($product->barcode)
                {{-- Label barcode produk: discan kamera dari layar ini (uji fitur scan) --}}
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div id="productBarcodeQr" data-code="{{ $product->barcode }}"
                         class="border rounded p-1 bg-white d-inline-block" style="width: 104px; height: 104px;"></div>
                    <div class="small text-muted-atk">
                        <div class="fw-semibold text-dark">Barcode Produk</div>
                        {{ $product->barcode }}<br>
                        Masukkan kode ini di kolom
                        <a href="{{ route('products.index') }}" class="text-decoration-none">pencarian katalog</a>
                        untuk menemukan produk ini.
                    </div>
                </div>
            @endif

            <h1 class="h4 fw-bold text-dark mb-2">{{ $product->name }}</h1>

            <div class="fs-3 fw-bold text-primary-atk mb-3">
                Rp {{ number_format((float) $product->price, 0, ',', '.') }}
                <span class="fs-6 fw-normal text-muted">/ {{ $product->unit }}</span>
            </div>

            {{-- Status stok --}}
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                @if($product->stock <= 0)
                    <span class="badge bg-danger px-3 py-2"><i class="bi bi-x-circle me-1"></i> Stok Habis</span>
                @elseif($product->stock <= $product->min_stock)
                    <span class="badge bg-warning text-dark px-3 py-2"><i class="bi bi-exclamation-triangle me-1"></i> Stok Menipis — Sisa {{ $product->stock }} {{ $product->unit }}</span>
                @else
                    <span class="badge bg-success-subtle text-success px-3 py-2"><i class="bi bi-check-circle me-1"></i> Tersedia: {{ $product->stock }} {{ $product->unit }}</span>
                @endif
                <span class="text-muted small"><i class="bi bi-truck me-1"></i> Pengiriman area Kuningan</span>
            </div>

            <table class="table table-borderless small mb-3">
                <tbody>
                    <tr>
                        <td class="text-muted" style="width: 35%;">Satuan</td>
                        <td class="fw-semibold text-dark">{{ $product->unit }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Berat</td>
                        <td class="fw-semibold text-dark">{{ $product->weight }} gram</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Kategori</td>
                        <td class="fw-semibold text-dark">{{ $product->category?->name ?? '-' }}</td>
                    </tr>
                </tbody>
            </table>

            {{-- Aksi: tambah ke keranjang --}}
            @if($product->stock > 0)
                @auth
                    @if(auth()->user()->isCustomer())
                        <form action="{{ route('cart.store') }}" method="POST" class="mt-2">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                            <div class="input-group input-group-lg mb-2" style="max-width: 320px;">
                                <span class="input-group-text bg-light">Qty</span>
                                <input type="number" name="quantity" value="{{ old('quantity', 1) }}" min="1" max="{{ $product->stock }}"
                                       aria-label="Jumlah pembelian"
                                       class="form-control @error('quantity') is-invalid @enderror" required>
                                <button type="submit" class="btn btn-accent-atk fw-semibold px-4">
                                    <i class="bi bi-cart-plus me-1"></i> Tambah
                                </button>
                            </div>
                            @error('quantity')
                                <div class="text-danger small mb-2"><i class="bi bi-exclamation-circle me-1"></i> {{ $message }}</div>
                            @enderror
                            <small class="text-muted">Maksimal {{ $product->stock }} {{ $product->unit }} per pemesanan (sesuai stok tersedia).</small>
                        </form>
                    @else
                        <div class="alert alert-secondary small mb-0">
                            <i class="bi bi-info-circle me-1"></i> Hanya akun <strong>Pelanggan</strong> yang dapat menambahkan produk ke keranjang.
                        </div>
                    @endif
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary-atk btn-lg mt-2">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk untuk Membeli
                    </a>
                @endauth
            @else
                <div class="alert alert-danger small mb-0">
                    <i class="bi bi-x-octagon me-1"></i> Produk ini sedang habis. Silakan kembali lagi nanti.
                </div>
            @endif

            @if($product->description)
                <hr>
                <h2 class="fw-bold text-dark mb-2 fs-6">Deskripsi Produk</h2>
                <p class="text-muted small lh-lg mb-0">{!! nl2br(e($product->description)) !!}</p>
            @endif
        </div>
    </div>
</div>

{{-- Ulasan Produk --}}
<div class="card border-0 shadow-sm rounded-3 mt-4">
    <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h3 class="mb-0 fw-bold text-dark fs-5">
            <i class="bi bi-star-fill text-warning me-2"></i>Ulasan Produk
        </h3>
        @if($reviewCount > 0)
            <div class="d-flex align-items-center gap-2">
                @include('partials.star-rating', ['rating' => $avgRating])
                <span class="fw-bold text-dark">{{ number_format($avgRating, 1, '.', '') }}</span>
                <span class="text-muted small">dari 5 · {{ $reviewCount }} ulasan</span>
            </div>
        @else
            <span class="text-muted small">Belum ada ulasan</span>
        @endif
    </div>

    <div class="card-body p-4">
        {{-- Form review: khusus pelanggan dengan pesanan eligible --}}
        @auth
            @if(auth()->user()->isCustomer())
                @if($eligibleOrders->isNotEmpty())
                    <form action="{{ route('reviews.store', $product) }}" method="POST" class="mb-4 p-3 bg-light rounded-3 border">
                        @csrf
                        <h4 class="fw-bold text-dark mb-3 fs-6"><i class="bi bi-pencil-square me-1"></i> Tulis Ulasan Anda</h4>

                        @if($eligibleOrders->count() > 1)
                            <label for="reviewOrder" class="form-label small fw-semibold text-dark">Pesanan <span class="text-danger">*</span></label>
                            <select name="order_id" id="reviewOrder"
                                    class="form-select mb-3 @error('order_id') is-invalid @enderror" required>
                                @foreach($eligibleOrders as $eligibleOrder)
                                    <option value="{{ $eligibleOrder->id }}" {{ old('order_id') == $eligibleOrder->id ? 'selected' : '' }}>
                                        {{ $eligibleOrder->order_number }} ({{ $eligibleOrder->created_at->format('d M Y') }})
                                    </option>
                                @endforeach
                            </select>
                            @error('order_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        @else
                            <input type="hidden" name="order_id" value="{{ $eligibleOrders->first()->id }}">
                            <div class="small text-muted mb-3">
                                Pesanan: <strong class="text-dark">{{ $eligibleOrders->first()->order_number }}</strong>
                            </div>
                            @error('order_id')
                                <div class="text-danger small mb-3"><i class="bi bi-exclamation-circle me-1"></i> {{ $message }}</div>
                            @enderror
                        @endif

                        <label class="form-label small fw-semibold text-dark d-block">Rating <span class="text-danger">*</span></label>
                        <div class="d-flex gap-1 flex-wrap mb-3" role="group" aria-label="Pilih rating">
                            @for($i = 1; $i <= 5; $i++)
                                <div class="form-check form-check-inline mb-1">
                                    <input class="form-check-input" type="radio" name="rating" value="{{ $i }}"
                                           id="rating-{{ $i }}" {{ old('rating') == $i ? 'checked' : '' }} required>
                                    <label class="form-check-label small" for="rating-{{ $i }}">
                                        {{ $i }} <i class="bi bi-star-fill text-warning"></i>
                                    </label>
                                </div>
                            @endfor
                        </div>
                        @error('rating')
                            <div class="text-danger small mb-3"><i class="bi bi-exclamation-circle me-1"></i> {{ $message }}</div>
                        @enderror

                        <label for="reviewComment" class="form-label small fw-semibold text-dark">Komentar <span class="text-danger">*</span></label>
                        <textarea name="comment" id="reviewComment" rows="3" maxlength="1000"
                                  class="form-control mb-3 @error('comment') is-invalid @enderror"
                                  placeholder="Ceritakan pengalaman Anda dengan produk ini...">{{ old('comment') }}</textarea>
                        @error('comment')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror

                        <button type="submit" class="btn btn-primary-atk fw-semibold">
                            <i class="bi bi-send me-1"></i> Kirim Ulasan
                        </button>
                    </form>
                @else
                    <div class="alert alert-secondary small mb-4">
                        <i class="bi bi-info-circle me-1"></i>
                        Anda belum dapat mengulas produk ini. Review hanya dapat diberikan untuk produk dari pesanan
                        yang sudah <strong>selesai (completed)</strong> dan belum Anda ulasi.
                    </div>
                @endif
            @endif
        @endauth

        {{-- Daftar review --}}
        @if($reviewCount === 0)
            <div class="text-center text-muted py-4">
                <i class="bi bi-chat-square-text fs-1 d-block mb-2 opacity-25"></i>
                Belum ada ulasan untuk produk ini.
            </div>
        @else
            <div class="row g-3">
                @foreach($reviews as $review)
                    <div class="col-12">
                        <div class="border rounded-3 p-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <span class="fw-bold text-dark">{{ $review->user?->name ?? 'Pelanggan' }}</span>
                                    <div class="small text-muted">
                                        <i class="bi bi-calendar3 me-1"></i>{{ $review->created_at->format('d M Y') }}
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    @include('partials.star-rating', ['rating' => $review->rating])
                                    @if(auth()->check() && auth()->user()->isAdmin())
                                        <form action="{{ route('admin.reviews.destroy', $review) }}" method="POST" class="d-inline"
                                              onsubmit="return confirm({{ Illuminate\Support\Js::from('Hapus review dari ' . ($review->user?->name ?? 'pelanggan') . ' ini?') }});">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    title="Hapus review" aria-label="Hapus review">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                            <p class="mb-0 small text-dark">{{ $review->comment }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@04f46c6a0708418cb7b96fc563eacae0fbf77674/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
<script>
    // QR label barcode produk — bisa langsung discan dari layar ini
    // untuk menguji fitur kamera (pencarian & chatbot).
    document.addEventListener('DOMContentLoaded', function () {
        const qrBox = document.getElementById('productBarcodeQr');
        if (qrBox && window.QRCode) {
            new QRCode(qrBox, {
                text: qrBox.dataset.code,
                width: 96,
                height: 96,
                correctLevel: QRCode.CorrectLevel.M
            });
        }
    });
</script>
@endpush
