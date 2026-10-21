<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm py-3" style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="{{ url('/') }}">
            <span class="d-inline-flex align-items-center justify-content-center bg-white text-primary rounded-3 px-2 py-1 shadow-sm">
                <i class="bi bi-pencil-square fs-5 text-primary"></i>
            </span>
            <span>
                <span class="text-white tracking-wide">AGUNG</span>
                <span class="badge bg-warning text-dark fw-bold ms-1">ATK</span>
            </span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('/') ? 'active fw-semibold text-white' : 'text-white-50' }}" href="{{ url('/') }}">
                        <i class="bi bi-house-door me-1"></i> Beranda
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('products.*') ? 'active fw-semibold text-white' : 'text-white-50' }}" href="{{ route('products.index') }}">
                        <i class="bi bi-search me-1 d-none d-md-inline"></i> Katalog
                    </a>
                </li>
                <li class="nav-item">
                    <span class="nav-link text-white-50 d-none d-md-inline">
                        <i class="bi bi-geo-alt me-1"></i> Area: Kab. Kuningan
                    </span>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">
                @include('partials.theme-toggle', ['buttonClass' => 'btn-outline-light btn-sm rounded-pill'])
                @auth
                    @php
                        $user = auth()->user();
                        $dashUrl = match(true) {
                            $user->isAdmin() => url('/admin/dashboard'),
                            $user->isAnggota() => url('/anggota/dashboard'),
                            $user->isCourier() => url('/kurir/dashboard'),
                            default => url('/pelanggan/dashboard'),
                        };
                        $roleLabel = $user->role?->label ?? ucfirst($user->role?->name);
                    @endphp
                    @if($user->isCustomer())
                        <a href="{{ route('cart.index') }}" class="btn btn-outline-light btn-sm rounded-pill px-3 position-relative">
                            <i class="bi bi-cart3"></i> Keranjang
                            @php($cartCount = \App\Support\CartSession::count())
                            @if($cartCount > 0)
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger fw-bold">{{ $cartCount }}</span>
                            @endif
                        </a>
                    @endif
                    <a href="{{ $dashUrl }}" class="btn btn-warning btn-sm fw-semibold rounded-pill px-3 shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="bi bi-speedometer2"></i> Dashboard ({{ $roleLabel }})
                    </a>
                    <form action="{{ route('logout') }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm rounded-pill px-3">
                            <i class="bi bi-box-arrow-right me-1"></i> Keluar
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-medium">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
                    </a>
                    <a href="{{ route('register') }}" class="btn btn-warning btn-sm rounded-pill px-3 fw-semibold text-dark shadow-sm">
                        <i class="bi bi-person-plus me-1"></i> Daftar Pelanggan
                    </a>
                @endauth
            </div>
        </div>
    </div>
</nav>
