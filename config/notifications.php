<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notifikasi Otomatis - Agung ATK
    |--------------------------------------------------------------------------
    | Notifikasi dikirim melalui Telegram, WhatsApp (Cloud API), dan Email.
    | Semua kredensial (token bot, API WhatsApp, SMTP) dibaca dari .env —
    | JANGAN PERNAH menaruh token atau kata sandi di dalam kode repository.
    |
    | Jika suatu saluran belum dikonfigurasi, sistem melewati saluran tersebut
    | dengan aman (tidak memanggil API luar) dan statusnya ditampilkan pada
    | halaman pengaturan notifikasi admin.
    */

    // Saklar global. false = seluruh notifikasi nonaktif
    // (dipakai pada phpunit.xml agar suite test tidak pernah mengirim apa pun).
    'enabled' => env('NOTIFICATIONS_ENABLED', true),

    // Mode mock/pengujian: true = tidak memanggil API luar, pengiriman dicatat
    // sebagai "sent" untuk demo. Default false (pengiriman sebenarnya).
    'mock' => env('NOTIFICATIONS_MOCK', false),

    // true = kirim melalui antrean Laravel Queue (butuh worker: php artisan queue:work).
    // false = kirim menyinkronkan setelah transaksi database commit.
    'queue' => env('NOTIFICATIONS_QUEUE', true),

    // Penerima notifikasi untuk audiens admin.
    // Default KOSONG — bila env tidak diisi, saluran email/WA dilewati dengan aman,
    // bukan mengirim ke kontak bawaan yang tertinggal di repository.
    // WHATSAPP_ADMIN_NUMBER (konvensi PROMPT 9) menjadi preferensi; WHATSAPP_ADMIN_PHONE
    // tetap didukung agar konfigurasi lama tidak berubah arti.
    'admin_email' => env('NOTIFICATION_ADMIN_EMAIL', ''),
    'admin_phone' => env('WHATSAPP_ADMIN_NUMBER') ?: env('WHATSAPP_ADMIN_PHONE', ''),

    // Jendela anti-duplikat global (menit): kejadian + saluran + penerima +
    // referensi yang sama tidak akan dikirim dua kali dalam jendela ini.
    'dedupe_window' => (int) env('NOTIFICATIONS_DEDUPE_WINDOW', 360),

    // Timeout HTTP untuk API Telegram/WhatsApp (detik).
    'http_timeout' => (int) env('NOTIFICATIONS_HTTP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Saluran Notifikasi
    |--------------------------------------------------------------------------
    */
    'channels' => [
        'telegram' => [
            'label' => 'Telegram',
            'icon' => 'bi-telegram',
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'icon' => 'bi-whatsapp',
        ],
        'email' => [
            'label' => 'Email',
            'icon' => 'bi-envelope-at',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audiens (penerima berdasarkan peran)
    |--------------------------------------------------------------------------
    | admin    -> kontak admin dari .env (email, nomor WhatsApp, Chat ID)
    | customer -> akun pelanggan terkait (email akun + nomor telepon)
    | seller   -> seluruh akun Anggota/Pegawai Toko aktif (email akun)
    | courier  -> kurir yang bertugas (email akun)
    */
    'audiences' => [
        'admin' => 'Administrator',
        'customer' => 'Pelanggan',
        'seller' => 'Anggota / Pegawai Toko',
        'courier' => 'Kurir',
    ],

    /*
    |--------------------------------------------------------------------------
    | Kejadian Pemicu Notifikasi
    |--------------------------------------------------------------------------
    | label    -> tampilan pada halaman pengaturan & riwayat
    | audiences -> peran yang menerima notifikasi untuk kejadian ini
    | window   -> (opsional) jendela anti-duplikat khusus dalam menit
    */
    'events' => [
        'order.new' => [
            'label' => 'Pesanan baru dari pelanggan',
            'audiences' => ['admin', 'seller'],
        ],
        'order.created' => [
            'label' => 'Pesanan berhasil dibuat',
            'audiences' => ['customer'],
        ],
        'payment.instructions' => [
            'label' => 'Instruksi pembayaran tersedia',
            'audiences' => ['customer'],
        ],
        'payment.proof_uploaded' => [
            'label' => 'Bukti pembayaran diunggah pelanggan',
            'audiences' => ['admin'],
            'window' => 60,
        ],
        'payment.verified' => [
            'label' => 'Pembayaran berhasil diverifikasi',
            'audiences' => ['admin', 'customer'],
        ],
        'payment.rejected' => [
            'label' => 'Pembayaran ditolak',
            'audiences' => ['admin', 'customer'],
        ],
        'order.paid' => [
            'label' => 'Pesanan sudah dibayar (perlu disiapkan penjual)',
            'audiences' => ['seller'],
        ],
        'order.processing' => [
            'label' => 'Pesanan sedang diproses',
            'audiences' => ['customer', 'seller'],
        ],
        'order.ready_to_ship' => [
            'label' => 'Pesanan siap dikirim',
            'audiences' => ['admin'],
        ],
        'order.cancelled' => [
            'label' => 'Pesanan dibatalkan',
            'audiences' => ['admin', 'customer'],
        ],
        'stock.low' => [
            'label' => 'Stok produk hampir habis',
            'audiences' => ['admin', 'seller'],
        ],
        'stock.out' => [
            'label' => 'Stok produk habis',
            'audiences' => ['admin'],
        ],
        'shipment.assigned' => [
            'label' => 'Penugasan pengiriman baru untuk kurir',
            'audiences' => ['courier', 'customer'],
        ],
        'shipment.status' => [
            'label' => 'Status pengiriman diperbarui',
            // 'courier' resolve ke kurir yang ditugaskan pada shipment ini saja
            // (lihat NotificationManager::recipients) — bukan seluruh kurir.
            'audiences' => ['customer', 'admin', 'courier'],
        ],
        'shipment.shipping' => [
            'label' => 'Pesanan sedang dikirim ke pelanggan',
            'audiences' => ['customer'],
        ],
        'shipment.delivered' => [
            'label' => 'Pesanan diterima pelanggan',
            'audiences' => ['admin', 'customer'],
        ],
        'account.registered' => [
            'label' => 'Pendaftaran akun berhasil',
            'audiences' => ['customer'],
        ],
        'report.daily' => [
            'label' => 'Ringkasan penjualan harian',
            'audiences' => ['admin'],
            'window' => 1440,
        ],
        'system.issue' => [
            'label' => 'Masalah sistem (pekerjaan antrean gagal)',
            'audiences' => ['admin'],
            // Jendela dedupe pendek: kegagalan beruntun tidak membanjiri admin.
            'window' => 60,
        ],
    ],
];
