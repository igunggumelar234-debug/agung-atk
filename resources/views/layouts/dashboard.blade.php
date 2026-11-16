<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dashboard internal Sistem Informasi Penjualan Agung ATK - Toko Alat Tulis Kerja Kuningan.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') - Agung ATK Kuningan</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">

    {{-- Design system bersama (tombol/kartu/badge/form/tabel/empty-state) --}}
    @include('partials.theme')

    {{-- Inisialisasi tema sebelum render (anti-FOUC) + pemilih tema --}}
    @include('partials.theme-init')

    <style>
        body {
            background-color: var(--atk-bg);
            min-height: 100vh;
        }

        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ============================= Sidebar ============================= */
        .dashboard-sidebar {
            width: 260px;
            background-color: var(--atk-sidebar-bg);
            color: #94a3b8;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            z-index: 1045;
        }

        .dashboard-sidebar .brand-box {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .dashboard-sidebar .nav-link {
            color: #94a3b8;
            padding: 0.7rem 1.25rem;
            border-radius: 0.5rem;
            margin: 0.15rem 0.75rem;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.2s;
        }

        .dashboard-sidebar .nav-link:hover,
        .dashboard-sidebar .nav-link.active {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .dashboard-sidebar .nav-link.active {
            background-color: var(--atk-primary);
            color: #ffffff;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(15, 23, 42, 0.5);
            z-index: 1040;
        }

        /* ============================ Konten =============================== */
        .dashboard-content {
            flex-grow: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        .top-navbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            background-color: #ffffff;
            border-bottom: 1px solid var(--atk-border);
            padding: 0.85rem 1.25rem;
        }

        /* ========================= Mobile / tablet ========================= */
        @media (max-width: 991.98px) {
            .dashboard-sidebar {
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                width: 270px;
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                overflow-y: auto;
            }

            .dashboard-sidebar.is-open {
                transform: translateX(0);
                box-shadow: 0 0 40px rgba(0, 0, 0, 0.35);
            }

            .sidebar-overlay.is-open {
                display: block;
            }
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="dashboard-wrapper">
        {{-- Sidebar --}}
        <aside class="dashboard-sidebar" id="dashboardSidebar">
            <div class="brand-box d-flex align-items-center justify-content-between">
                <a href="{{ url('/') }}" class="text-decoration-none d-flex align-items-center gap-2">
                    <span class="bg-primary text-white p-1 rounded-2">
                        <i class="bi bi-pencil-square"></i>
                    </span>
                    <span class="fw-bold text-white fs-5">AGUNG <span class="text-warning">ATK</span></span>
                </a>
            </div>

            <div class="p-3">
                <div class="p-2 rounded-3 bg-white bg-opacity-10 text-white small d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <div class="overflow-hidden">
                        <div class="fw-bold text-truncate">{{ auth()->user()->name }}</div>
                        <span class="badge role-badge-{{ auth()->user()->role?->name }} px-2 py-0">
                            {{ auth()->user()->role?->label ?? ucfirst(auth()->user()->role?->name) }}
                        </span>
                    </div>
                </div>
            </div>

            <nav class="nav flex-column mb-auto">
                <div class="px-3 py-1 text-uppercase text-secondary" style="font-size: 0.7rem; font-weight: 700;">Menu Utama</div>

                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-speedometer2"></i> Dashboard Admin
                    </a>
                    <a href="{{ route('admin.orders.index') }}" class="nav-link {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
                        <i class="bi bi-receipt"></i> Pesanan
                    </a>
                    <a href="{{ route('admin.payments.index') }}" class="nav-link {{ request()->routeIs('admin.payments.*') ? 'active' : '' }}">
                        <i class="bi bi-wallet2"></i> Pembayaran
                    </a>
                    <a href="{{ route('admin.categories.index') }}" class="nav-link {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                        <i class="bi bi-tags"></i> Kategori
                    </a>
                    <a href="{{ route('admin.products.index') }}" class="nav-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
                        <i class="bi bi-box-seam"></i> Produk & Stok
                    </a>
                    <a href="{{ route('admin.shipments.index') }}" class="nav-link {{ request()->routeIs('admin.shipments.*') ? 'active' : '' }}">
                        <i class="bi bi-truck"></i> Pengiriman
                    </a>
                    <a href="{{ route('admin.couriers.index') }}" class="nav-link {{ request()->routeIs('admin.couriers.*') ? 'active' : '' }}">
                        <i class="bi bi-bicycle"></i> Kurir
                    </a>
                    <a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                        <i class="bi bi-people"></i> Pengguna & Pegawai
                    </a>
                    <a href="{{ route('admin.activity-logs.index') }}" class="nav-link {{ request()->routeIs('admin.activity-logs.*') ? 'active' : '' }}">
                        <i class="bi bi-clock-history"></i> Audit Log
                    </a>
                    <a href="{{ route('admin.reports.index') }}" class="nav-link {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}">
                        <i class="bi bi-bar-chart-line"></i> Laporan
                    </a>
                    <a href="{{ route('admin.notifications.index') }}" class="nav-link {{ request()->routeIs('admin.notifications.*') ? 'active' : '' }}">
                        <i class="bi bi-bell"></i> Notifikasi
                    </a>
                    <a href="{{ route('profile.index') }}" class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                        <i class="bi bi-person-gear"></i> Profil Saya
                    </a>
                @elseif(auth()->user()->isAnggota())
                    <a href="{{ route('anggota.dashboard') }}" class="nav-link {{ request()->routeIs('anggota.dashboard', 'seller.dashboard', 'penjual.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-shop"></i> Dashboard Anggota
                    </a>
                    <a href="{{ route('anggota.orders.index') }}" class="nav-link {{ request()->routeIs('anggota.orders.*', 'seller.orders.*') ? 'active' : '' }}">
                        <i class="bi bi-bag-check"></i> Pesanan
                    </a>
                    <a href="{{ route('anggota.stock.index') }}" class="nav-link {{ request()->routeIs('anggota.stock.*') ? 'active' : '' }}">
                        <i class="bi bi-box-seam"></i> Stok Barang
                    </a>
                    <a href="{{ route('profile.index') }}" class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                        <i class="bi bi-person-gear"></i> Profil Saya
                    </a>
                @elseif(auth()->user()->isCourier())
                    <a href="{{ route('courier.dashboard') }}" class="nav-link {{ request()->routeIs('courier.dashboard', 'kurir.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-truck"></i> Dashboard Tugas Kirim
                    </a>
                    <a href="{{ route('courier.shipments.index') }}" class="nav-link {{ request()->routeIs('courier.shipments.*') ? 'active' : '' }}">
                        <i class="bi bi-box-seam"></i> Pengiriman Saya
                    </a>
                    <a href="{{ route('profile.index') }}" class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                        <i class="bi bi-person-gear"></i> Profil Saya
                    </a>
                @elseif(auth()->user()->isCustomer())
                    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard', 'customer.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-person-circle"></i> Dashboard Pelanggan
                    </a>
                    <a href="{{ route('orders.index') }}" class="nav-link {{ request()->routeIs('orders.*') ? 'active' : '' }}">
                        <i class="bi bi-receipt"></i> Pesanan Saya
                    </a>
                    <a href="{{ route('addresses.index') }}" class="nav-link {{ request()->routeIs('addresses.*') ? 'active' : '' }}">
                        <i class="bi bi-geo-alt"></i> Alamat Pengiriman
                    </a>
                    <a href="{{ route('profile.index') }}" class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                        <i class="bi bi-person-gear"></i> Profil Saya
                    </a>
                    <a href="{{ route('ai.chat') }}" class="nav-link {{ request()->routeIs('ai.*') ? 'active' : '' }}">
                        <i class="bi bi-robot"></i> AI Assistant
                    </a>
                @endif

                <a href="{{ url('/') }}" class="nav-link">
                    <i class="bi bi-house-door"></i> Ke Toko Depan
                </a>
            </nav>

            <div class="p-3 border-top border-secondary border-opacity-25">
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-box-arrow-right"></i> Keluar
                    </button>
                </form>
            </div>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        {{-- Main Content --}}
        <div class="dashboard-content">
            <header class="top-navbar d-flex align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2 min-width-0">
                    <button class="btn btn-outline-secondary btn-sm d-lg-none flex-shrink-0" type="button"
                            id="sidebarToggle" aria-expanded="false" aria-controls="dashboardSidebar" aria-label="Buka menu navigasi">
                        <i class="bi bi-list fs-5"></i>
                    </button>
                    <h5 class="mb-0 fw-bold text-dark text-truncate">@yield('page-title', 'Dashboard')</h5>
                    <span class="badge bg-light text-muted border d-none d-sm-inline-block">Area: Kab. Kuningan</span>
                </div>

                <div class="d-flex align-items-center gap-3">
                    @include('partials.theme-toggle')
                    <span class="text-secondary small d-none d-md-inline">
                        <i class="bi bi-calendar3 me-1"></i> {{ now()->translatedFormat('l, d F Y') }}
                    </span>
                </div>
            </header>

            <main class="p-3 p-md-4">
                {{-- Flash Messages & Error Validasi --}}
                @include('partials.flash')

                @yield('content')
            </main>
        </div>
    </div>

    {{-- Chatbot widget mengambang (semua menu dashboard dalam) --}}
    @include('partials.visual-search')
    @include('partials.home-chatbot')

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <script>
        (function () {
            /* ---- Sidebar off-canvas (mobile / tablet) ---- */
            const sidebar = document.getElementById('dashboardSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggle = document.getElementById('sidebarToggle');

            function setSidebar(open) {
                sidebar.classList.toggle('is-open', open);
                overlay.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                document.body.style.overflow = open ? 'hidden' : '';
            }

            toggle.addEventListener('click', function () {
                setSidebar(!sidebar.classList.contains('is-open'));
            });

            overlay.addEventListener('click', function () {
                setSidebar(false);
            });

            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth < 992) {
                        setSidebar(false);
                    }
                });
            });

            /* ---- Loading state global (anti double-submit) ---- */
            document.addEventListener('submit', function (event) {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                const button = form.querySelector('button[type="submit"]:not([data-no-loading])');
                if (!button || button.dataset.loading === '1') {
                    return;
                }

                button.dataset.loading = '1';
                button.dataset.originalHtml = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Memproses...';
            });

            window.addEventListener('pageshow', function (event) {
                if (!event.persisted) {
                    return;
                }
                document.querySelectorAll('button[data-loading="1"]').forEach(function (button) {
                    button.disabled = false;
                    button.removeAttribute('data-loading');
                    if (button.dataset.originalHtml) {
                        button.innerHTML = button.dataset.originalHtml;
                        delete button.dataset.originalHtml;
                    }
                });
            });
        })();
    </script>
    @stack('scripts')
</body>
</html>
