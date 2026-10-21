@extends('layouts.app')

@section('no-container', '')
@section('title', 'Daftar Pelanggan Baru')

@section('content')
<div class="py-5 auth-hero" style="background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 100%);">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card-custom p-4 p-md-5 border-0 shadow-sm bg-white">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-warning-subtle text-warning-emphasis rounded-circle mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-person-plus-fill fs-3"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Daftar Akun Pelanggan</h4>
                        <p class="text-muted small">Buat akun untuk belanja kebutuhan ATK Anda di Kuningan</p>
                    </div>

                    <form action="{{ route('register.post') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="name" class="form-label small fw-semibold text-dark">Nama Lengkap</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control border-start-0 ps-0 @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" placeholder="Contoh: Budi Santoso" required autofocus>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label small fw-semibold text-dark">Alamat Email</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control border-start-0 ps-0 @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" placeholder="nama@email.com" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="phone" class="form-label small fw-semibold text-dark">Nomor Telepon / WhatsApp</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-whatsapp"></i></span>
                                <input type="text" class="form-control border-start-0 ps-0 @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone') }}" placeholder="08xxxxxxxxxx" required>
                            </div>
                            <div class="form-text small text-muted">Digunakan untuk konfirmasi pesanan dan kurir saat pengantaran di Kuningan.</div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <label for="password" class="form-label small fw-semibold text-dark">Kata Sandi</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control border-start-0 ps-0 @error('password') is-invalid @enderror" id="password" name="password" placeholder="Min. 8 karakter" minlength="8" required>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label for="password_confirmation" class="form-label small fw-semibold text-dark">Ulangi Kata Sandi</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-check2-circle"></i></span>
                                    <input type="password" class="form-control border-start-0 ps-0" id="password_confirmation" name="password_confirmation" placeholder="Ulangi kata sandi" required>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary-atk w-100 py-2 rounded-3 shadow-sm mb-3">
                            <i class="bi bi-person-check-fill me-1"></i> Daftar Sekarang
                        </button>
                    </form>

                    <div class="text-center small text-muted">
                        Sudah memiliki akun? <a href="{{ route('login') }}" class="text-primary fw-semibold text-decoration-none">Masuk di sini</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
