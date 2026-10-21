<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Assistant - Agung ATK
    |--------------------------------------------------------------------------
    | Kunci API dibaca dari environment variable AI_API_KEY — JANGAN pernah
    | hard-code kunci di dalam kode. Jika kosong atau API gagal dihubungi,
    | AIService otomatis memakai fallback/mock berbasis data produk database
    | sehingga antarmuka tetap dapat diuji tanpa konfigurasi API.
    */

    'key' => env('AI_API_KEY'),

    // env kosong ('') dianggap tidak diisi sehingga fallback default tetap berlaku.
    'model' => env('AI_MODEL') ?: 'gpt-4o-mini',

    // Endpoint kompatibel OpenAI Chat Completions
    'url' => env('AI_URL') ?: 'https://api.openai.com/v1/chat/completions',

    'timeout' => (int) env('AI_TIMEOUT', 20),
];
