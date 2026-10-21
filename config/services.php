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
    | Telegram Bot API (Notifikasi)
    |--------------------------------------------------------------------------
    | Token bot & Chat ID WAJIB diisi melalui .env — jangan hard-code di sini.
    | Nomor telepon BUKAN Chat ID. Dapatkan Chat ID dengan mengirim pesan /start
    | ke bot lalu membuka https://api.telegram.org/bot<TOKEN>/getUpdates
    | (atau gunakan tombol bantuan pada halaman pengaturan notifikasi).
    */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business Cloud API (Notifikasi)
    |--------------------------------------------------------------------------
    | Semua kredensial diisi melalui .env. Pesan yang memerlukan template
    | harus memakai template yang sudah disetujui di Meta Business Suite.
    | message_mode: 'text' (percakapan bebas) atau 'template'.
    */
    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'message_mode' => env('WHATSAPP_MESSAGE_MODE', 'text'),
        'template_name' => env('WHATSAPP_TEMPLATE_NAME'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'id'),
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
    ],

];
