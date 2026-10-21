@extends('layouts.app')

@section('no-container', '')
@section('title', 'Beranda')

@section('content')
<div class="py-5 auth-hero" style="background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 100%);">
    <div class="container">
        {{-- Hero Branding Section --}}
        <div class="row align-items-center g-5 py-4">
            <div class="col-lg-7">
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-white shadow-sm border border-primary-subtle text-primary fw-semibold small mb-3">
                    <i class="bi bi-geo-alt-fill text-warning"></i> Wilayah Kota & Kabupaten Kuningan
                </div>
                <h1 class="display-4 fw-extrabold text-dark tracking-tight mb-2" style="color: #1e3a8a !important;">
                    AGUNG <span class="text-warning">ATK</span>
                </h1>
                <p class="lead fw-semibold text-dark mb-3 fs-5">
                    Sistem Informasi Penjualan Toko ATK di Kuningan
                </p>
                <p class="text-muted mb-4 fs-6 lh-base">
                    Platform digital terpadu untuk jual beli Alat Tulis Kerja (ATK) di lingkungan Kota dan Kabupaten Kuningan. Menghubungkan admin toko, penjual, kurir pengantaran lokal, dan pelanggan dalam satu sistem yang rapi dan mudah digunakan.
                </p>

                <div class="d-flex flex-wrap gap-3">
                    <a href="#peran-sistem" class="btn btn-primary btn-lg px-4 py-2 rounded-3 shadow-sm d-inline-flex align-items-center gap-2" style="background-color: #1e3a8a; border-color: #1e3a8a;">
                        <i class="bi bi-people"></i> 4 Role Pengguna
                    </a>
                </div>
            </div>

            <div class="col-lg-5">
                {{-- background memakai var(--atk-card-bg) dari .card-custom
                     (inline "white" sebelumnya mengalahkan tema gelap -> teks
                     terang di atas kartu putih pada mode gelap). --}}
                <div class="card-custom p-4 text-center border-0 shadow">
                    <div class="p-4 rounded-4 bg-light mb-3">
                        <div class="display-1 text-primary mb-2" style="color: #1e3a8a !important;">
                            <i class="bi bi-shop-window"></i>
                        </div>
                        <h2 class="fw-bold text-dark mb-1 fs-4">AGUNG ATK</h2>
                        <p class="text-muted small mb-0">Alat Tulis Kantor & Sekolah Terlengkap di Kuningan</p>
                    </div>

                    <div class="row g-2 text-start small">
                        <div class="col-6">
                            <div class="p-2 border rounded-3 bg-white">
                                <div class="text-muted small">Cakupan Wilayah</div>
                                <div class="fw-bold text-dark"><i class="bi bi-check2 text-success"></i> Se-Kab. Kuningan</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 border rounded-3 bg-white">
                                <div class="text-muted small">Pengiriman</div>
                                <div class="fw-bold text-dark"><i class="bi bi-truck text-primary"></i> Kurir Internal</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 border rounded-3 bg-white">
                                <div class="text-muted small">Metode Bayar</div>
                                <div class="fw-bold text-dark"><i class="bi bi-wallet2 text-warning"></i> Transfer, QRIS, COD</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 border rounded-3 bg-white">
                                <div class="text-muted small">Katalog</div>
                                <div class="fw-bold text-dark"><i class="bi bi-collection text-info"></i> Lengkap & Terstruktur</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Katalog: Produk Unggulan --}}
<div class="py-5 bg-white border-top border-bottom">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
            <div>
                <span class="badge bg-primary-subtle text-primary px-3 py-2 rounded-pill fw-semibold small">Katalog Produk</span>
                <h2 class="fw-bold text-dark mt-2 mb-0">Produk Unggulan</h2>
                <p class="text-muted small mb-0">Pilihan ATK terbaru yang siap dikirim ke seluruh wilayah Kuningan.</p>
            </div>
            <a href="{{ route('products.index') }}" class="btn btn-primary-atk btn-sm px-3">
                Lihat Semua Produk <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>

        @if($featured->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="bi bi-box-seam d-block fs-2 mb-2 opacity-50"></i>
                Belum ada produk aktif untuk ditampilkan.
            </div>
        @else
            <div class="row row-cols-2 row-cols-md-4 g-3">
                @foreach($featured as $product)
                    <div class="col">
                        <div class="card card-custom h-100 border-0">
                            <a href="{{ route('products.show', $product) }}" class="d-block bg-light overflow-hidden" style="height: 140px;">
                                @if($product->primaryImage)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->primaryImage->image_path) }}"
                                         alt="{{ $product->name }}" class="w-100 h-100" style="object-fit: cover;">
                                @else
                                    <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                                        <i class="bi bi-card-image display-6 text-secondary opacity-25"></i>
                                    </div>
                                @endif
                            </a>
                            <div class="card-body p-3">
                                <a href="{{ route('products.show', $product) }}" class="text-decoration-none">
                                    <div class="fw-semibold text-dark small text-truncate">{{ $product->name }}</div>
                                </a>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="fw-bold text-primary-atk small">Rp {{ number_format((float) $product->price, 0, ',', '.') }}</span>
                                    <span class="badge bg-light text-secondary border">{{ $product->category?->name ?? '-' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($catalogCategories->isNotEmpty())
            <div class="d-flex flex-wrap gap-2 mt-4">
                <span class="text-muted small align-self-center me-1">Kategori:</span>
                @foreach($catalogCategories as $cat)
                    <a href="{{ route('products.index', ['category_id' => $cat->id]) }}"
                       class="badge bg-light text-dark border text-decoration-none px-3 py-2">{{ $cat->name }}</a>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- 4 Role Pengguna --}}
<div class="py-5" id="peran-sistem">
    <div class="container">
        <div class="text-center max-w-xl mx-auto mb-5">
            <span class="badge bg-warning-subtle text-warning-emphasis px-3 py-2 rounded-pill fw-semibold text-uppercase small">Arsitektur Pengguna</span>
            <h2 class="fw-bold text-dark mt-2">4 Peran Utama Sistem</h2>
            <p class="text-muted">Setiap peran dirancang dengan alur kerja yang terisolasi dan aman.</p>
        </div>

        <div class="row g-4">
            {{-- Admin --}}
            <div class="col-md-6 col-lg-3">
                <div class="card-custom p-4 h-100 d-flex flex-column">
                    <div class="feature-icon-wrapper bg-primary-subtle text-primary mb-3">
                        <i class="bi bi-shield-lock-fill fs-4"></i>
                    </div>
                    <h3 class="fw-bold text-dark mb-2 fs-5">1. Admin</h3>
                    <p class="text-muted small flex-grow-1">
                        Pusat kendali operasional: mengelola pengguna, master produk & kategori, penyesuaian stok, verifikasi pembayaran, laporan penjualan, dan konfigurasi API AI.
                    </p>
                    <span class="badge bg-light text-secondary border text-start py-2 px-3 rounded-2 small">
                        <i class="bi bi-gear me-1"></i> Hak Akses Penuh
                    </span>
                </div>
            </div>

            {{-- Anggota / Pegawai Toko --}}
            <div class="col-md-6 col-lg-3">
                <div class="card-custom p-4 h-100 d-flex flex-column">
                    <div class="feature-icon-wrapper bg-success-subtle text-success mb-3">
                        <i class="bi bi-shop fs-4"></i>
                    </div>
                    <h3 class="fw-bold text-dark mb-2 fs-5">2. Anggota Toko</h3>
                    <p class="text-muted small flex-grow-1">
                        Karyawan internal toko: memproses pesanan yang masuk, menyiapkan & mengemas barang pesanan ATK, serta memantau dan mencatat ketersediaan stok produk.
                    </p>
                    <span class="badge bg-light text-secondary border text-start py-2 px-3 rounded-2 small">
                        <i class="bi bi-box-seam me-1"></i> Operasional Toko
                    </span>
                </div>
            </div>

            {{-- Kurir --}}
            <div class="col-md-6 col-lg-3">
                <div class="card-custom p-4 h-100 d-flex flex-column">
                    <div class="feature-icon-wrapper bg-info-subtle text-info mb-3">
                        <i class="bi bi-truck fs-4"></i>
                    </div>
                    <h3 class="fw-bold text-dark mb-2 fs-5">3. Kurir</h3>
                    <p class="text-muted small flex-grow-1">
                        Pengantaran wilayah Kuningan: menerima daftar pesanan siap kirim, navigasi alamat pelanggan, update status pengiriman, dan upload bukti serah terima.
                    </p>
                    <span class="badge bg-light text-secondary border text-start py-2 px-3 rounded-2 small">
                        <i class="bi bi-geo-alt me-1"></i> Distribusi Wilayah
                    </span>
                </div>
            </div>

            {{-- Pelanggan --}}
            <div class="col-md-6 col-lg-3">
                <div class="card-custom p-4 h-100 d-flex flex-column">
                    <div class="feature-icon-wrapper bg-warning-subtle text-warning mb-3">
                        <i class="bi bi-person-heart fs-4"></i>
                    </div>
                    <h3 class="fw-bold text-dark mb-2 fs-5">4. Pelanggan</h3>
                    <p class="text-muted small flex-grow-1">
                        Kemudahan belanja ATK: registrasi, katalog lengkap, keranjang, checkout alamat Kuningan, tracking status pesanan, review, dan konsultasi kebutuhan ATK via AI.
                    </p>
                    <span class="badge bg-light text-secondary border text-start py-2 px-3 rounded-2 small">
                        <i class="bi bi-cart-check me-1"></i> Belanja & Interaksi AI
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
