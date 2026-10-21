@include('partials.error-page', [
    'errorCode' => 429,
    'errorTitle' => 'Terlalu Banyak Permintaan',
    'errorDescription' => 'Anda melakukan terlalu banyak permintaan dalam waktu singkat. Tunggu sekitar satu menit lalu coba lagi.',
])
