@extends('layouts.dashboard')

@section('title', 'Notifikasi')
@section('page-title', 'Pengaturan Notifikasi')

@section('content')
{{-- Status global & mode --}}
@if (! $globalEnabled)
<div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-pause-circle-fill mt-1"></i>
    <div>
        Notifikasi <strong>dinonaktifkan secara global</strong> melalui <code>NOTIFICATIONS_ENABLED=false</code> pada <code>.env</code>.
        Ubah menjadi <code>true</code> lalu jalankan <code>php artisan config:clear</code> untuk mengaktifkan kembali.
    </div>
</div>
@endif

@if ($mockMode)
<div class="alert alert-info d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-flask mt-1"></i>
    <div>
        <strong>Mode mock aktif</strong> (<code>NOTIFICATIONS_MOCK=true</code>): pesan dicatat sebagai terkirim
        <u>tanpa memanggil API luar</u>. Matikan untuk pengiriman sebenarnya.
    </div>
</div>
@endif

{{-- 1. Status konfigurasi --}}
<div class="card card-custom mb-4">
    <div class="card-header">
        <i class="bi bi-shield-check text-primary me-1"></i> Status Konfigurasi Saluran
    </div>
    <div class="row g-0">
        @foreach ($channels as $channel => $meta)
        <div class="col-12 col-md-4 border-end-md">
            <div class="p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="fw-semibold">
                        <i class="bi {{ $meta['icon'] }} me-1 text-primary"></i>{{ $meta['label'] }}
                    </span>
                    @if ($statuses[$channel]['configured'])
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Terkonfigurasi</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Belum lengkap</span>
                    @endif
                </div>

                <div class="mb-2">
                    @if ($channelStates[$channel])
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Saluran aktif</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Saluran nonaktif</span>
                    @endif
                    @if ($statuses[$channel]['mode'] !== null)
                        <span class="badge bg-light text-dark border">mailer: {{ $statuses[$channel]['mode'] }}</span>
                    @endif
                </div>

                <div class="small text-body-secondary mb-1">
                    Penerima uji:
                    <span class="fw-semibold text-body">{{ $defaultRecipients[$channel] ?: 'belum diatur' }}</span>
                </div>

                @if (! $statuses[$channel]['configured'])
                <div class="small">
                    <span class="text-body-secondary">Variabel <code>.env</code> yang belum diisi:</span>
                    @foreach ($statuses[$channel]['missing'] as $envVar)
                        <code class="d-inline-block mt-1">{{ $envVar }}</code>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- Status integrasi chatbot WhatsApp & Telegram (webhook masuk + uji koneksi) --}}
<div class="card card-custom mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-chat-dots text-primary me-1"></i> Integrasi Chatbot (WhatsApp &amp; Telegram)</span>
        <form method="POST" action="{{ route('admin.notifications.connections') }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-plug me-1"></i> Uji Koneksi
            </button>
        </form>
    </div>
    <div class="row g-0">
        {{-- WhatsApp Cloud API --}}
        <div class="col-12 col-md-6 border-end-md">
            <div class="p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="fw-semibold"><i class="bi bi-whatsapp me-1 text-primary"></i> WhatsApp Cloud API</span>
                    @if (! $integration['whatsapp']['enabled'])
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Nonaktif (WHATSAPP_ENABLED=false)</span>
                    @elseif ($integration['whatsapp']['configured'])
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Terkonfigurasi</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Belum Lengkap</span>
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Status konfigurasi:</span>
                    <span class="fw-semibold {{ $integration['whatsapp']['configured'] ? 'text-success' : 'text-warning-emphasis' }}">
                        {{ $integration['whatsapp']['configured'] ? 'Terkonfigurasi' : 'Belum Lengkap' }}
                    </span>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Status kredensial:</span>
                    <span class="fw-semibold {{ $integration['whatsapp']['configured'] ? 'text-success' : 'text-danger' }}">
                        {{ $integration['whatsapp']['configured'] ? 'Tersedia' : 'Belum Tersedia' }}
                    </span>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Status koneksi API:</span>
                    @if ($integration['whatsapp']['test'] === null)
                        <span class="text-body-secondary">belum diuji</span>
                    @elseif ($integration['whatsapp']['test']['ok'])
                        <span class="text-success fw-semibold">Berhasil</span>
                        — nomor {{ $integration['whatsapp']['test']['display_phone'] ?? '-' }}
                    @else
                        <span class="text-danger fw-semibold">Gagal</span>
                        — {{ $integration['whatsapp']['test']['error'] ?? 'tidak diketahui' }}
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Nomor penerima:</span>
                    <span class="fw-semibold">{{ $integration['whatsapp']['admin_number'] !== '' ? $integration['whatsapp']['admin_number'] : '-' }}</span>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Mode pesan:</span>
                    <span class="fw-semibold">{{ $integration['whatsapp']['message_mode'] }}</span>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Pengiriman pesan terakhir:</span>
                    @if ($integration['whatsapp']['last_sent'] === null)
                        <span class="text-body-secondary">belum pernah</span>
                    @else
                        @php($lastSent = $integration['whatsapp']['last_sent'])
                        <span class="fw-semibold {{ $lastSent['status'] === 'sent' ? 'text-success' : ($lastSent['status'] === 'failed' ? 'text-danger' : 'text-body-secondary') }}">
                            {{ $lastSent['status'] === 'sent' ? 'Terkirim' : ($lastSent['status'] === 'failed' ? 'Gagal' : ucfirst($lastSent['status'])) }}
                        </span>
                        — {{ \Illuminate\Support\Carbon::parse($lastSent['at'])->format('d M Y H:i') }}
                        @if ($lastSent['error'] !== null)
                            <div class="text-danger">{{ $lastSent['error'] }}</div>
                        @endif
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Status pengiriman pesan (callback Meta):</span>
                    @if ($integration['whatsapp']['last_delivery'] === null)
                        <span class="text-body-secondary">belum ada callback</span>
                    @else
                        @php($delivery = $integration['whatsapp']['last_delivery'])
                        <span class="fw-semibold {{ ($delivery['status'] ?? '') === 'failed' ? 'text-danger' : 'text-success' }}">
                            {{ strtoupper((string) ($delivery['status'] ?? '-')) }}
                        </span>
                        @if (! empty($delivery['at']))
                            — {{ \Illuminate\Support\Carbon::parse($delivery['at'])->format('d M Y H:i') }}
                        @endif
                        @if (! empty($delivery['error']))
                            <div class="text-danger">{{ $delivery['error'] }}</div>
                        @endif
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Webhook masuk:</span>
                    <code>{{ $integration['whatsapp']['webhook_url'] }}</code>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Pesan webhook terakhir:</span>
                    {{ $integration['whatsapp']['last_webhook_at'] !== null
                        ? \Illuminate\Support\Carbon::parse($integration['whatsapp']['last_webhook_at'])->format('d M Y H:i')
                        : 'belum pernah' }}
                </div>
                @if (! $integration['whatsapp']['configured'])
                    <div class="small mt-1">
                        <span class="text-body-secondary">Variabel <code>.env</code> yang belum diisi:</span>
                        @foreach ($integration['whatsapp']['missing'] as $envVar)
                            <code class="d-inline-block me-1">{{ $envVar }}</code>
                        @endforeach
                    </div>
                @endif
                <div class="mt-2">
                    <button type="button" id="wa-api-status-btn" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-activity me-1"></i> Periksa Status API
                    </button>
                </div>
                <div id="wa-api-status-result" class="small mt-2" style="display: none;"></div>
            </div>
        </div>

        {{-- Telegram Bot API --}}
        <div class="col-12 col-md-6">
            <div class="p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="fw-semibold"><i class="bi bi-telegram me-1 text-primary"></i> Telegram Bot API</span>
                    @if (! $integration['telegram']['enabled'])
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Nonaktif (TELEGRAM_ENABLED=false)</span>
                    @elseif ($integration['telegram']['configured'])
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Siap</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Belum Lengkap</span>
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Username bot:</span>
                    <span class="fw-semibold">{{ $integration['telegram']['bot_username'] ?? 'belum diatur (TELEGRAM_BOT_USERNAME)' }}</span>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Chat ID admin:</span>
                    <span class="fw-semibold">{{ $integration['telegram']['chat_id'] ?: '-' }}</span>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Secret webhook:</span>
                    @if ($integration['telegram']['webhook_secret_set'])
                        <span class="fw-semibold">terisi</span>
                    @else
                        <span class="text-warning fw-semibold">belum (wajib untuk webhook masuk)</span>
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Webhook masuk:</span>
                    <code>{{ $integration['telegram']['webhook_url'] }}</code>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Pesan webhook terakhir:</span>
                    {{ $integration['telegram']['last_webhook_at'] !== null
                        ? \Illuminate\Support\Carbon::parse($integration['telegram']['last_webhook_at'])->format('d M Y H:i')
                        : 'belum pernah' }}
                </div>
                @if ($integration['telegram']['test'] !== null)
                    <div class="small">
                        <span class="text-body-secondary">Uji koneksi:</span>
                        @if ($integration['telegram']['test']['ok'])
                            <span class="text-success fw-semibold">berhasil</span>
                            — {{ $integration['telegram']['test']['username'] ?? '?' }}
                            @if (($integration['telegram']['test']['username_matches'] ?? null) === false)
                                <span class="text-danger">(berbeda dari TELEGRAM_BOT_USERNAME)</span>
                            @endif
                            @if (array_key_exists('webhook', $integration['telegram']['test']))
                                <br>Status webhook:
                                @if ($integration['telegram']['test']['webhook']['url'] ?? null)
                                    terpasang, {{ $integration['telegram']['test']['webhook']['pending'] ?? 0 }} update antre
                                @else
                                    belum dipasang
                                @endif
                                @if (! empty($integration['telegram']['test']['webhook']['last_error']))
                                    — <span class="text-danger">{{ $integration['telegram']['test']['webhook']['last_error'] }}</span>
                                @endif
                            @endif
                        @else
                            <span class="text-danger fw-semibold">gagal</span>
                            — {{ $integration['telegram']['test']['error'] ?? 'tidak diketahui' }}
                        @endif
                    </div>
                @endif
                @if (! $integration['telegram']['configured'])
                    <div class="small mt-1">
                        <span class="text-body-secondary">Variabel <code>.env</code> yang belum diisi:</span>
                        @foreach ($integration['telegram']['missing'] as $envVar)
                            <code class="d-inline-block me-1">{{ $envVar }}</code>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
    <div class="card-footer small text-body-secondary">
        Token &amp; secret tidak pernah ditampilkan di halaman ini.
        Akun terhubung: <strong>{{ $integration['linked_accounts'] }}</strong>.
        @if ($integration['test_at'] !== null)
            Terakhir diuji {{ \Illuminate\Support\Carbon::parse($integration['test_at'])->format('d M Y H:i') }}.
        @endif
        @if ($integration['last_sent_at'] !== null)
            Pengiriman terakhir {{ \Illuminate\Support\Carbon::parse($integration['last_sent_at'])->format('d M Y H:i') }}.
        @endif
    </div>
</div>

{{-- Status sistem: pengantrean notifikasi & provider AI (tanpa rahasia) --}}
<div class="card card-custom mb-4">
    <div class="card-header">
        <i class="bi bi-activity text-primary me-1"></i> Status Sistem &amp; Provider AI
    </div>
    <div class="row g-0">
        {{-- Pengantrean --}}
        <div class="col-12 col-md-6 border-end-md">
            <div class="p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="fw-semibold"><i class="bi bi-hourglass-split me-1 text-primary"></i> Pengantrean Notifikasi</span>
                    @if ($system['queue_enabled'])
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Antrean aktif</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Kirim langsung</span>
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Koneksi antrean:</span>
                    <code>QUEUE_CONNECTION={{ $system['queue_connection'] }}</code>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Job gagal:</span>
                    @if ($system['failed_jobs'] > 0)
                        <span class="text-danger fw-semibold">{{ $system['failed_jobs'] }}</span>
                        — periksa <code>php artisan queue:failed</code>
                    @else
                        <span class="text-success fw-semibold">tidak ada</span>
                    @endif
                </div>
                @if ($system['queue_enabled'])
                <div class="small text-body-secondary">
                    Worker harus berjalan agar pesan terkirim:
                    <code>php artisan queue:work</code>. Kegagalan job memberi
                    tahu admin via kejadian <code>system.issue</code>.
                </div>
                @else
                <div class="small text-body-secondary">
                    Notifikasi dikirim langsung dalam request (tanpa antrean).
                </div>
                @endif
            </div>
        </div>

        {{-- Provider AI --}}
        <div class="col-12 col-md-6">
            <div class="p-3 h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="fw-semibold"><i class="bi bi-robot me-1 text-primary"></i> Provider AI Chatbot</span>
                    @if ($system['ai_key_set'])
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Kunci terisi</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Belum dikonfigurasi</span>
                    @endif
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Endpoint:</span>
                    <code>{{ $system['ai_url'] !== '' ? $system['ai_url'] : 'belum diatur (AI_URL)' }}</code>
                </div>
                <div class="small mb-1">
                    <span class="text-body-secondary">Model:</span>
                    <span class="fw-semibold">{{ $system['ai_model'] !== '' ? $system['ai_model'] : 'belum diatur (AI_MODEL)' }}</span>
                </div>
                <div class="small">
                    <span class="text-body-secondary">Percobaan terakhir:</span>
                    @if ($system['ai_last'] !== null)
                        @if ($system['ai_last']['ok'])
                            <span class="text-success fw-semibold">berhasil</span>
                            (HTTP {{ $system['ai_last']['status'] ?? '-' }})
                        @else
                            <span class="text-danger fw-semibold">gagal</span>
                            @if (! empty($system['ai_last']['status']))
                                (HTTP {{ $system['ai_last']['status'] }})
                            @elseif (! empty($system['ai_last']['reason']))
                                — {{ $system['ai_last']['reason'] }}
                            @endif
                        @endif
                        pada {{ \Illuminate\Support\Carbon::parse($system['ai_last']['at'])->format('d M Y H:i') }}
                    @else
                        <span class="text-body-secondary">belum pernah (kirim pesan chatbot untuk mencoba)</span>
                    @endif
                </div>
                @if (! $system['ai_key_set'])
                <div class="small text-body-secondary mt-1">
                    Tanpa <code>AI_API_KEY</code> chatbot tetap menjawab memakai
                    pengetahuan lokal (mode fallback).
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- 2. Pengaturan saluran & kejadian --}}
    <div class="col-12 col-xl-7">
        <div class="card card-custom h-100">
            <div class="card-header">
                <i class="bi bi-sliders text-primary me-1"></i> Saluran &amp; Kejadian Notifikasi
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.notifications.settings') }}">
                    @csrf
                    {{-- Penanda form terkirim meski semua kotak tidak dicentang --}}
                    <input type="hidden" name="channels[__sentinel]" value="1">
                    <input type="hidden" name="events[__sentinel]" value="1">

                    <div class="row g-2 mb-3">
                        @foreach ($channels as $channel => $meta)
                        <div class="col-12 col-md-4">
                            <div class="border rounded p-2 h-100 bg-light">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="channel-{{ $channel }}" name="channels[{{ $channel }}]" value="1"
                                           @checked($channelStates[$channel])>
                                    <label class="form-check-label fw-semibold" for="channel-{{ $channel }}">
                                        <i class="bi {{ $meta['icon'] }} me-1"></i>{{ $meta['label'] }}
                                    </label>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Kejadian</th>
                                    <th class="text-center">Penerima</th>
                                    <th class="text-end">Aktif</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($events as $eventKey => $event)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $event['label'] }}</div>
                                        <code class="small text-body-secondary">{{ $eventKey }}</code>
                                    </td>
                                    <td class="text-center">
                                        @foreach ($event['audiences'] as $audience)
                                            <span class="badge bg-light text-dark border">{{ $audiences[$audience] ?? $audience }}</span>
                                        @endforeach
                                    </td>
                                    <td class="text-end">
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="event-{{ $eventKey }}" name="events[{{ $eventKey }}]" value="1"
                                                   @checked($eventStates[$eventKey])>
                                            <label class="visually-hidden" for="event-{{ $eventKey }}">
                                                Aktifkan {{ $event['label'] }}
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary-atk">
                            <i class="bi bi-check2-circle me-1"></i> Simpan Pengaturan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        {{-- 3. Bantuan Telegram --}}
        <div class="card card-custom mb-4">
            <div class="card-header">
                <i class="bi bi-telegram text-primary me-1"></i> Hubungkan Akun Telegram
            </div>
            <div class="card-body">
                @if ($telegramChatId)
                    <div class="alert alert-success py-2 small mb-3">
                        Chat ID aktif: <strong>{{ $telegramChatId }}</strong>
                    </div>
                @else
                    <div class="alert alert-warning py-2 small mb-3">
                        <code>TELEGRAM_ADMIN_CHAT_ID</code> belum diisi. <strong>Nomor telepon bukan Chat ID</strong>
                        &mdash; ikuti langkah berikut.
                    </div>
                @endif

                <ol class="small mb-3 ps-3">
                    <li>Buka Telegram, cari bot <strong>@BotFather</strong>, kirim <code>/newbot</code>, lalu ikuti instruksinya.</li>
                    <li>Salin token bot ke <code>.env</code> sebagai <code>TELEGRAM_BOT_TOKEN=...</code> (jangan di-commit).</li>
                    <li>Buka bot Anda dan kirim pesan <code>/start</code>.</li>
                    <li>Klik tombol <strong>Ambil Chat ID</strong> di bawah (atau buka
                        <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code>).</li>
                    <li>Isi <code>TELEGRAM_ADMIN_CHAT_ID=&lt;angka&gt;</code> di <code>.env</code>, lalu
                        <code>php artisan config:clear</code>.</li>
                </ol>

                <form method="POST" action="{{ route('admin.notifications.telegram.chat-id') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-search me-1"></i> Ambil Chat ID dari Pesan Terbaru
                    </button>
                </form>
            </div>
        </div>

        {{-- 4. Uji kirim --}}
        <div class="card card-custom">
            <div class="card-header">
                <i class="bi bi-send-check text-primary me-1"></i> Kirim Pesan Uji
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.notifications.test') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="test-channel" class="form-label">Saluran</label>
                        <select name="channel" id="test-channel" class="form-select" required>
                            @foreach ($channels as $channel => $meta)
                                <option value="{{ $channel }}" @selected(old('channel') === $channel)>
                                    {{ $meta['label'] }}{{ $statuses[$channel]['configured'] ? '' : ' (belum dikonfigurasi)' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="test-recipient" class="form-label">Tujuan (opsional)</label>
                        <input type="text" name="recipient" id="test-recipient" class="form-control"
                               value="{{ old('recipient') }}" maxlength="190"
                               placeholder="Kosongkan untuk memakai penerima admin">
                        <div class="form-text">Email, nomor WhatsApp (contoh: 089697358716), atau Chat ID Telegram.</div>
                    </div>
                    <div class="mb-3">
                        <label for="test-message" class="form-label">Pesan (opsional)</label>
                        <textarea name="message" id="test-message" class="form-control" rows="2"
                                  maxlength="500" placeholder="Pesan uji dari Agung ATK...">{{ old('message') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary-atk">
                        <i class="bi bi-send me-1"></i> Kirim Uji
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- 5. Riwayat pengiriman --}}
<div class="card card-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history text-primary me-1"></i> Riwayat Pengiriman</span>
        <span class="badge bg-light text-dark border">{{ $logs->total() }} catatan</span>
    </div>

    @if ($queueEnabled)
    <div class="px-3 pt-3">
        <div class="alert alert-info py-2 small mb-0">
            <i class="bi bi-info-circle-fill me-1"></i>
            Pengiriman berjalan di latar belakang melalui antrean: status <strong>pending</strong> akan berubah
            setelah worker dijalankan (<code>php artisan queue:work</code>). Atau set
            <code>NOTIFICATIONS_QUEUE=false</code> untuk pengiriman langsung.
        </div>
    </div>
    @endif

    <div class="card-body">
        <form method="GET" action="{{ route('admin.notifications.index') }}" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label for="filter-date" class="form-label small mb-1">Tanggal</label>
                <input type="date" name="date" id="filter-date" class="form-control form-control-sm"
                       value="{{ $filters['date'] }}" max="{{ now()->toDateString() }}">
            </div>
            <div class="col-6 col-md-3">
                <label for="filter-channel" class="form-label small mb-1">Saluran</label>
                <select name="channel" id="filter-channel" class="form-select form-select-sm">
                    <option value="all">Semua Saluran</option>
                    @foreach ($channels as $channelKey => $channelMeta)
                        <option value="{{ $channelKey }}" @selected($filters['channel'] === $channelKey)>
                            {{ $channelMeta['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="filter-status" class="form-label small mb-1">Status</label>
                <select name="status" id="filter-status" class="form-select form-select-sm">
                    <option value="all">Semua Status</option>
                    <option value="sent" @selected($filters['status'] === 'sent')>Terkirim</option>
                    <option value="failed" @selected($filters['status'] === 'failed')>Gagal</option>
                    <option value="pending" @selected($filters['status'] === 'pending')>Pending</option>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary-atk flex-fill">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                @if ($hasActiveFilter)
                    <a href="{{ route('admin.notifications.index') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Kejadian</th>
                    <th>Saluran</th>
                    <th>Penerima</th>
                    <th>Status</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                <tr>
                    <td class="small text-nowrap">{{ $log->created_at?->format('d M Y H:i') }}</td>
                    <td>
                        <div class="small fw-semibold">{{ $eventLabels[$log->event_key] ?? strtoupper($log->event_key) }}</div>
                        <code class="small text-body-secondary">{{ $log->event_key }}</code>
                        @if ($log->order)
                            <div class="small"><a href="{{ route('admin.orders.show', $log->order) }}">#{{ $log->order->order_number }}</a></div>
                        @endif
                    </td>
                    <td class="small text-nowrap">{{ ucfirst($log->channel) }}</td>
                    <td class="small">{{ \App\Http\Controllers\Admin\NotificationController::maskRecipient($log->recipient) }}</td>
                    <td>
                        @if ($log->status === \App\Models\NotificationLog::STATUS_SENT)
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Terkirim</span>
                        @elseif ($log->status === \App\Models\NotificationLog::STATUS_FAILED)
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Gagal</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Pending</span>
                        @endif
                    </td>
                    <td class="small text-body-secondary">
                        @if ($log->error)
                            <span class="text-danger">{{ \Illuminate\Support\Str::limit($log->error, 120) }}</span>
                        @elseif ($log->sent_at)
                            Dikirim {{ $log->sent_at->format('d M Y H:i') }}
                        @else
                            &mdash;
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6">
                        <div class="empty-state py-4">
                            <i class="bi bi-bell-slash empty-state-icon"></i>
                            <div class="empty-state-title">Tidak ada notifikasi yang cocok</div>
                            <p class="empty-state-text">
                                @if ($hasActiveFilter)
                                    Tidak ada catatan dengan filter ini.
                                    <a href="{{ route('admin.notifications.index') }}">Hapus filter</a> untuk melihat semua.
                                @else
                                    Notifikasi akan muncul di sini setelah terjadi kejadian (pesanan, pembayaran, stok,
                                    pengiriman) atau setelah Anda mengirim pesan uji.
                                @endif
                            </p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">
    {{ $logs->links() }}
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const btn = document.getElementById('wa-api-status-btn');
        const out = document.getElementById('wa-api-status-result');

        if (!btn || !out) {
            return;
        }

        const esc = (value) => String(value === null || value === undefined ? '-' : value)
            .replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        const when = (iso) => {
            if (!iso) {
                return '-';
            }
            const d = new Date(iso);
            return isNaN(d.getTime()) ? esc(iso) : d.toLocaleString('id-ID');
        };

        btn.addEventListener('click', async function () {
            btn.disabled = true;
            out.style.display = 'block';
            out.innerHTML = '<span class="text-body-secondary">Memeriksa status API WhatsApp…</span>';

            try {
                const response = await fetch(@json(route('admin.notifications.whatsapp.status')), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json().catch(() => ({}));

                if (response.status === 429) {
                    throw new Error('Terlalu banyak permintaan — coba lagi dalam satu menit.');
                }

                if (!response.ok) {
                    throw new Error(data.message || 'Permintaan gagal diterima server.');
                }

                const conn = data.connection || {};
                const lastSent = data.last_sent;
                const delivery = data.last_delivery;
                const connHtml = conn.ok
                    ? '<span class="text-success fw-semibold">Berhasil</span> — nomor ' + esc(conn.display_phone || '-')
                    : '<span class="text-danger fw-semibold">Gagal</span> — ' + esc(conn.error || 'tidak diketahui');
                const sentHtml = !lastSent
                    ? 'belum pernah'
                    : '<span class="fw-semibold ' + (lastSent.status === 'sent' ? 'text-success' : 'text-danger') + '">'
                        + esc(lastSent.status === 'sent' ? 'Terkirim' : (lastSent.status === 'failed' ? 'Gagal' : lastSent.status))
                        + '</span> — ' + when(lastSent.at)
                        + (lastSent.error ? ' <span class="text-danger">' + esc(lastSent.error) + '</span>' : '');
                const deliveryHtml = !delivery
                    ? 'belum ada callback'
                    : '<span class="fw-semibold ' + (delivery.status === 'failed' ? 'text-danger' : 'text-success') + '">'
                        + esc(String(delivery.status || '-').toUpperCase()) + '</span> — ' + when(delivery.at)
                        + (delivery.error ? ' <span class="text-danger">' + esc(delivery.error) + '</span>' : '');

                out.innerHTML =
                    '<div><span class="text-body-secondary">Status kredensial:</span> <span class="fw-semibold '
                    + (data.configured ? 'text-success' : 'text-danger') + '">'
                    + (data.configured ? 'Tersedia' : 'Belum Tersedia (' + esc((data.missing || []).join(', ')) + ')')
                    + '</span></div>'
                    + '<div><span class="text-body-secondary">Saklar integrasi:</span> <span class="fw-semibold">'
                    + (data.enabled ? 'aktif' : 'nonaktif') + '</span></div>'
                    + '<div><span class="text-body-secondary">Nomor penerima:</span> <span class="fw-semibold">'
                    + esc(data.admin_number || '-') + '</span></div>'
                    + '<div><span class="text-body-secondary">Koneksi API:</span> ' + connHtml + '</div>'
                    + '<div><span class="text-body-secondary">Pengiriman terakhir:</span> ' + sentHtml + '</div>'
                    + '<div><span class="text-body-secondary">Status pengiriman (Meta):</span> ' + deliveryHtml + '</div>'
                    + '<div><span class="text-body-secondary">Webhook terakhir:</span> ' + when(data.last_webhook_at) + '</div>'
                    + '<div class="text-body-secondary">Diperiksa pada ' + when(data.checked_at) + '.</div>';
            } catch (error) {
                out.innerHTML = '<div class="text-danger">' + esc(error.message || 'Terjadi kesalahan.') + '</div>';
            } finally {
                btn.disabled = false;
            }
        });
    })();
</script>
@endpush
