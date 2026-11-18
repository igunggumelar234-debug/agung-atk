<?php

namespace App\Support;

/**
 * Pengetahuan langkah kerja (how-to) seluruh sistem Agung ATK untuk chatbot.
 *
 * Setiap topik diverifikasi terhadap kode aktual, bukan asumsi:
 * - AuthController (registrasi, login, redirectBasedOnRole, logout)
 * - CatalogController (pencarian teks + filter kategori + scan barcode/SKU)
 * - ImageSearchController + ATKImageRecognitionService (pencarian visual;
 *   format JPG/PNG/WebP, batas ukuran dari config services.image_search)
 * - CartSession, CheckoutController (alamat milik sendiri, metode
 *   transfer/qris/cod, stok dikurangi satu kali dalam transaksi)
 * - PaymentController (bukti pembayaran), Admin\PaymentController (verify/reject)
 * - Seller\OrderController (paid → processing → ready_to_ship, hanya status
 *   yang sudah dibayar yang terlihat)
 * - Admin\ShipmentController (penugasan kurir), Courier\ShipmentController
 *   (hanya tugas miliknya; waiting → picked_up → shipping → delivered)
 * - ReviewController/CatalogController@show (kelayakan ulasan: completed)
 * - Admin\ProductController/CategoryController/UserController/ReportController
 * - config/notifications.php (Telegram/WhatsApp/Email + kejadian)
 * - App\Support\ShipmentStatus (status pengiriman resmi)
 *
 * Topik yang TIDAK ada di aplikasi dijawab jujur (UNAVAILABLE) — tanpa mengarang.
 */
class WorkflowKnowledge
{
    /**
     * Langkah per topik — hanya menyebut menu/aksi yang benar-benar ada.
     *
     * @var array<string, string>
     */
    private const STEPS = [
        'tentang' => 'Agung ATK (Agung ATK Kuningan) adalah website toko alat tulis kantor: katalog produk ATK dengan '
            .'pencarian teks, scan barcode, dan pencarian visual berbasis gambar; keranjang & checkout; pembayaran '
            .'transfer/QRIS/COD; pengiriman kurir toko; ulasan produk; laporan untuk admin; serta chatbot AI ini. '
            .'Pengguna terbagi dalam 4 role: admin, anggota (pegawai toko), kurir, dan pelanggan.',

        'daftar_login' => '1. Buka halaman Login (/login) atau Registrasi (/register).'."\n"
            .'2. Registrasi hanya untuk calon pelanggan (otomatis berperan "pelanggan"); akun anggota, kurir, dan admin dibuat oleh admin.'."\n"
            .'3. Login memakai email + password (dibatasi 5 percobaan per menit); akun yang dinonaktifkan admin tidak bisa masuk.'."\n"
            .'4. Setelah masuk, Anda diarahkan ke dashboard sesuai role (admin: /admin/dashboard; anggota: /anggota/dashboard; kurir: /kurir/dashboard; pelanggan: /pelanggan/dashboard).'."\n"
            .'5. Keluar lewat tombol Keluar — sesi dihapus dengan aman.',

        'cari_produk' => '1. Katalog (/products): semua produk aktif; pencarian kata kunci mencakup nama, SKU, merek, dan deskripsi; tersedia filter kategori.'."\n"
            .'2. Scan barcode: kode dipindai lalu diarahkan ke halaman produk yang cocok (berdasarkan barcode atau SKU); bila tidak ada, jatuh ke pencarian katalog dengan kode itu.'."\n"
            .'3. Pencarian visual: unggah/ambil foto barang ATK (JPG/PNG/WebP, batas ukuran terkonfigurasi) — sistem mengenali jenis ATK lalu menyarankan produk NYATA dari katalog; bila bukan ATK atau belum dikenali, sistem menyatakannya apa adanya.',

        'beli_pesan' => '1. Login sebagai pelanggan, pilih produk aktif di Katalog.'."\n"
            .'2. Masukkan ke Keranjang (/cart) dan atur jumlah sesuai stok tersedia.'."\n"
            .'3. Buka Checkout (/checkout): pilih alamat pengiriman milik sendiri, metode pembayaran (transfer, qris, atau cod), isi catatan bila perlu.'."\n"
            .'4. Pesanan dibuat berstatus "pending" dengan pembayaran "pending"; stok produk dikurangi satu kali di dalam transaksi database.'."\n"
            .'5. Anda diarahkan ke halaman pembayaran: unggah bukti (transfer/QRIS) atau ikuti instruksi COD.'."\n"
            .'6. Admin memverifikasi pembayaran -> status "paid".'."\n"
            .'7. Anggota memproses pesanan (processing -> siap kirim); admin menugaskan kurir; kurir mengantar (dalam perjalanan -> terkirim); pesanan berakhir "completed".',

        'bayar' => '1. Setelah checkout, halaman pembayaran pesanan terbuka otomatis.'."\n"
            .'2. Metode transfer/QRIS: unggah bukti pembayaran lewat tombol unggah bukti di halaman tersebut.'."\n"
            .'3. Metode COD: ikuti instruksi pembayaran di tempat — tanpa unggah bukti.'."\n"
            .'4. Admin memeriksa bukti lalu memverifikasi (verified) atau menolak (rejected); status terlihat di menu "Pesanan Saya".',

        'pantau_status' => '1. Login sebagai pelanggan, buka menu "Pesanan Saya" (hanya pesanan milik Anda).'."\n"
            .'2. Klik pesanan untuk detail: alamat, metode & status pembayaran, resi/status pengiriman bila sudah ditugaskan.'."\n"
            .'3. Alur status pesanan: pending -> paid -> processing -> ready_to_ship -> shipping -> delivered -> completed (atau cancelled).'."\n"
            .'4. Pembatalan oleh pelanggan hanya bisa selama status masih "pending" — stok otomatis kembali.',

        'ulasan' => '1. Buka halaman detail produk yang pernah Anda terima.'."\n"
            .'2. Form ulasan muncul bila memenuhi syarat: Anda pelanggan, pesanan berstatus "completed", produk ada di pesanan itu, dan belum diulas.'."\n"
            .'3. Berikan rating + komentar; ulasan tampil publik di halaman produk dan dapat dimoderasi admin.',

        'kelola_produk' => '1. Login sebagai admin, buka menu "Produk & Stok" (/admin/products).'."\n"
            .'2. Tambah/ubah/hapus produk (nama, SKU, merek, harga, stok, kategori, status aktif).'."\n"
            .'3. Kelola gambar produk (unggah, jadikan gambar utama, hapus) di halaman detail produk.'."\n"
            .'4. Perbarui stok dan aktif/nonaktifkan produk tanpa menghapusnya.'."\n"
            .'5. Kategori dikelola terpisah di menu "Kategori" (tambah/ubah/hapus + saklar aktif).',

        'verifikasi_bayar' => '1. Login sebagai admin, buka menu "Pembayaran" (/admin/payments).'."\n"
            .'2. Periksa pembayaran berstatus pending beserta bukti yang diunggah pelanggan.'."\n"
            .'3. Verifikasi (menjadi "paid" — pesanan lanjut diproses) atau Tolak sesuai pemeriksaan bukti.'."\n"
            .'4. Keputusan tercatat di Audit Log dan pelanggan menerima notifikasi status.',

        'tugaskan_kurir' => '1. Login sebagai admin, buka menu "Pengiriman" (/admin/shipments).'."\n"
            .'2. Pilih pesanan berstatus "ready_to_ship" yang akan dikirim.'."\n"
            .'3. Pilih kurir dari daftar kurir aktif (saklar aktif/nonaktif kurir dikelola di menu "Kurir").'."\n"
            .'4. Pengiriman tercatat dengan nomor resi; kurir menerima notifikasi penugasan dan statusnya dipantau di halaman yang sama.',

        'proses_pesanan' => '1. Login sebagai anggota, buka menu "Pesanan" (/anggota/orders) — hanya pesanan SUDAH DIBAYAR (paid s/d completed) yang terlihat.'."\n"
            .'2. Pesanan "paid": klik Proses -> menjadi "processing".'."\n"
            .'3. Pesanan "processing": klik Siap Kirim -> menjadi "ready_to_ship" (siap diambil kurir).'."\n"
            .'4. Setiap aksi divalidasi server (hanya maju satu langkah; transisi ganda/salah ditolak) dan tercatat di Audit Log.'."\n"
            .'5. Anggota TIDAK punya akses manajemen akun, laporan, atau verifikasi pembayaran — itu kewenangan admin.',

        'update_stok' => '1. Login sebagai anggota, buka menu "Stok Barang" (/anggota/stock).'."\n"
            .'2. Pantau jumlah stok tiap produk (termasuk produk yang stoknya menipis).'."\n"
            .'3. Perbarui stok produk sesuai hasil pengecekan fisik toko; perubahan tercatat di sistem.',

        'pengiriman' => '1. Admin: menu "Pengiriman" (/admin/shipments) — daftarkan pengiriman untuk pesanan "ready_to_ship" dan tugaskan kurir aktif.'."\n"
            .'2. Kurir: menu "Pengiriman Saya" (/courier/shipments) — HANYA tugas yang ditugaskan kepadanya (beserta alamat & telepon penerima).'."\n"
            .'3. Kurir memperbarui status maju satu langkah saja: menunggu (waiting) -> diambil (picked_up) -> dalam perjalanan (shipping) -> terkirim (delivered); transisi lain ditolak server.'."\n"
            .'4. Saat kurir memperbarui, status pesanan pelanggan ikut menyesuaikan otomatis (ready_to_ship -> dalam perjalanan -> terkirim).'."\n"
            .'5. Nomor resi dan riwayat waktu (diambil/diterima) tercatat otomatis pada pengiriman.',

        'laporan' => '1. Login sebagai admin, buka menu "Laporan" (/admin/reports).'."\n"
            .'2. Pilih jenis laporan dan rentang tanggal yang diinginkan.'."\n"
            .'3. Tersedia tampilan layar, cetak/PDF, dan ekspor CSV (unduh).'."\n"
            .'4. Statistik ringkas juga tampil di Dashboard Admin.',

        'notifikasi' => '1. Login sebagai admin, buka menu "Notifikasi" (/admin/notifications).'."\n"
            .'2. Saklar saluran Telegram, WhatsApp, dan Email — per kejadian (pesanan baru, pembayaran, stok menipis, pengiriman, pendaftaran, ringkasan harian).'."\n"
            .'3. Tersedia bantuan Chat ID Telegram dan tombol uji kirim di halaman yang sama.'."\n"
            .'4. Kredensial teknis (token bot, API WhatsApp, SMTP) diatur admin lewat konfigurasi server — bukan melalui chat.',

        'chatbot' => '1. Tanpa login: gunakan kotak chat di halaman beranda (dibatasi 20 pesan per menit).'."\n"
            .'2. Pelanggan login: menu "AI Assistant" di dashboard — percakapan tersimpan per akun dan bisa di-reset.'."\n"
            .'3. Saya menjawab info produk, panduan fitur, struktur database, dan konsep pemrograman umum — bukan pengganti operasi toko.',
    ];

    /**
     * Topik yang BELUM/TIDAK tersedia — dijawab jujur tanpa mengarang.
     *
     * @var array<string, string>
     */
    public const UNAVAILABLE = [
        'pengiriman_gagal' => 'Fitur "pengiriman gagal" BELUM ADA di aplikasi saat ini — status pengiriman hanya tersedia: '
            .'menunggu (waiting), diambil (picked_up), dalam perjalanan (shipping), dan terkirim (delivered). '
            .'Untuk masalah kiriman, hubungi toko/admin agar ditindaklanjuti di luar sistem.',
        'retur_refund' => 'Fitur retur / pengembalian uang (refund) BELUM ADA di aplikasi saat ini. '
            .'Yang tersedia: pembatalan pesanan oleh pelanggan selama masih "pending", atau oleh admin hingga "ready_to_ship" — stok otomatis kembali.',
        'chat_ke_kurir' => 'Fitur chat langsung dengan kurir BELUM ADA di aplikasi. '
            .'Kurir hanya menerima penugasan pengiriman; koordinasi masalah kiriman dilakukan lewat admin toko.',
        'cetak_struk' => 'Fitur cetak struk untuk pelanggan BELUM ADA di aplikasi. '
            .'Yang tersedia: cetak/PDF laporan penjualan khusus admin (menu Laporan).',
    ];

    /**
     * Pola deteksi per topik — kebanyakan memerlukan kata sandi pengantar
     * (cara/langkah/bagaimana/dst.) agar edukasi umum tidak tertangkap.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        'tentang' => '/\b(?:apa\s+itu|jelaskan|kenalkan|perkenalkan|sekilas|tentang|profil)\b[\s\S]{0,30}'
            .'(?:agung\s+atk|toko\s+ini|website\s+ini|aplikasi\s+ini|sistem\s+ini|tokonya)\b'
            .'|\b(?:agung\s+atk|website\s+ini|aplikasi\s+ini|sistem\s+ini)\s+(?:itu|adalah|apa|untuk\s+apa|buat\s+apa)\b'
            .'|\b(?:ini|itu)\s+(?:website|aplikasi|sistem|toko)\s+(?:apa|untuk\s+apa|buat\s+apa)\b'
            .'|\b(?:website|aplikasi|sistem|toko)\s+(?:ini|itu)\s+(?:untuk\s+apa|buat\s+apa)\b/i',

        // Topik TIDAK tersedia diperiksa lebih dulu agar tidak tertangkap pola umum
        'pengiriman_gagal' => '/\b(?:pengiriman|paket|kiriman|barang)\b[\s\S]{0,30}'
            .'(?:gagal|rusak|hilang|tidak\s+sampai|tidak\s+terkirim)\b|\bgagal\s+kirim\b/i',
        'retur_refund' => '/\b(?:retur|refund|pengembalian\s+(?:uang|barang)|uang\s+kembali|tukar\s+barang)\b/i',
        'chat_ke_kurir' => '/\b(?:chat|pesan|kontak|hubungi|menghubungi|telepon|menelpon|whatsapp)\b[\s\S]{0,25}\b(?:kurir|courier)\b/i',
        'cetak_struk' => '/\b(?:mencetak|cetak|print|download|unduh)\b[\s\S]{0,25}\b(?:struk|nota|bon)\b/i',

        'daftar_login' => '/\b(?:cara|langkah|bagaimana|gimana|kenapa)\b[\s\S]{0,40}'
            .'(?:daftar|registrasi|register|mendaftar|login|masuk|signin|log\s*in)\b/i',
        'cari_produk' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:cari|search|mencari|pencarian|scan|barcode|kamera|foto|gambar|filter)\b[\s\S]{0,30}'
            .'(?:produk|barang|atk|kategori|katalog)\b/i',
        'beli_pesan' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:beli|membeli|pesan|memesan|pemesanan|belanja|checkout|check\s*out)\b/i',
        'verifikasi_bayar' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:verifikasi|memverifikasi|setujui|menyetujui|tolak|menolak)\b[\s\S]{0,25}'
            .'(?:pembayaran|payment|bukti)\b/i',
        'bayar' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:bayar|membayar|pembayaran|bukti\s+(?:bayar|pembayaran)|upload\s+bukti|metode\s+(?:pembayaran|bayar))\b/i',
        'pantau_status' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:lihat|melihat|pantau|memantau|cek|mengecek|track|lacak)\b[\s\S]{0,30}'
            .'(?:status|riwayat|pesanan|order|pengiriman|resi)\b/i',
        'ulasan' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,40}'
            .'(?:ulasan|review|rating|beri\s+nilai|menilai|ulas)\b/i',
        'kelola_produk' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:tambah|menambah|kelola|mengelola|edit|ubah|hapus|atur|input)\b[\s\S]{0,25}'
            .'(?:produk|kategori|gambar\s+produk|stok)\b/i',
        'tugaskan_kurir' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:tugaskan|menugaskan|assign|pilih\s+kurir)\b/i',
        'proses_pesanan' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:(?:proses|memproses|prosesan)\s+(?:pesanan|order)|siap\s+kirim)\b/i',
        'update_stok' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:perbarui|memperbarui|update|ubah|edit)\b[\s\S]{0,20}\b(?:stok|stock)\b/i',
        'pengiriman' => '/\b(?:cara|langkah|bagaimana|gimana|proses)\b[\s\S]{0,40}'
            .'(?:pengiriman|mengirim|kirim\s+barang|delivery)\b/i',
        'laporan' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,40}'
            .'(?:laporan|report|statistik|penjualan|cetak|export|ekspor|csv|pdf)\b'
            .'|\b(?:di\s+mana|dimana|mana\s+(?:saya\s+)?bisa|ke\s+mana)\b[\s\S]{0,40}'
            .'(?:laporan|report|statistik\s+penjualan)\b/i',
        'notifikasi' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,45}'
            .'(?:notifikasi|telegram|whatsapp|email\s+otomatis)\b/i',
        'chatbot' => '/\b(?:cara|langkah|bagaimana|gimana)\b[\s\S]{0,40}'
            .'(?:chatbot|chat\s+ai|asisten\s+ai|ai\s+assistant|tanya\s+chatbot)\b/i',
    ];

    /**
     * Jawaban langkah kerja untuk pesan ini; null bila bukan pertanyaan how-to
     * (lanjut ke jalur penangan berikutnya).
     */
    public function workflowReply(string $lower): ?string
    {
        foreach (self::PATTERNS as $topic => $pattern) {
            if (preg_match($pattern, $lower) !== 1) {
                continue;
            }

            return $this->formatReply($topic);
        }

        return null;
    }

    /**
     * Susun jawaban per topik; topik tanpa langkah (belum tersedia) memakai
     * teks kejujuran UNAVAILABLE.
     */
    public function formatReply(string $topic): string
    {
        $steps = self::STEPS[$topic] ?? null;

        if ($steps === null) {
            return self::UNAVAILABLE[$topic]
                ?? 'Maaf, saya belum memiliki panduan terverifikasi untuk topik tersebut, dan saya tidak boleh mengarang.';
        }

        $titles = [
            'tentang' => 'Tentang Agung ATK (fakta dari implementasi website):',
            'daftar_login' => 'Cara daftar & login di Agung ATK:',
            'cari_produk' => 'Cara mencari produk ATK:',
            'beli_pesan' => 'Cara melakukan pemesanan (belanja) di Agung ATK:',
            'bayar' => 'Cara pembayaran & unggah bukti:',
            'pantau_status' => 'Cara melihat status & riwayat pesanan:',
            'ulasan' => 'Cara memberi ulasan produk:',
            'kelola_produk' => 'Cara mengelola produk & kategori (admin):',
            'verifikasi_bayar' => 'Cara memverifikasi pembayaran (admin):',
            'tugaskan_kurir' => 'Cara menugaskan kurir (admin):',
            'proses_pesanan' => 'Cara memproses pesanan (anggota / pegawai toko):',
            'update_stok' => 'Cara memperbarui stok barang (anggota):',
            'pengiriman' => 'Alur pengiriman di Agung ATK:',
            'laporan' => 'Cara melihat & mengunduh laporan (admin):',
            'notifikasi' => 'Cara mengatur notifikasi otomatis (admin):',
            'chatbot' => 'Cara menggunakan chatbot AI ini:',
        ];

        return ($titles[$topic] ?? 'Panduan:')."\n".$steps;
    }
}
