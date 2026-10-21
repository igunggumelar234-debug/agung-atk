@include('partials.error-page', [
    'errorCode' => 500,
    'errorTitle' => 'Terjadi Gangguan di Sisi Server',
    'errorDescription' => 'Permintaan Anda tidak dapat diproses karena gangguan teknis. Data Anda aman — silakan coba beberapa saat lagi. Kesalahan ini sudah tercatat pada log aplikasi untuk ditindaklanjuti admin.',
])
