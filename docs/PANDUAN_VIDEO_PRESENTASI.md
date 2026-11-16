# PANDUAN VIDEO PRESENTASI — AGUNG ATK

**Judul proyek:** Sistem Informasi Penjualan Toko Jual Beli Alat Tulis Kerja Berbasis Web dengan Integrasi Artificial Intelligence (AI) di Kabupaten Kuningan
**Status dokumen:** naskah & panduan siap direkam — **video BELUM direkam** (perekaman dilakukan mahasiswa).

> Aturan emas: **narasi dijelaskan, bukan dibacakan.** Tampilkan aplikasi/diagram nyata, bukan slide tanpa bukti.

---

## A. VIDEO PROGRES 1 — PRD dan Konsep Aplikasi

**Durasi: 3–5 menit · Bobot nilai video: 20%**
**Materi wajib:** identifikasi masalah nyata · latar belakang Agung ATK · tujuan · manfaat · kebutuhan fungsional & nonfungsional · peran strategis AI.

### Naskah (panduan per segmen ± 4 menit)

**0:00–0:30 — Pembuka & identitas**
> "Assalamualaikum/ Selamat hari, saya [Nama], NIM [NIM], kelompok [X], tema topik kelompok [tema]. Proyek saya: Sistem Informasi Penjualan Toko ATK berbasis web dengan integrasi AI untuk toko di Kabupaten Kuningan."

**0:30–1:30 — Masalah nyata (gunakan poin dari `docs/PRD_SRS_AGUNG_ATK.md` §2–3)**
> "Toko ATK nyata punya masalah: varian produk serupa membuat pencarian lambat, stok dicatat manual sehingga sering kehabisan atau kelebihan, pesanan-pembayaran-pengiriman tidak terlacak, dan pegawai habis waktu melayani pertanyaan repetitif seperti 'ada kertas A4?' atau 'pesanan saya sampai mana?'."
> *Tunjukkan daftar M1–M8 di layar (bukan membacanya semua — ambil 3 contoh).*

**1:30–2:15 — Tujuan & manfaat**
> "Sistem ini menjawab dengan enam tujuan: katalog digital akurat, siklus pesanan-terkirim-ulasan terintegrasi, otorisasi ketat empat peran, notifikasi otomatis, chatbot yang memahami aplikasi dan data toko, serta pencarian produk lewat foto."
> *Sebutkan manfaat per pihak: pemilik, pegawai, kurir, pembeli.*

**2:15–3:15 — Kebutuhan fungsional & nonfungsional**
> "Kebutuhan fungsional saya rinci menjadi 25 butir F1–F25 dengan acceptance criteria terukur — misalnya AC12: laporan harus menampilkan total penjualan sesuai data tersimpan. Nonfungsional: performa halaman di bawah 3 detik, keamanan bcrypt + CSRF + rate limit, keterlacakan lewat activity log."
> *Tampilkan tabel F dan AC dari PRD — sorot 2 baris sebagai contoh keterukuran.*

**3:15–4:30 — Peran strategis AI (jujur & spesifik)**
> "AI di sini bukan tempelan. Dua wujud nyata: (1) chatbot yang menjawab pertanyaan lewat intent terbatas ke database — read-only dan diskop per role, jadi angka yang dijawab selalu benar dan tidak ada akses SQL bebas; dan (2) pencarian produk lewat foto dengan deteksi objek YOLOv8 yang saya latih sendiri pada data ATK — bukan barcode, bukan pengenalan nama teks."
> "Yang TIDAK saya klaim: tidak ada RAG, vector database, atau model besar berjalan lokal — dokumen PRD saya tulis apa adanya."

**4:30–5:00 — Penutup**
> "Selengkapnya ada di dokumen PRD/SRS yang mengikuti IEEE 830 — 20 bagian, user story dengan acceptance criteria yang bisa diuji. Terima kasih."

**Checklist perekaman:** tampilan wajah jelas (webcam), 720p minimal, suara jernih, durasi 3–5 menit.

---

## B. VIDEO PROGRES 2 — UML dan UI/UX

**Durasi: 5–7 menit · Bobot nilai video: 30%**
**Materi wajib:** Use Case · Activity · Class · Sequence · ERD pendukung · keterkaitan diagram↔implementasi · prototype interaktif.

### Naskah (panduan per segmen ± 6 menit)

**0:00–0:20 — Pembuka**
> "Proyek lanjutan Agung ATK. Saya akan menjelaskan empat diagram utama, ERD, dan prototype — serta bagaimana tiap diagram terhubung ke kode nyata."

**0:20–1:40 — Use Case (`docs/uml/use_case.md`)**
> "Ada lima aktor utama: Tamu, Pelanggan, Anggota, Kurir, Admin — plus aktor sekunder Sistem Notifikasi, LLM, dan layanan YOLO. Anggota saya pisahkan tegas dari Pelanggan: Anggota punya employee_code dan mengelola stok; Pelanggan hanya berbelanja."
> "Perhatikan relasi: checkout *include* login; verifikasi pembayaran dan penugasan kurir adalah *extend* dari pemrosesan pesanan. Saya tunjukkan skenario alternatif — misalnya saat stok kurang, transaksi checkout di-*rollback*."
> *Tunjukkan diagram + tunjuk 1 skenario alternatif.*

**1:40–3:00 — Activity Diagram (`docs/uml/activity.md`)**
> "Sembilan alur: login & deteksi role, kelola produk, pencarian teks & gambar, chatbot, checkout, pembayaran, pengiriman, notifikasi, tautan akun kanal."
> "**Kaitan ke kode:** alur chatbot persis seperti implementasi — ChatGuard menolak pertanyaan privasi *sebelum* model dipanggil, perintah `/menu` dijawab lokal, intent data di-query read-only ber-role, baru sisanya ke LLM, dan output disaring lagi."
> **Logika yang dijelaskan (bukan hanya nama):** sebutkan titik keputusan penting — transaksi checkout (BEGIN/COMMIT/ROLLBACK), dedupe notifikasi, fail-closed webhook.

**3:00–4:10 — Class Diagram (`docs/uml/class_diagram.md`)**
> "Digambar dari struktur kode aktual: 17 model dengan relasinya, lapisan service (NotificationManager, ChannelIdentityService, InboundChatService), dan support chatbot (AIService, ChatGuard, ChatbotDataService)."
> "SOLID: single responsibility kuat — transport kanal terpisah dari orkestrasi; kejujuran saya: belum ada interface kustom, terdokumentasi sebagai catatan pengembangan, bukan saya klaim sudah ada."

**4:10–5:20 — Sequence Diagram (`docs/uml/sequence_diagram.md`) & ERD**
> "Diagram sequence AI saya tunjukkan titik kritisnya: autentikasi dari session server, permission sebagai scope query, data hanya dari intent terbatas, pemrosesan AI dengan fallback bila API tidak tersedia, lalu pagaran output. ERD saya ambil langsung dari struktur database aktual — 25 tabel — sehingga dijamin konsisten dengan migration."
> *Tunjukkan 1 sequence (chatbot) dan 1 bagian ERD (orders–order_details–products).*

**5:20–6:30 — Prototype UI/UX**
> "Prototype interaktif ada di `docs/prototype/index.html` — satu file HTML/JS yang bisa dibuka langsung: silakan klik, ganti peran di kanan atas, nyalakan mode gelap, coba simulasi pencarian gambar (termasuk hasil *Barang Tidak Sesuai Kriteria Toko* dan *Stok Kosong*), dan chatbot."
> "Audit UI/UX saya dokumentasikan: 203 titik error per-field, skrip loading global, tema gelap/terang, 79 atribut aria — plus kelemahan jujur (pengujian mobile manual, aksesibilitas belum menyeluruh)."

**6:30–7:00 — Penutup**
> "Semua diagram tersimpan dalam format Mermaid yang bisa dirender — silakan buka filenya."

---

## C. VIDEO FINAL — LIVE DEMO APLIKASI

**Durasi: 8–12 menit · Bobot nilai video: 50%**
**Materi wajib:** elevator pitch · arsitektur · live demo web · demo role · proses bisnis · chatbot AI real-time · pengolahan data · unit test · status GitHub Actions · refleksi.

### Naskah (panduan ± 10 menit)

**0:00–0:45 — Elevator Pitch**
> "Agung ATK adalah sistem informasi penjualan toko ATK berbasis Laravel yang menutup siklus lengkap — katalog, pesanan, pembayaran, pengiriman, laporan — dengan empat peran berjenjang, notifikasi Telegram/WhatsApp/Email, dan dua fitur AI nyata: chatbot berbasis data terkontrol serta pencarian produk lewat foto berbasis YOLOv8 yang saya latih sendiri."

**0:45–1:30 — Arsitektur sistem**
> *Tampilkan bagan arsitektur (`docs/SDD_ARSITEKTUR_AGUNG_ATK.md` §1).*
> "Tiga lapis Laravel + MySQL 25 tabel; chatbot berlapis guard→data→LLM→guard; YOLO berjalan sebagai layanan Python terpisah di port 8090; notifikasi lewat queue dengan log."

**1:30–5:30 — Live demo aplikasi (inti video)**
Jalankan **benar-benar di browser**, bukan screenshot:

1. **Login berbagai role** — tunjukkan redirect otomatis & menu berbeda (Admin, Anggota, Kurir, Pelanggan).
2. **Proses bisnis penuh (pelanggan):** cari produk → tambah keranjang → checkout → unggah bukti bayar.
3. **Admin:** verifikasi pembayaran → pesanan berubah status → **tugaskan kurir** → lihat laporan + ekspor.
4. **Kurir:** login → hanya melihat tugasnya → perbarui status kirim → selesai.
5. **Stok & barcode (Anggota):** perbarui stok / pindai barcode.
6. **Pencarian gambar:** unggah foto → hasil YOLO (sertakan skenario "bukan ATK" dan "stok kosong").
7. **Notifikasi:** tunjukkan `notification_logs` / inbox Mailpit / chat Telegram sebagai bukti pesan keluar.

**5:30–7:00 — Chatbot AI real-time**
> Kirim langsung di widget: `siapa saya?` (role dari session), pertanyaan data nyata (`berapa penjualan bulan ini?` — admin), perintah `/menu` dan `/lacak TRK-…`, sapaan lintas bahasa (`piye kabare`, `hello`), lalu **satu pertanyaan umum** untuk menunjukkan mode AI.
> **Jujur bila API AI belum aktif:** "Kunci API saya sedang menunggu penggantian — lihat, sistem menjawab fallback jujur tanpa mengarang, dan ini tercakup tes."

**7:00–8:00 — Pengujian unit/feature**
> *Jalankan di terminal:* `php artisan test --compact` → tunjukkan **620/620 lulus, 2.940 asersi** (±5 menit — potong video bila perlu, tampilkan hasil akhir).

**8:00–8:30 — Status GitHub Actions**
> *Tampilkan tab Actions.* Jika sudah terhubung: tunjukkan run terakhir + warnanya. **Bila belum:** "Repository saya belum saya push — langkahnya sudah saya siapkan di `docs/TESTING_CI_CD_AGUNG_ATK.md` §6, dan saya akan menunjukkan run-nya setelah terhubung." **Jangan mengklaim hijau bila belum berjalan.**

**8:30–10:00 — Refleksi pembelajaran mandiri**
> "Tantangan terbesar saya: menjaga chatbot tetap aman tanpa memberi LLM akses SQL bebas — solusinya intent read-only ber-role; dan melatih YOLOv8 pada data ATK agar pencarian gambar benar-benar cocok ke katalog. Yang saya perbaiki dari audit: bug validasi webhook, import namespace, dan isolasi environment tes. Yang tersisa jujur: kredensial WhatsApp & kunci AI menunggu, video ini bagian saya selesaikan sendiri."

**10:00 — Penutup:** identitas + terima kasih.

---

## D. RUBRIK KHUSUS VIDEO (pemetaan)

| Kriteria | Bobot | Dipenuhi jika… |
|---|---:|---|
| Penguasaan materi & kejelasan narasi | 25% | Alur logis, tidak membaca mentah, istilah dipahami |
| Kualitas live demo aplikasi & fitur AI | 35% | Demo benar-benar berjalan (bukan screenshot), chatbot & pencarian gambar ditunjukkan nyata |
| Kedalaman penjelasan UML & arsitektur | 20% | Menjelaskan *hubungan* diagram ke kode, bukan menyebut nama diagram |
| Kualitas visual, audio, editing | 10% | Wajah jelas, suara jernih, layar terbaca, ≥720p |
| Manajemen waktu/durasi | 10% | Tepat rentang 3–5 / 5–7 / 8–12 menit |

## E. KETENTUAN REKAMAN (wajib)

- ✅ **Webcam aktif** — wajah mahasiswa terlihat jelas sepanjang presentasi.
- ✅ Resolusi **minimal 720p** (disarankan 1080p); audio jernih (headset/ruang tenang).
- ✅ Penjelasan **tidak hanya membaca teks** — arahkan kursor, klik aplikasi, tunjuk diagram.
- ✅ Progres 1: **3–5 menit** · Progres 2: **5–7 menit** · Final: **8–12 menit** + **live demo wajib**.
- ✅ Unggah ke **YouTube Unlisted/Public** atau **Google Drive** (akses *Anyone with the link*).
- ✅ Cantumkan tautan ketiga video di **README.md** (bagian yang sudah disediakan).
- ❌ Dilarang mengklaim video sudah direkam sebelum benar-benar diunggah.

## F. STATUS DOKUMEN INI

| Item | Status |
|---|---|
| Naskah Progres 1 | ✅ Siap direkam |
| Naskah Progres 2 | ✅ Siap direkam |
| Naskah Final | ✅ Siap direkam |
| Video direkam & diunggah | ❌ **BELUM** — dilakukan mahasiswa |
| Tautan di README | ⏳ Placeholder menunggu unggahan |
