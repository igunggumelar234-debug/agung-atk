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
                        <div class="small fw-semibold">{{ $events[$log->event_key]['label'] ?? strtoupper($log->event_key) }}</div>
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
