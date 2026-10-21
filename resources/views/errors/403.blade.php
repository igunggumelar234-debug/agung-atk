@include('partials.error-page', [
    'errorCode' => 403,
    'errorTitle' => 'Akses Ditolak',
    'errorDescription' => 'Anda tidak memiliki hak akses ke halaman atau fitur ini. Silakan masuk dengan akun yang berwenang, atau hubungi admin toko bila Anda merasa seharusnya memiliki akses.',
])
