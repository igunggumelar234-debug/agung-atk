@extends('layouts.dashboard')

@section('title', 'Tambah Pengguna')
@section('page-title', 'Tambah Pengguna / Pegawai Baru')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="alert alert-info small">
            <i class="bi bi-shield-lock me-1"></i>
            Role ditentukan oleh Admin di formulir ini — pengguna tidak dapat memilih role sendiri.
            Akun Admin dibuat melalui seeding awal, bukan dari halaman ini.
        </div>

        <div class="card card-stat">
            <div class="card-body p-4">
                <form action="{{ route('admin.users.store') }}" method="POST">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror"
                                   placeholder="Nama pegawai/pengguna" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror"
                                   placeholder="nama@agungatk.com" required>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-semibold">No. Telepon</label>
                            <input type="text" name="phone" id="phone" value="{{ old('phone') }}" class="form-control @error('phone') is-invalid @enderror"
                                   placeholder="08xxxxxxxxxx">
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="role" class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->name }}" @selected(old('role') === $role->name)>{{ $role->label }}</option>
                                @endforeach
                            </select>
                            @error('role')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="employee_code" class="form-label fw-semibold">No. Pegawai <span class="text-secondary fw-normal">(opsional, dibuat otomatis)</span></label>
                            <input type="text" name="employee_code" id="employee_code" value="{{ old('employee_code') }}" class="form-control @error('employee_code') is-invalid @enderror"
                                   placeholder="AGT-0001 / KUR-0001" maxlength="30">
                            @error('employee_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="position" class="form-label fw-semibold">Jabatan</label>
                            <input type="text" name="position" id="position" value="{{ old('position') }}" class="form-control @error('position') is-invalid @enderror"
                                   placeholder="Contoh: Staf Operasional Toko" maxlength="100">
                            @error('position')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="employment_status" class="form-label fw-semibold">Status Kerja</label>
                            <select name="employment_status" id="employment_status" class="form-select @error('employment_status') is-invalid @enderror">
                                <option value="">- Pilih status -</option>
                                @foreach ($employmentStatuses as $status)
                                    <option value="{{ $status }}" @selected(old('employment_status') === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                            @error('employment_status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="hired_at" class="form-label fw-semibold">Tanggal Mulai Bekerja</label>
                            <input type="date" name="hired_at" id="hired_at" value="{{ old('hired_at') }}" class="form-control @error('hired_at') is-invalid @enderror">
                            @error('hired_at')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="password" class="form-label fw-semibold">Kata Sandi <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror"
                                   placeholder="Minimal 8 karakter" minlength="8" required>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-md-6">
                            <label for="password_confirmation" class="form-label fw-semibold">Konfirmasi Kata Sandi <span class="text-danger">*</span></label>
                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control"
                                   minlength="8" required>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-save me-1"></i> Simpan Pengguna
                        </button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
