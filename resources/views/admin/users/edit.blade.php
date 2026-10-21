@extends('layouts.dashboard')

@section('title', 'Ubah Pengguna')
@section('page-title', 'Ubah Data Pengguna / Pegawai')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card card-stat">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
                    <div>
                        <div class="fw-bold fs-6">{{ $user->name }}</div>
                        <div class="text-muted small">
                            {{ $user->email }}
                            @if ($user->employee_code)
                                · No. Pegawai: <span class="badge bg-light text-dark border">{{ $user->employee_code }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('admin.users.activities', $user) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-clock-history me-1"></i> Riwayat Aktivitas
                        </a>
                    </div>
                </div>

                <form action="{{ route('admin.users.update', $user) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" class="form-control @error('email') is-invalid @enderror" required>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-semibold">No. Telepon</label>
                            <input type="text" name="phone" id="phone" value="{{ old('phone', $user->phone) }}" class="form-control @error('phone') is-invalid @enderror">
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="role" class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" {{ $user->id === auth()->id() ? 'disabled' : '' }} required>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->name }}" @selected(old('role', $user->role?->name) === $role->name)>{{ $role->label }}</option>
                                @endforeach
                            </select>
                            @if ($user->id === auth()->id())
                                <div class="form-text text-muted">Role akun sendiri tidak dapat diubah.</div>
                            @endif
                            @error('role')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="employee_code" class="form-label fw-semibold">No. Pegawai</label>
                            <input type="text" name="employee_code" id="employee_code" value="{{ old('employee_code', $user->employee_code) }}" class="form-control @error('employee_code') is-invalid @enderror"
                                   maxlength="30" placeholder="AGT-0001 / KUR-0001">
                            @error('employee_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="position" class="form-label fw-semibold">Jabatan</label>
                            <input type="text" name="position" id="position" value="{{ old('position', $user->position) }}" class="form-control @error('position') is-invalid @enderror"
                                   maxlength="100" placeholder="Contoh: Staf Operasional Toko">
                            @error('position')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="employment_status" class="form-label fw-semibold">Status Kerja</label>
                            <select name="employment_status" id="employment_status" class="form-select @error('employment_status') is-invalid @enderror">
                                <option value="">- Pilih status -</option>
                                @foreach ($employmentStatuses as $status)
                                    <option value="{{ $status }}" @selected(old('employment_status', $user->employment_status) === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                            @error('employment_status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="hired_at" class="form-label fw-semibold">Tanggal Mulai Bekerja</label>
                            <input type="date" name="hired_at" id="hired_at"
                                   value="{{ old('hired_at', $user->hired_at?->format('Y-m-d')) }}" class="form-control @error('hired_at') is-invalid @enderror">
                            @error('hired_at')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i> Simpan Perubahan
                        </button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Kembali</a>
                    </div>
                </form>

                @if ($user->id !== auth()->id())
                    <hr class="my-4">
                    <h6 class="fw-bold mb-1"><i class="bi bi-key me-1 text-warning"></i>Reset Kata Sandi</h6>
                    <p class="text-muted small mb-3">
                        Kata sandi baru langsung di-hash, tidak pernah ditampilkan/dicatat, dan seluruh sesi aktif akun ini diakhiri.
                    </p>
                    <form action="{{ route('admin.users.reset-password', $user) }}" method="POST" class="row g-2 align-items-end">
                        @csrf
                        @method('PATCH')
                        <div class="col-md-5">
                            <label for="reset_password" class="form-label small fw-semibold">Kata Sandi Baru</label>
                            <input type="password" name="password" id="reset_password" minlength="8" required
                                   class="form-control @error('password') is-invalid @enderror" placeholder="Minimal 8 karakter">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-5">
                            <label for="reset_password_confirmation" class="form-label small fw-semibold">Konfirmasi</label>
                            <input type="password" name="password_confirmation" id="reset_password_confirmation" minlength="8" required class="form-control">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-warning fw-semibold"
                                    onclick="return confirm('Reset kata sandi akun ini? Sesi aktifnya akan diakhiri.');">
                                Reset
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
