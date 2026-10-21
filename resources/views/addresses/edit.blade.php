@extends('layouts.dashboard')

@section('title', 'Ubah Alamat')
@section('page-title', 'Ubah Alamat: ' . $address->label)

@section('content')
<div class="mb-3">
    <a href="{{ route('addresses.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Alamat
    </a>
</div>

<div class="card card-custom">
    <div class="card-header"><i class="bi bi-geo-alt text-primary me-1"></i> Form Ubah Alamat</div>
    <div class="card-body">
        <form action="{{ route('addresses.update', $address) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="label" class="form-label">Label Alamat <span class="text-danger">*</span></label>
                    <input type="text" name="label" id="label" maxlength="50"
                           class="form-control @error('label') is-invalid @enderror"
                           value="{{ old('label', $address->label) }}" required>
                    @error('label')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6">
                    <label for="recipient_name" class="form-label">Nama Penerima <span class="text-danger">*</span></label>
                    <input type="text" name="recipient_name" id="recipient_name" maxlength="255"
                           class="form-control @error('recipient_name') is-invalid @enderror"
                           value="{{ old('recipient_name', $address->recipient_name) }}" required>
                    @error('recipient_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6">
                    <label for="phone" class="form-label">Nomor Telepon <span class="text-danger">*</span></label>
                    <input type="text" name="phone" id="phone" maxlength="20"
                           class="form-control @error('phone') is-invalid @enderror"
                           value="{{ old('phone', $address->phone) }}" placeholder="08xxxxxxxxxx" required>
                    @error('phone')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6">
                    <label for="postal_code" class="form-label">Kode Pos</label>
                    <input type="text" name="postal_code" id="postal_code" maxlength="10"
                           class="form-control @error('postal_code') is-invalid @enderror"
                           value="{{ old('postal_code', $address->postal_code) }}">
                    @error('postal_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12">
                    <label for="full_address" class="form-label">Alamat Lengkap <span class="text-danger">*</span></label>
                    <textarea name="full_address" id="full_address" rows="2" maxlength="1000"
                              class="form-control @error('full_address') is-invalid @enderror" required>{{ old('full_address', $address->full_address) }}</textarea>
                    @error('full_address')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6">
                    <label for="city" class="form-label">Kota / Kabupaten <span class="text-danger">*</span></label>
                    <input type="text" name="city" id="city" maxlength="100"
                           class="form-control @error('city') is-invalid @enderror"
                           value="{{ old('city', $address->city) }}" required>
                    @error('city')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12 col-md-6">
                    <label for="district" class="form-label">Kecamatan <span class="text-danger">*</span></label>
                    <input type="text" name="district" id="district" maxlength="100"
                           class="form-control @error('district') is-invalid @enderror"
                           value="{{ old('district', $address->district) }}" required>
                    @error('district')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-12">
                    <label for="notes" class="form-label">Catatan (Opsional)</label>
                    <textarea name="notes" id="notes" rows="2" maxlength="500"
                              class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $address->notes) }}</textarea>
                    @error('notes')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary-atk">
                    <i class="bi bi-check2-circle me-1"></i> Simpan Perubahan
                </button>
                <a href="{{ route('addresses.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
