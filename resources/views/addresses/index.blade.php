@extends('layouts.dashboard')

@section('title', 'Alamat Pengiriman')
@section('page-title', 'Alamat Pengiriman')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-muted small mb-0">
        <i class="bi bi-geo-alt me-1"></i>Kelola alamat tujuan agar proses checkout lebih cepat.
    </p>
    <button class="btn btn-primary-atk btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#formTambah"
            aria-expanded="false" aria-controls="formTambah">
        <i class="bi bi-plus-lg me-1"></i> Tambah Alamat
    </button>
</div>

{{-- Form tambah alamat (collapse) --}}
<div class="collapse mb-4 {{ $errors->any() ? 'show' : '' }}" id="formTambah">
    <div class="card card-custom">
        <div class="card-header"><i class="bi bi-plus-square text-primary me-1"></i> Alamat Baru</div>
        <div class="card-body">
            <form action="{{ route('addresses.store') }}" method="POST">
                @csrf
                <input type="hidden" name="back" value="addresses">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="label" class="form-label">Label Alamat <span class="text-danger">*</span></label>
                        <input type="text" name="label" id="label" maxlength="50"
                               class="form-control @error('label') is-invalid @enderror"
                               value="{{ old('label') }}" placeholder="Rumah / Kantor / Kos" required>
                        @error('label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="recipient_name" class="form-label">Nama Penerima <span class="text-danger">*</span></label>
                        <input type="text" name="recipient_name" id="recipient_name" maxlength="255"
                               class="form-control @error('recipient_name') is-invalid @enderror"
                               value="{{ old('recipient_name', auth()->user()->name) }}" required>
                        @error('recipient_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="phone" class="form-label">Nomor Telepon <span class="text-danger">*</span></label>
                        <input type="text" name="phone" id="phone" maxlength="20"
                               class="form-control @error('phone') is-invalid @enderror"
                               value="{{ old('phone', auth()->user()->phone) }}" placeholder="08xxxxxxxxxx" required>
                        @error('phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="postal_code" class="form-label">Kode Pos</label>
                        <input type="text" name="postal_code" id="postal_code" maxlength="10"
                               class="form-control @error('postal_code') is-invalid @enderror"
                               value="{{ old('postal_code') }}" placeholder="45511">
                        @error('postal_code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <label for="full_address" class="form-label">Alamat Lengkap <span class="text-danger">*</span></label>
                        <textarea name="full_address" id="full_address" rows="2" maxlength="1000"
                                  class="form-control @error('full_address') is-invalid @enderror"
                                  placeholder="Nama jalan, nomor rumah, RT/RW, patokan" required>{{ old('full_address') }}</textarea>
                        @error('full_address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="city" class="form-label">Kota / Kabupaten <span class="text-danger">*</span></label>
                        <input type="text" name="city" id="city" maxlength="100"
                               class="form-control @error('city') is-invalid @enderror"
                               value="{{ old('city', 'Kuningan') }}" required>
                        @error('city')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="district" class="form-label">Kecamatan <span class="text-danger">*</span></label>
                        <input type="text" name="district" id="district" maxlength="100"
                               class="form-control @error('district') is-invalid @enderror"
                               value="{{ old('district') }}" placeholder="Contoh: Kuningan" required>
                        @error('district')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <label for="notes" class="form-label">Catatan (Opsional)</label>
                        <textarea name="notes" id="notes" rows="2" maxlength="500"
                                  class="form-control @error('notes') is-invalid @enderror"
                                  placeholder="Patokan rumah, jam terima, dll.">{{ old('notes') }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary-atk">
                        <i class="bi bi-plus-lg me-1"></i> Simpan Alamat
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#formTambah">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Daftar alamat --}}
@if ($addresses->isEmpty())
    <div class="card card-custom">
        <div class="card-body">
            <div class="empty-state">
                <i class="bi bi-geo-alt empty-state-icon"></i>
                <div class="empty-state-title">Belum ada alamat tersimpan</div>
                <p class="empty-state-text">Tambahkan alamat agar kamu tidak perlu mengisi ulang saat checkout.</p>
                <button class="btn btn-primary-atk mt-3" type="button" data-bs-toggle="collapse" data-bs-target="#formTambah">
                    <i class="bi bi-plus-lg me-1"></i> Tambah Alamat Pertama
                </button>
            </div>
        </div>
    </div>
@else
    <div class="row g-3">
        @foreach ($addresses as $address)
            <div class="col-12 col-md-6">
                <div class="card card-custom h-100 {{ $address->is_primary ? 'border-primary' : '' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div class="fw-bold">
                                {{ $address->label }}
                                @if ($address->is_primary)
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1">
                                        <i class="bi bi-star-fill me-1"></i>Utama
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="small mb-1">
                            <i class="bi bi-person me-1 text-muted"></i>{{ $address->recipient_name }}
                            &middot; {{ $address->phone }}
                        </div>
                        <div class="small text-muted mb-1">
                            {{ $address->full_address }}
                        </div>
                        <div class="small text-muted mb-1">
                            Kec. {{ $address->district }}, {{ $address->city }}
                            {{ $address->postal_code ? '&middot; Kode pos ' . $address->postal_code : '' }}
                        </div>
                        @if ($address->notes)
                            <div class="small text-muted fst-italic">
                                <i class="bi bi-chat-left-text me-1"></i>{{ $address->notes }}
                            </div>
                        @endif

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            @unless ($address->is_primary)
                                <form action="{{ route('addresses.primary', $address) }}" method="POST">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-star me-1"></i> Jadikan Utama
                                    </button>
                                </form>
                            @endunless
                            <a href="{{ route('addresses.edit', $address) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil me-1"></i> Ubah
                            </a>
                            <form action="{{ route('addresses.destroy', $address) }}" method="POST"
                                  onsubmit="return confirm({{ Illuminate\Support\Js::from('Hapus alamat "' . $address->label . '"? Tindakan ini tidak dapat dibatalkan.') }});">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash me-1"></i> Hapus
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
