@extends('layouts.dashboard')

@section('title', 'Profil Saya')
@section('page-title', 'Profil & Keamanan Akun')

@section('content')
<div class="row g-4">
    {{-- Kartu identitas akun --}}
    <div class="col-12 col-lg-4">
        <div class="card card-custom">
            <div class="card-body text-center">
                <div class="rounded-circle bg-primary-atk text-white d-inline-flex align-items-center justify-content-center mb-3"
                     style="width: 72px; height: 72px;">
                    <i class="bi bi-person fs-2"></i>
                </div>
                <h5 class="fw-bold mb-1">{{ $user->name }}</h5>
                <div class="text-muted small mb-2">{{ $user->email }}</div>
                <span class="badge role-badge-{{ $user->role?->name }} px-2 py-1">
                    {{ $user->role?->label ?? ucfirst($user->role?->name) }}
                </span>

                <hr>
                <div class="text-start small">
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Nomor Telepon</span>
                        <span class="fw-semibold">{{ $user->phone ?: '-' }}</span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Bergabung</span>
                        <span class="fw-semibold">{{ $user->created_at->translatedFormat('d M Y') }}</span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Status Akun</span>
                        @if ($user->is_active)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Aktif</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Nonaktif</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        {{-- Ubah profil --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-person-gear text-primary me-1"></i> Ubah Profil</div>
            <div class="card-body">
                <form action="{{ route('profile.update') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="name" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $user->name) }}" required maxlength="255">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="phone" class="form-label">Nomor Telepon</label>
                            <input type="text" name="phone" id="phone"
                                   class="form-control @error('phone') is-invalid @enderror"
                                   value="{{ old('phone', $user->phone) }}" maxlength="20"
                                   placeholder="08xxxxxxxxxx">
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="profile-email">Email (Login)</label>
                            <input id="profile-email" type="email" class="form-control" value="{{ $user->email }}" disabled>
                            <div class="form-text">Email digunakan untuk masuk dan tidak dapat diubah.</div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary-atk">
                            <i class="bi bi-check2-circle me-1"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Ganti password --}}
        <div class="card card-custom mb-4">
            <div class="card-header"><i class="bi bi-shield-lock text-primary me-1"></i> Ganti Password</div>
            <div class="card-body">
                <form action="{{ route('profile.password') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="current_password" class="form-label">Password Saat Ini <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" id="current_password"
                                   class="form-control @error('current_password') is-invalid @enderror"
                                   required autocomplete="current-password">
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="password" class="form-label">Password Baru <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   required autocomplete="new-password">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Minimal 8 karakter.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="password_confirmation" class="form-label">Ulangi Password Baru <span class="text-danger">*</span></label>
                            <input type="password" name="password_confirmation" id="password_confirmation"
                                   class="form-control" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary-atk">
                            <i class="bi bi-key me-1"></i> Ubah Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Integrasi chatbot WhatsApp & Telegram (tautan via kode verifikasi) --}}
        <div class="card card-custom">
            <div class="card-header"><i class="bi bi-chat-dots text-primary me-1"></i> Integrasi Chatbot (WhatsApp & Telegram)</div>
            <div class="card-body">
                @if (session('channel_status'))
                    <div class="alert alert-success py-2 small mb-3">{{ session('channel_status') }}</div>
                @endif

                <div class="row g-3">
                    @foreach ([
                        'whatsapp' => [
                            'label' => 'WhatsApp',
                            'icon' => 'bi-whatsapp',
                            'hint' => 'lewat pesan WhatsApp ke nomor bisnis resmi toko (nomor pengirim Cloud API)',
                        ],
                        'telegram' => [
                            'label' => 'Telegram',
                            'icon' => 'bi-telegram',
                            'hint' => 'ke bot Telegram toko',
                        ],
                    ] as $channelKey => $channelInfo)
                        @php($linked = $linkedChannels[$channelKey] ?? null)
                        @php($pending = $pendingChannels[$channelKey] ?? null)
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">
                                        <i class="bi {{ $channelInfo['icon'] }} me-1"></i> {{ $channelInfo['label'] }}
                                    </span>
                                    @if ($linked)
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Terhubung</span>
                                    @elseif ($pending)
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Menunggu Verifikasi</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Belum Terhubung</span>
                                    @endif
                                </div>

                                @if ($linked)
                                    <div class="small text-muted mb-2">
                                        Terhubung ke <span class="fw-semibold text-dark">{{ $linked->identifier }}</span>
                                        @if ($linked->verified_at)
                                            <br>sejak {{ $linked->verified_at->translatedFormat('d M Y H:i') }}
                                        @endif
                                        @if ($linked->last_message_at)
                                            <br>pesan terakhir {{ $linked->last_message_at->translatedFormat('d M Y H:i') }}
                                        @endif
                                    </div>
                                    <form action="{{ route('profile.channel.unlink', ['channel' => $channelKey]) }}"
                                          method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                onclick="return confirm('Cabut tautan {{ $channelInfo['label'] }}? Chatbot tidak akan lagi mengakses data akun Anda lewat kanal ini.');">
                                            <i class="bi bi-unlink me-1"></i> Cabut Tautan
                                        </button>
                                    </form>
                                @else
                                    <div class="small text-muted mb-2">
                                        @if ($pending)
                                            Kode verifikasi: <span class="fw-bold text-dark">{{ $pending->verification_code }}</span>
                                            <br>Berlaku sampai {{ $pending->code_expires_at->translatedFormat('d M Y H:i') }}.
                                            <br>Kirim kode ini {{ $channelInfo['hint'] }}.
                                        @else
                                            Hubungkan akun agar chatbot dapat menjawab pertanyaan tentang data Anda
                                            (pesanan, stok, pembayaran) sesuai hak akses.
                                            <br>Kirim kode {{ $channelInfo['hint'] }} setelah kode dibuat.
                                        @endif
                                    </div>
                                    <form action="{{ route('profile.channel.code', ['channel' => $channelKey]) }}"
                                          method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-primary-atk btn-sm">
                                            <i class="bi bi-key me-1"></i>
                                            {{ $pending ? 'Buat Kode Baru' : 'Buat Kode Verifikasi' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="form-text mt-3">
                    Tautan memerlukan kode verifikasi dari halaman ini — bukan sekadar username atau nomor.
                    Data akun hanya ditampilkan ke identitas kanal yang sudah terverifikasi.
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
