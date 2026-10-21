@extends('layouts.app')

@section('no-container', '')
@section('title', 'Masuk Akun')

@section('content')
<div class="py-5 auth-hero" style="background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 100%);">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-5">
                <div class="card-custom p-4 p-md-5 border-0 shadow-sm bg-white">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded-circle mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-person-circle fs-3"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Masuk ke Agung ATK</h4>
                        <p class="text-muted small">Silakan masuk menggunakan akun terdaftar Anda</p>
                    </div>

                    <form action="{{ route('login.post') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="email" class="form-label small fw-semibold text-dark">Alamat Email</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control border-start-0 ps-0 @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" placeholder="nama@email.com" required autofocus>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label small fw-semibold text-dark">Kata Sandi</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control border-start-0 ps-0 @error('password') is-invalid @enderror" id="password" name="password" placeholder="••••••••" required>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                <label class="form-check-label small text-muted" for="remember">
                                    Ingat saya
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary-atk w-100 py-2 rounded-3 shadow-sm mb-3">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
                        </button>
                    </form>

                    <div class="text-center small text-muted mb-4">
                        Belum memiliki akun? <a href="{{ route('register') }}" class="text-primary fw-semibold text-decoration-none">Daftar sebagai Pelanggan</a>
                    </div>

                    {{-- Demo Quick Login Box untuk 4 Role — HANYA untuk lingkungan
                         non-production. Di produksi (APP_ENV=production) blok ini
                         tidak dirender agar kredensial/akun uji tidak dipublikasikan. --}}
                    @if(config('app.env') !== 'production')
                    <div class="p-3 bg-light rounded-3 border border-secondary-subtle">
                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1">
                            <i class="bi bi-lightning-charge-fill text-warning"></i> Akun Uji Coba (4 Role):
                        </div>
                        <div class="d-grid gap-1 small">
                            <button type="button" class="btn btn-sm btn-outline-danger text-start py-1 px-2 d-flex justify-content-between" onclick="fillLogin('admin@agungatk.com', 'password')">
                                <span><i class="bi bi-shield-check me-1"></i> <strong>Admin:</strong> admin@agungatk.com</span>
                                <span class="badge bg-danger">Isi</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success text-start py-1 px-2 d-flex justify-content-between" onclick="fillLogin('penjual@agungatk.com', 'password')">
                                <span><i class="bi bi-shop me-1"></i> <strong>Anggota:</strong> penjual@agungatk.com</span>
                                <span class="badge bg-success">Isi</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info text-start py-1 px-2 d-flex justify-content-between" onclick="fillLogin('kurir@agungatk.com', 'password')">
                                <span><i class="bi bi-truck me-1"></i> <strong>Kurir:</strong> kurir@agungatk.com</span>
                                <span class="badge bg-info text-dark">Isi</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning text-start py-1 px-2 d-flex justify-content-between" onclick="fillLogin('pelanggan@agungatk.com', 'password')">
                                <span><i class="bi bi-person me-1"></i> <strong>Pelanggan:</strong> pelanggan@agungatk.com</span>
                                <span class="badge bg-warning text-dark">Isi</span>
                            </button>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function fillLogin(email, password) {
        document.getElementById('email').value = email;
        document.getElementById('password').value = password;
    }
</script>
@endpush
