<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot API (Notifikasi & Chatbot)
    |--------------------------------------------------------------------------
    | Token bot & Chat ID WAJIB diisi melalui .env — jangan hard-code di sini.
    | Nomor telepon BUKAN Chat ID. Dapatkan Chat ID dengan mengirim pesan /start
    | ke bot lalu membuka https://api.telegram.org/bot<TOKEN>/getUpdates
    | (atau gunakan tombol bantuan pada halaman pengaturan notifikasi).
    |
    | enabled        : saklar integrasi Telegram (TELEGRAM_ENABLED).
    | bot_username   : username bot tanpa '@' (dari BotFather) — BUKAN token.
    | webhook_secret : secret_token untuk validasi header
    |                  X-Telegram-Bot-Api-Secret-Token pada webhook masuk.
    */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'chat_id' => env('TELEGRAM_BOT_CHAT_ID') ?: env('TELEGRAM_CHAT_ID'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'enabled' => (bool) env('TELEGRAM_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fonnte WhatsApp Gateway (Notifikasi & Chatbot) — Provider Utama
    |--------------------------------------------------------------------------
    | Dokumentasi resmi: https://docs.fonnte.com/
    | - POST {url}/send        : kirim pesan (header Authorization: TOKEN,
    |                            tanpa prefix "Bearer", body target+message).
    | - POST {url}/get-devices : status perangkat WhatsApp terhubung.
    |
    | token    : API token Fonnte (FONNTE_API_TOKEN) — HANYA di .env.
    | url      : FONNTE_API_URL (default https://api.fonnte.com).
    | timeout  : detik timeout HTTP (FONNTE_TIMEOUT, default 15).
    */
    'fonnte' => [
        'url' => env('FONNTE_API_URL', 'https://api.fonnte.com'),
        'token' => env('FONNTE_API_TOKEN'),
        'timeout' => (int) env('FONNTE_TIMEOUT', 15),
        // Secret di path webhook masuk (/webhooks/fonnte/{token}) — Fonnte
        // tidak menandatangani payload, sehingga verifikasi GAGAL-TERTUTUP:
        // bila kosong, seluruh request webhook Fonnte ditolak (403).
        'webhook_secret' => env('FONNTE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business Cloud API (Notifikasi & Chatbot) — Provider Cadangan
    |--------------------------------------------------------------------------
    | Semua kredensial diisi melalui .env. Pesan yang memerlukan template
    | harus memakai template yang sudah disetujui di Meta Business Suite.
    | message_mode: 'text' (percakapan bebas) atau 'template'.
    |
    | provider  : 'fonnte' (default produksi, lihat blok Fonnte di atas) atau
    |             'meta' (WhatsApp Business Cloud API). Mengontrol service
    |             mana yang dipakai WhatsAppNotificationService.
    | enabled     : saklar integrasi WhatsApp (WHATSAPP_ENABLED).
    | verify_token: token verifikasi webhook GET dari Meta (WHATSAPP_VERIFY_TOKEN).
    | app_secret  : app secret Meta untuk validasi header
    |               X-Hub-Signature-256 pada webhook masuk (disarankan).
    | admin_number: nomor tujuan admin — bisa juga lewat WHATSAPP_ADMIN_PHONE.
    | CATATAN: nomor pengirim Cloud API berasal dari Meta (Phone Number ID),
    | bukan nomor admin di bawah ini.
    */
    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'meta'),
        // WHATSAPP_ACCESS_TOKEN adalah nama resmi Meta; WHATSAPP_API_KEY
        // didukung sebagai alias untuk konfigurasi yang menyebutnya "API Key".
        'access_token' => env('WHATSAPP_ACCESS_TOKEN') ?: env('WHATSAPP_API_KEY'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        // WHATSAPP_CLOUD_API_VERSION (nama resmi Meta) didahulukan; WHATSAPP_API_VERSION
        // tetap didukung untuk kompatibilitas konfigurasi lama.
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION')
            ?: env('WHATSAPP_API_VERSION', 'v21.0'),
        'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '62'),
        'message_mode' => env('WHATSAPP_MESSAGE_MODE', 'text'),
        'template_name' => env('WHATSAPP_TEMPLATE_NAME'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'id'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'admin_number' => env('WHATSAPP_ADMIN_NUMBER') ?: env('WHATSAPP_ADMIN_PHONE'),
        'enabled' => (bool) env('WHATSAPP_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Visual Search ATK (Pencarian Produk Berbasis Gambar)
    |--------------------------------------------------------------------------
    | Pengenalan jenis barang ATK dari foto kamera/unggahan memakai indeks
    | fitur dataset gabungan (lihat perintah `php artisan atk:*`). Dataset &
    | indeks disimpan PRIVAT di storage/app — tidak pernah diekspos ke URL
    | publik.
    |
    | Ambang batas di bawah ini DITENTUKAN dari hasil evaluasi data test
    | (`php artisan atk:image-evaluate`), bukan angka sembarangan:
    |   - min_confidence : rerata kemiripan2 tetangga terdekat kelas terbaik
    |                      minimal agar hasil diterima.
    |   - min_margin     : selisih skor kelas terbaik vs runner-up minimal.
    |   - min_similarity : kemiripan kosinus tetangga terdekat manapun minimal.
    */
    'image_search' => [
        'dataset_path' => env('IMAGE_SEARCH_DATASET_PATH', storage_path('app/datasets/atk-image-search')),
        'min_confidence' => (float) env('IMAGE_SEARCH_MIN_CONFIDENCE', 0.55),
        'min_margin' => (float) env('IMAGE_SEARCH_MIN_MARGIN', 0.02),
        'min_similarity' => (float) env('IMAGE_SEARCH_MIN_SIMILARITY', 0.45),
        'max_results' => (int) env('IMAGE_SEARCH_MAX_RESULTS', 8),
        'max_upload_kb' => (int) env('IMAGE_SEARCH_MAX_UPLOAD_KB', 2048),

        /*
         * IntegrasI YOLOv8 → integrasi YOLOv8 (layanan inferensi Python terpisah — ai-service/server.py).
         *
         *   driver             : 'hybrid' = deteksi YOLO dulu; selama layanan
         *                         menjawab, keputusan (recognized / not_atk /
         *                         uncertain) dipercayakan ke YOLO. Bila layanan
         *                         mati ATAU balasan tidak valid, sistem otomatis
         *                         memakai mesin pengenalan bawaan (k-NN fitur GD)
         *                         sehingga fitur tidak pernah ikut mati.
         *                         'gd' = hanya mesin bawaan, tanpa panggilan HTTP.
         *   service_url        : POST {service_url}/detect (kosongkan = nonaktif).
         *   yolo_min_confidence: ambang keyakinan deteksi — nilai default WAJIB
         *                         mengikuti hasil sweep pada ai-service/models/metrics.json
         *                         (dihasilkan `python ai-service/evaluate.py` pada split test),
         *                         bukan angka tebakan.
         *   yolo_min_margin    : selisih skor kelas terbaik vs runner-up (kosisten
         *                         dengan aturan mesin bawaan).
         *
         *   Default saat ini = hasil sweep evaluasi split test (304 gambar,
         *   metrics.json): conf 0.80 → atk_precision 0.9065 @ coverage 0.8487
         *   (aturan pemilihan: presisi ≥ 0.90 dengan cakupan tertinggi).
         *   Perbarui keduanya bersamaan bila model/dataset dilatih ulang.
         */
        'driver' => (string) env('IMAGE_SEARCH_DRIVER', 'hybrid'),
        'service_url' => env('IMAGE_SEARCH_SERVICE_URL', 'http://127.0.0.1:8090'),
        'service_timeout' => (float) env('IMAGE_SEARCH_SERVICE_TIMEOUT', 10),
        'service_connect_timeout' => (float) env('IMAGE_SEARCH_SERVICE_CONNECT_TIMEOUT', 2),
        'yolo_min_confidence' => (float) env('IMAGE_SEARCH_YOLO_MIN_CONFIDENCE', 0.80),
        'yolo_min_margin' => (float) env('IMAGE_SEARCH_YOLO_MIN_MARGIN', 0.00),
    ],

];
