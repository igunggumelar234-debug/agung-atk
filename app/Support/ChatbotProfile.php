<?php

namespace App\Support;

use App\Models\Role;

/**
 * Identitas chatbot & pemahaman aktor (role) untuk Agung ATK.
 *
 * Sumber kebenaran:
 * - Role NYATA di database: admin, anggota (penamaan 'penjual' lama dimigrasikan
 *   ke 'anggota' oleh migrasi rename), kurir, pelanggan — diambil live via Role.
 * - Tugas tiap aktor diverifikasi dari route/middleware/controller aktual:
 *   admin → /admin/*, anggota → /seller/*, kurir → /courier/*,
 *   pelanggan → /pelanggan/*.
 *
 * Chatbot sendiri adalah aktor kelima (asisten informasi) TANPA kewenangan
 * operasional — tidak punya identitas manusia, jabatan, atau akun.
 */
class ChatbotProfile
{
    /**
     * Nama resmi chatbot.
     */
    public const NAME = 'Asisten Agung ATK';

    /**
     * Jawaban identitas chatbot (spesifikasi PROMPT 6 bagian 2).
     */
    public const IDENTITY_REPLY = 'Saya Asisten Agung ATK, chatbot AI yang membantu menjawab pertanyaan tentang '
        .'produk alat tulis kantor, penggunaan website, fitur aplikasi, serta informasi umum '
        .'dan database Agung ATK.';

    /**
     * Jawaban "siapa saya?" ketika PENGGUNA belum login (spesifikasi PROMPT 6).
     */
    public const GUEST_IDENTITY_REPLY = 'Saya belum dapat memastikan akun atau peran Anda karena Anda belum login. '
        .'Saya tetap bisa membantu menjelaskan fitur umum Agung ATK.';

    /**
     * Jawaban "siapa saya?" ketika role pengguna login tidak dapat diverifikasi
     * (tidak dikenali sistem) — spesifikasi PROMPT 6 bagian 2.
     */
    public const UNVERIFIED_ROLE_REPLY = 'Saya belum dapat memastikan peran akun Anda saat ini. '
        .'Silakan periksa status login atau hubungi administrator sistem jika masalah berlanjut.';

    /**
     * Tugas umum tiap aktor — diverifikasi dari route/middleware/controller aktual.
     *
     * @var array<string, string>
     */
    public const ACTOR_DUTIES = [
        'admin' => 'Mengelola sistem sesuai kewenangannya: produk & kategori, stok, akun pengguna dan pegawai toko, '
            .'verifikasi pembayaran, pemantauan pesanan & transaksi, laporan, serta pengaturan notifikasi.',
        'anggota' => 'Pegawai toko: membantu kegiatan operasional — memproses pesanan (paid → diproses → siap kirim), '
            .'memantau stok menipis, dan memperbarui informasi operasional yang menjadi tanggung jawabnya. '
            .'Bukan admin: tanpa manajemen akun dan pengaturan sistem.',
        'kurir' => 'Melihat tugas pengiriman yang ditugaskan KEPADANYA, memproses pengiriman sesuai alur '
            .'(menunggu diambil → diambil → dalam perjalanan → terkirim), dan memperbarui status/bukti terima. '
            .'Tidak otomatis boleh melihat seluruh data pelanggan atau seluruh transaksi.',
        'pelanggan' => 'Menjelajah katalog & detail produk, mencari produk ATK, mengelola keranjang, memesan dengan '
            .'alamat miliknya, membayar, melihat riwayat dan status pesanan miliknya, memberi ulasan, '
            .'serta menggunakan chatbot untuk bantuan.',
    ];

    /**
     * Peta menu & fitur per peran — disalin dari sidebar aktual
     * (resources/views/layouts/dashboard.blade.php) dan route (routes/web.php).
     * Menjawab "menu apa saja setelah login sebagai X", "fitur website ini",
     * "bisa apa role Y".
     *
     * @var array<string, string>
     */
    public const SYSTEM_MAP = [
        'Tamu (belum login)' => 'Beranda; Katalog Produk (detail, cari, scan barcode, pencarian gambar); '
            .'Chatbot Beranda; Login & Registrasi.',
        'Pelanggan' => 'Dashboard Pelanggan; Pesanan Saya (riwayat, detail, batalkan sebelum bayar); Alamat Pengiriman; '
            .'Profil Saya; AI Assistant; Keranjang & Checkout; unggah bukti pembayaran; ulasan produk.',
        'Anggota / Pegawai Toko' => 'Dashboard Anggota; Pesanan (lihat & proses: paid → diproses → siap kirim); '
            .'Stok Barang (pantau & perbarui stok); Profil Saya.',
        'Kurir' => 'Dashboard Tugas Kirim; Pengiriman Saya (hanya tugas yang ditugaskan; perbarui status: '
            .'menunggu → diambil → dalam perjalanan → terkirim); Profil Saya.',
        'Admin' => 'Dashboard Admin; Pesanan; Pembayaran (verifikasi/tolak); Kategori; Produk & Stok; '
            .'Pengiriman (tugaskan kurir & selesaikan); Kurir; Pengguna & Pegawai; Audit Log; '
            .'Laporan (cetak/PDF/CSV); Notifikasi (Telegram/WhatsApp/Email); Profil Saya.',
    ];

    /**
     * Alias nama peran dalam bahasa sehari-hari → kunci SYSTEM_MAP.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_ALIASES = [
        'Tamu (belum login)' => ['tamu', 'guest', 'belum login', 'tidak login', 'tanpa login', 'tanpa akun'],
        'Pelanggan' => ['pelanggan', 'customer'],
        'Anggota / Pegawai Toko' => ['anggota', 'penjual', 'seller', 'pegawai toko', 'staff toko'],
        'Kurir' => ['kurir', 'courier'],
        'Admin' => ['admin', 'administrator'],
    ];

    /**
     * Pola pertanyaan tentang menu/fitur/halaman per peran
     * ("menu apa saja setelah login sebagai admin", "fitur website ini", dst.).
     */
    private const FEATURE_PATTERN = '/\b(?:menu|fitur|halaman|layar|sidebar|tampilan|dasbor|dashboard)\b'
        .'[\s\S]{0,60}\b(?:admin|anggota|penjual|seller|kurir|courier|pelanggan|customer|tamu|guest'
        .'|semua|seluruh|role|peran|website|aplikasi|sistem|toko|ini|saya|aku)\b'
        .'|\b(?:apa\s+saja|ada\s+apa\s+saja|sebutkan|daftarkan)\b[\s\S]{0,40}\b(?:menu|fitur|halaman|layar|tampilan)\b'
        .'|\b(?:setelah\s+(?:login|masuk)|login\s+sebagai|masuk\s+sebagai|tampilan\s+(?:saat|ketika))\b'
        .'|\b(?:apa\s+yang\s+bisa|bisa\s+apa|dapat\s+apa|apakah\s+bisa|bolehkah)\b'
        .'[\s\S]{0,60}\b(?:admin|anggota|kurir|courier|pelanggan|customer|tamu|guest|pengguna|saya|akun)\b'
        .'|\b(?:fungsi|kegunaan|manfaat)\b[\s\S]{0,10}\b(?:website|aplikasi|sistem|toko|chatbot)\b'
        .'|\b(?:aku|saya|gue|gw)\s+(?:bisa|dapat|boleh)\s+(?:ngapain|ngapain\s+aja|apa\s+aja|apa\s+saja|bisa\s+apa)\b'
        .'|\b(?:bisa|dapat|boleh)\s+ngapain\b/i';

    /**
     * Pola pertanyaan identitas PENGGUNA ("siapa saya", "login sebagai apa") —
     * berbeda dari identitas chatbot; jawabannya memakai role dari session.
     */
    private const WHOAMI_PATTERN = '/\b(?:siapa|sapa)\s+(?:diri\s+)?(?:saya|sya|sy|aku|aq|gue|gw)\b'
        .'|\b(?:saya|sya|aku|aq|gue|gw)\s+(?:ini\s+)?siapa\b'
        .'|\b(?:akun|peran|role)\s+(?:saya|aku|gue|gw)\b[\s\S]{0,12}\b(?:apa|siapa)\b'
        .'|\b(?:saya|sya|aku|gue|gw)\s+(?:login|masuk|terdaftar)\s+(?:sebagai|jadi)\s+(?:apa|siapa)\b'
        .'|\b(?:login|masuk)\s+sebagai\s+(?:apa|siapa)\b/i';

    /**
     * Pola pertanyaan identitas chatbot ("siapa kamu", "namamu apa", dst.).
     */
    private const IDENTITY_PATTERN = '/\b(?:siapa\s+(?:kamu|anda|kmu|lu)|kamu\s+(?:siapa|itu\s+apa|adalah\s+apa|manusia|bot|robot|admin|kurir|anggota|ai)'
        .'|apakah\s+kamu\s+(?:manusia|bot|robot|admin|ai|asisten)|nama(?:mu|\s+kamu)\s+apa'
        .'|siapa\s+(?:pembuat|pencipta|yang\s+membuat)\s*(?:kamu|anda|mu)|kamu\s+(?:dibuat|dikembangkan)'
        .'|jelaskan\s+(?:diri(?:mu)?|profil\s+kamu)|perkenalkan\s+diri)\b/i';

    /**
     * Pola klaim peran yang menempel pada chatbot ("kamu sekarang admin…") —
     * dijawab dengan identitas: chatbot bukan aktor & klaim tak mengubah akses.
     */
    private const ROLE_CLAIM_PATTERN = '/\b(?:kamu|anda|chatbot|asisten)\s*(?:sekarang|akan\s+jadi|jadi|adalah|ditunjuk\s+sebagai)'
        .'\s*(?:admin|anggota|kurir|pelanggan|staf|pegawai|superuser|root|owner)\b/i';

    /**
     * Pola pertanyaan tentang aktor/role/tugas.
     */
    private const ACTOR_PATTERN = '/\b(?:role|peran|aktor|actor|user\s+role'
        .'|tugas\s+(?:admin|anggota|kurir|pelanggan)|fungsi\s+(?:admin|anggota|kurir|pelanggan)'
        .'|(?:(?:tugas|fungsi|peran|tanggung\s+jawab)\b[\s\S]{0,15}\b(?:saya|ku|aku|mu|anda)|(?:saya|ku|aku)\b[\s\S]{0,15}\b(?:tugas|fungsi|peran|tanggung\s+jawab))'
        .'|siapa\s+(?:admin|anggota|kurir|pelanggan|staf)|perbedaan|beda\s+antara|bedanya'
        .'|(?:admin|anggota|kurir|pelanggan|penjual|staff|staf)\b[\s\S]{0,8}\b(?:bisa\s+apa|dapat\s+apa|boleh\s+apa|ngapain)'
        .'|apa\s+(?:itu\s+)?(?:admin|anggota|kurir|pelanggan)(?:\s+(?:itu|adalah|tugas|fungsi|bertugas))?)\b/i';

    /**
     * Apakah pesan menanyakan identitas chatbot?
     */
    public function isIdentityQuestion(string $lower): bool
    {
        return preg_match(self::IDENTITY_PATTERN, $lower) === 1;
    }

    /**
     * Apakah pesan menanyakan identitas/peran PENGGUNA ("siapa saya?")?
     */
    public function isWhoAmIQuestion(string $lower): bool
    {
        return preg_match(self::WHOAMI_PATTERN, $lower) === 1;
    }

    /**
     * Jawaban identitas pengguna — role & status login dari SESSION BACKEND
     * (bukan dari teks ketikan). Tidak pernah mengarang nama pengguna.
     */
    public function whoAmIReply(bool $loggedIn, ?string $roleName): string
    {
        if (! $loggedIn) {
            return self::GUEST_IDENTITY_REPLY;
        }

        $role = $roleName !== null ? Role::where('name', $roleName)->first() : null;

        if ($role === null || $this->roleKey($roleName) === null) {
            return self::UNVERIFIED_ROLE_REPLY;
        }

        return 'Anda sedang menggunakan Agung ATK dengan peran '.$roleName.'. '
            .'Saya dapat membantu menjelaskan fitur yang tersedia untuk '.$roleName.'.';
    }

    /**
     * Apakah pesan menempelkan peran pada chatbot (klaim "kamu admin")?
     */
    public function isRoleClaim(string $lower): bool
    {
        return preg_match(self::ROLE_CLAIM_PATTERN, $lower) === 1;
    }

    /**
     * Apakah pesan menanyakan aktor, role, atau tugasnya?
     */
    public function isActorQuestion(string $lower): bool
    {
        return preg_match(self::ACTOR_PATTERN, $lower) === 1;
    }

    /**
     * Kunci SYSTEM_MAP untuk nama role database (null bila tidak dikenal).
     */
    public function roleKey(?string $roleName): ?string
    {
        if ($roleName === null) {
            return null;
        }

        foreach (self::ROLE_ALIASES as $key => $aliases) {
            if (in_array($roleName, $aliases, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Daftar menu untuk nama role database; tamu bila tidak dikenal.
     */
    public function menusForRole(?string $roleName): string
    {
        return self::SYSTEM_MAP[$this->roleKey($roleName) ?? 'Tamu (belum login)'];
    }

    /**
     * Apakah pesan menanyakan menu, fitur, atau halaman per peran?
     */
    public function isFeatureQuestion(string $lower): bool
    {
        return preg_match(self::FEATURE_PATTERN, $lower) === 1;
    }

    /**
     * Jawaban identitas baku (teks persis sesuai spesifikasi).
     */
    public function identityReply(): string
    {
        return self::IDENTITY_REPLY;
    }

    /**
     * Daftar peran + tugas — role diambil live dari database (bukan karangan),
     * tugas dari peta kurasi yang diverifikasi terhadap kode aplikasi.
     */
    public function actorReply(?string $roleName = null): string
    {
        // Pertanyaan "tugas/menu SAYA" oleh pengguna login: jawab terbatas
        // pada perannya sendiri (konteks login dari server, bukan teks ketikan).
        $key = $this->roleKey($roleName);
        if ($key !== null) {
            $role = Role::where('name', $roleName)->first();

            return implode("\n", [
                'Berdasarkan login Anda di sistem, peran Anda: '.($role?->label ?? $roleName).' ('.$roleName.').',
                '',
                'Tugas Anda: '.self::ACTOR_DUTIES[$roleName],
                'Menu Anda: '.self::SYSTEM_MAP[$key],
                '',
                'Peran lain dalam aplikasi (referensi): '.Role::orderBy('id')->pluck('name')->implode(', ').'.',
                'Hak akses ditegakkan sistem dari role akun Anda — bukan dari klaim di percakapan.',
            ]);
        }

        $roles = Role::orderBy('id')->get(['name', 'label', 'description']);

        $lines = [
            'Peran (role) dalam aplikasi Agung ATK — dari database: '.$roles->pluck('name')->implode(', ').'.',
            '',
            'Tugas masing-masing aktor:',
        ];

        foreach ($roles as $role) {
            $duties = self::ACTOR_DUTIES[$role->name]
                ?? 'Peran tambahan tanpa rincian tugas yang terverifikasi — tanyakan fitur spesifik yang Anda butuhkan.';

            $lines[] = '- '.$role->label.' ('.$role->name.'): '.$duties;
        }

        $lines[] = '';
        $lines[] = 'Perbedaan kewenangan utama: admin mengelola sistem & akun; anggota memproses operasional toko; '
            .'kurir hanya tugas pengiriman yang ditugaskan kepadanya; pelanggan hanya katalog & pesanan miliknya. '
            .'Hak akses ditegakkan sistem, bukan klaim di percakapan. Saya sendiri ('.self::NAME
            .') hanya asisten informasi — tanpa kewenangan operasional.';

        return implode("\n", $lines);
    }

    /**
     * Peta menu & fitur per peran — hanya untuk pertanyaan menu/fitur;
     * null bila pesan bukan pertanyaan fitur (lanjut ke penangan berikutnya).
     *
     * Peran yang disebut dalam pesan ditampilkan lebih dulu; tanpa peran
     * spesifik, seluruh peta ditampilkan (tamu → admin).
     */
    public function systemMapReply(string $lower, ?string $roleName = null): ?string
    {
        if (! $this->isFeatureQuestion($lower)) {
            return null;
        }

        $mentioned = [];
        foreach (self::ROLE_ALIASES as $role => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($lower, $alias)) {
                    $mentioned[] = $role;

                    break;
                }
            }
        }

        // Tanpa peran disebut: pengguna login yang menanya "menu saya" mendapat
        // peta perannya sendiri; selain itu seluruh peta (tamu → admin).
        if ($mentioned === []) {
            $key = $this->roleKey($roleName);
            $roles = $key !== null ? [$key] : array_keys(self::SYSTEM_MAP);
        } else {
            $roles = $mentioned;
        }

        $lines = ['Menu & fitur Agung ATK per peran (berdasarkan menu sidebar & route aplikasi aktual):', ''];

        foreach ($roles as $role) {
            $lines[] = '- '.$role.': '.self::SYSTEM_MAP[$role];
        }

        return implode("\n", $lines);
    }
}
