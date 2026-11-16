<?php

namespace App\Support;

/**
 * Pagaran keamanan chatbot (input & output) yang bekerja DI LUAR model AI.
 *
 * Prinsip: jangan mengandalkan system prompt sebagai satu-satunya pengaman.
 * - INPUT : pesan pengguna dipindai sebelum model dipanggil. Pertanyaan privasi
 *           dijawab dengan respons tetap tanpa memanggil API (hemat kuota &
 *           menutup jalur "social engineering" ke model).
 * - OUTPUT: balasan model diperiksa sebelum ditampilkan — mendeteksi kebocoran
 *           system prompt/konteks internal dan menredaksi rahasia teknis
 *           (hash kata sandi, API key) bila model sempat menghasilkannya.
 *
 * Pagaran ini melengkapi (menggantikan) filter larangan coding lama: chatbot
 * kini DIHARAPKAN membantu edukasi pemrograman/SQL, sehingga pagaran hanya
 * menangani privasi & kebocoran informasi internal.
 */
class ChatGuard
{
    /**
     * Respons tetap untuk pertanyaan privasi (kebijakan & data pribadi).
     */
    public const PRIVACY_REFUSAL = 'Maaf, saya tidak dapat menjawab pertanyaan mengenai privasi atau membagikan informasi pribadi. '
        .'Saya tetap dapat membantu menjelaskan fitur, peran pengguna, dan cara menggunakan Agung ATK.';

    /**
     * Respons ketika balasan model terdeteksi membocorkan informasi internal.
     */
    public const LEAK_FALLBACK = 'Maaf, saya tidak dapat menampilkan informasi sistem internal. '
        .'Saya tetap dapat membantu menjelaskan fitur Agung ATK, struktur tabel database, relasi antartabel, dan konsep SQL tanpa mengungkap data pribadi.';

    /**
     * Respons tetap untuk permintaan source code aplikasi, file internal,
     * kredensial, system prompt internal, dan detail keamanan/deployment
     * (PROMPT bagian 5B) — dibedakan dari edukasi pemrograman/SQL umum yang
     * tetap dibantu (contoh fiktif).
     */
    public const SOURCE_REFUSAL = 'Maaf, saya tidak dapat membagikan source code aplikasi, isi file internal (.env, konfigurasi), '
        .'kredensial, atau system prompt internal. Saya tetap dapat membantu konsep pemrograman dan SQL secara edukatif '
        .'(dengan contoh fiktif), menjelaskan fitur, struktur tabel, relasi, dan alur operasional Agung ATK.';

    /**
     * Batas panjang balasan yang ditampilkan (pertahanan dari output liar).
     */
    private const MAX_REPLY_LENGTH = 6000;

    /**
     * Pola pertanyaan privasi (input). Disengaja longgar-berbahaya pada topik
     * privasi (F), tetapi TIDAK menyentuh pertanyaan struktur/SQL/edukasi aman (G):
     * jawaban hanya ditolak bila mengandung maksud mengakses/mengungkap data
     * pribadi, kredensial, atau kebijakan privasi.
     *
     * @var list<string>
     */
    private const PRIVACY_PATTERNS = [
        // Kebijakan / aturan privasi
        '/\b(?:kebijakan|aturan|prosedur|prinsip|makna|arti|definisi|penjelasan)\s+(?:dan\s+)?privasi\b/i',
        '/\bprivacy\s+polic(?:y|ies)\b/i',
        // Permintaan data pribadi secara umum
        '/\b(?:data|info|informasi|daftar|rekaman)\s+(?:pribadi|privat|sensitif)\b/i',
        '/\bpersonal\s+(?:data|information|details)\b/i',
        // Data/orang/akun lain
        '/\b(?:pengguna|user|pelanggan|akun|member|pembeli)\s+(?:lain|orang|lainnya)\b/i',
        '/\borang\s+lain\b/i',
        '/\bother\s+(?:users?|customers?|people|person|accounts?)\b/i',
        // Niat ekstraksi data pribadi (kata kerja + objek sensitif)
        '/\b(?:tampilkan|lihat|cek|ambil|kirim|ekspor|export|unduh|download|retrieve|reveal|show|get|dump)\b[\s\S]{0,60}\b(?:data\s+pribadi|privacy\s+data)\b/i',
        // Identitas & dokumen kependudukan / keuangan
        '/\b(?:nik|ktp|kartu\s+keluarga|no\s*kk|rekening|kartu\s+kredit|cvv|bpjs)\b/i',
        // Kontak milik orang lain
        '/\b(?:alamat|nomor\s+(?:hp|telepon|handphone)|email)\s+(?:orang|pengguna|user|pelanggan|akun)\s+lain\b/i',
        // Kredensial dengan niat ekstraksi
        '/\b(?:tampilkan|lihat|berikan|kirim|ambil|buka|reveal|show|print|dump|ekspor)\b[\s\S]{0,50}\b(?:password|kata\s+sandi|pass(?:word)?\s*hash|api[\s_-]?key|token|secret|kredensial|credential)\b/i',
        '/\b(?:password|kata\s+sandi|api[\s_-]?key|token|secret)\s+(?:saya|akun|admin|pengguna|user)\b/i',
        '/\$2[aby]\$\d{2}\$[\.\/A-Za-z0-9]{53}/',
        // Disamarkan sebagai query SQL / audit / debugging (tetap ditolak)
        '/\bselect\b[\s\S]{0,120}\b(?:password|email|phone|telepon|alamat|nik|ktp|rekening|token|api_key|remember_token)\b[\s\S]{0,120}\bfrom\b\s*`?(?:users|addresses|sessions|password_reset_tokens)\b/i',
        '/\bselect\b[\s\S]{0,120}\bfrom\b\s*`?(?:users|addresses|sessions|password_reset_tokens)\b[\s\S]{0,80}\b(?:password|email|phone|token|remember_token)\b/i',
        // Riwayat transaksi / aktivitas milik orang lain
        '/\b(?:riwayat|history|transaksi|pesanan|order|aktivitas)\s+(?:pembelian\s+)?(?:orang|pengguna|user|pelanggan|akun)\s+lain\b/i',
        '/\bpunya\s+(?:orang|dia|mereka)\b/i',
    ];

    /**
     * Pola permintaan source code / file internal / kredensial / system prompt
     * internal (input). Sengaja HANYA menangkap konteks APLIKASI INI — edukasi
     * pemrograman/SQL umum dengan contoh fiktif TIDAK boleh tertangkap.
     *
     * @var list<string>
     */
    private const SOURCE_PATTERNS = [
        // Source code aplikasi (kata maupun nama file/kelas)
        '/\b(?:source\s*code|kode\s*(?:sumber|program\s+aplikasi|aplikasi|backend|source))\b/i',
        '/\b(?:tampilkan|berikan|salin|copy|paste|kirim|buka|tunjukkan|muat|load|print|dump|reveal|tuliskan)\b'
            .'[\s\S]{0,50}\b(?:model|service|middleware|migration|route|view|blade|component|helper|trait)s?\b/i',
        '/\b(?:tampilkan|berikan|salin|copy|paste|kirim|buka|tunjukkan|muat|load|print|dump|reveal|tuliskan)\b'
            .'[\s\S]{0,60}\b\w*(?:Controller|Service|Middleware|Migration|Helper|Trait|Component)\b/i',
        '/\b(?:app|resources|routes|config|database|storage|vendor)\/[\w\-\/]+\.php\b/i',
        // File .env / konfigurasi rahasia (nisiat ekstraksi — "apa itu .env" tetap boleh)
        '/\b(?:tampilkan|berikan|salin|copy|kirim|buka|tunjukkan|muat|load|print|dump|isi|baca|read|show|give|reveal)\b'
            .'[\s\S]{0,40}\.env\b/i',
        // Kredensial layanan teknis
        '/\b(?:password|kata\s+sandi|kredensial|credential)\b[\s\S]{0,25}\b(?:database|db|mysql)\b/i',
        // System prompt internal (permintaan eksplisit — berbeda dari edukasi konsep)
        '/\b(?:tampilkan|berikan|lihat|cetak|print|show|reveal|salin|copy|tuliskan|kirim|buka)\b'
            .'[\s\S]{0,40}\b(?:system\s*prompt|prompt\s*sistem)\b/i',
        // Detail keamanan / upaya melewati proteksi
        '/\b(?:bypass|melewati|bobol|retas|crack)\b[\s\S]{0,40}\b(?:proteksi|keamanan|security|login|auth|otentikasi)\b/i',
        '/\bdetail\s+(?:implementasi\s+)?(?:keamanan|security)\b/i',
        // Deployment / infrastruktur internal
        '/\b(?:konfigurasi|config|setting|setup)\b[\s\S]{0,25}\b(?:deployment|deploy|server|infrastruktur|infrastructure|docker|nginx)\b/i',
    ];

    /**
     * Tanda tangan konten yang menandai kebocoran system prompt / konteks
     * internal. Bila salah satu ditemukan di balasan, seluruh balasan diganti.
     *
     * @var list<string>
     */
    private const LEAK_SIGNATURES = [
        'Kamu adalah Asisten AI cerdas untuk toko ATK',
        'KONTEKS DATABASE & PENGGUNA',
        'DATA PRODUK DARI DATABASE TOKO (JSON)',
        'PRIVASI & KEAMANAN (nilai tertinggi',
        'TOPIK LARANGAN CODING',
        'IDENTITAS & PEMAHAMAN AKTOR',
        'PERANMU (wajib patuh)',
        '"ringkasan_tabel"',
        '"pesanan_terbaru"',
        '"pesanan_saya"',
        '"stok_menipis"',
        '"pengiriman_saya"',
        '"user_terbaru"',
        '"alamat_saya"',
        '"fitur_saya"',
    ];

    /**
     * Pola rahasia teknis yang harus di-redaksi dari balasan model.
     *
     * @var list<string>
     */
    private const SECRET_PATTERNS = [
        '/\$2[aby]\$\d{2}\$[\.\/A-Za-z0-9]{53}/',
        '/\bsk-[A-Za-z0-9_-]{16,}\b/',
        '/\brk-[A-Za-z0-9_-]{16,}\b/',
        '/\bAI_API_KEY=[^\s]+/i',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
    ];

    /**
     * Apakah pesan pengguna merupakan permintaan/permusan privasi?
     */
    public function isPrivacyRequest(string $message): bool
    {
        foreach (self::PRIVACY_PATTERNS as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apakah pesan pengguna meminta source code aplikasi, file internal,
     * kredensial teknis, system prompt internal, atau detail keamanan/deployment?
     *
     * Berbeda dari edukasi pemrograman/SQL umum (konsep & contoh fiktif) yang
     * tetap dibantu — pola di sini menangkap konteks aplikasi ini saja.
     */
    public function isSourceCodeRequest(string $message): bool
    {
        foreach (self::SOURCE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Periksa & bersihkan balasan model SEBELUM ditampilkan.
     *
     * - Bila terdeteksi kebocoran system prompt / konteks internal → ganti
     *   seluruh balasan dengan LEAK_FALLBACK.
     * - Redaksi rahasia teknis (hash kata sandi, API key, kunci privat).
     * - Potong balasan yang terlalu panjang.
     */
    public function sanitizeOutput(string $reply): string
    {
        foreach (self::LEAK_SIGNATURES as $signature) {
            if (mb_stripos($reply, $signature) !== false) {
                return self::LEAK_FALLBACK;
            }
        }

        $cleaned = (string) preg_replace(self::SECRET_PATTERNS, '[REDAHSIKAN]', $reply);

        if (mb_strlen($cleaned) > self::MAX_REPLY_LENGTH) {
            $cleaned = mb_substr($cleaned, 0, self::MAX_REPLY_LENGTH)
                ."\n\n(Pesan dipotong: melebihi batas tampilan.)";
        }

        return trim($cleaned !== '' ? $cleaned : $reply);
    }
}
