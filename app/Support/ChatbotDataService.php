<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lapisan DATA AKTUAL untuk chatbot (PROMPT 8).
 *
 * Memetahkan maksud pertanyaan (intent) ke operasi baca-data terbatas,
 * menjalankan query Eloquent READ-ONLY yang sudah divalidasi & di-scope
 * hak akses di SERVER, lalu menyusun jawaban bahasa Indonesia yang alami.
 *
 * Prinsip keamanan:
 * - Read-only: tidak pernah menulis/mengubah data — perubahan hanya lewat
 *   fitur bisnis resmi (validasi + otorisasi + transaksi + konfirmasi).
 * - Tanpa SQL bebas: hanya intent berpola yang dikenali; nama tabel/kolom
 *   fixed pada kode service ini (bukan dari teks pengguna).
 * - Otorisasi dari session autentikasi: scope query ditentukan role NYATA
 *   user login — bukan klaim dalam percakapan.
 * - Hasil minimal: LIMIT pada daftar, agregasi SQL untuk hitungan besar.
 * - Data kosong / query gagal → jawaban jujur tanpa mengarang dan tanpa
 *   membocorkan detail query/infrastruktur ke pengguna.
 */
class ChatbotDataService
{
    /**
     * Status pesanan yang dihitung sebagai penjualan/pendapatan nyata
     * (pending & cancelled tidak dihitung — diverifikasi dari alur aplikasi).
     *
     * @var list<string>
     */
    private const SALES_STATUSES = ['paid', 'processing', 'ready_to_ship', 'shipping', 'delivered', 'completed'];

    /**
     * Status pengiriman yang masih menjadi tugas aktif kurir.
     *
     * @var list<string>
     */
    private const ACTIVE_SHIPMENT_STATUSES = ['waiting', 'picked_up', 'shipping'];

    /**
     * Batas baris untuk setiap daftar agar jawaban tetap ringkas.
     */
    private const MAX_LIST = 5;

    /**
     * Deteksi intent data lalu susun jawabannya.
     *
     * Mengembalikan null bila pesan BUKAN pertanyaan data — agar routing
     * chatbot lain (identitas, fitur, workflow, edukasi) tetap berjalan.
     */
    public function reply(string $lower, ?User $user): ?string
    {
        $intent = $this->detect($lower);

        if ($intent === null) {
            return null;
        }

        try {
            $answer = $this->answer($intent, $lower, $user);
        } catch (Throwable $e) {
            // Log aman: hanya intent + kelas pengecualian — tanpa SQL, nilai
            // data, atau kredensial. Pesan untuk pengguna tetap generik.
            Log::warning('chatbot.data_query_failed', [
                'intent' => $intent,
                'error' => class_basename($e),
            ]);

            return 'Maaf, data belum berhasil diperiksa karena terjadi kendala teknis, '
                .'dan saya tidak boleh menebak hasilnya. Silakan coba beberapa saat lagi '
                .'atau hubungi administrator bila masalah berlanjut.';
        }

        // Metrik teknis yang AMAN untuk evaluasi kualitas: hanya intent & peran
        // (tanpa isi pesan pengguna maupun nilai data).
        Log::info('chatbot.data_intent', [
            'intent' => $intent,
            'role' => $user?->role?->name ?? 'tamu',
        ]);

        return $answer;
    }

    /**
     * Pemetaan maksud pesan ke intent data (prioritas: spesifik dulu).
     *
     * Pertanyaan "bagaimana/kenapa/cara" (how-to) dikecualikan dari intent
     * angka agar tidak menjawab jumlah untuk pertanyaan penjelasan.
     */
    private function detect(string $lower): ?string
    {
        // Pertanyaan penjelasan — bukan permintaan angka.
        $isHowto = preg_match(
            '/\b(?:cara|bagaimana|gimana|kenapa|mengapa|kapan|menghitung|dihitung|hitung)\b/i',
            $lower
        ) === 1;

        // 1. Status pembayaran milik sendiri ("mengapa pembayaran belum dikonfirmasi").
        if ($this->matchesPaymentPending($lower)) {
            return 'payment_pending';
        }

        // 2. Nomor pesanan spesifik — lintas tabel, diskalakan per role.
        if (preg_match('/\b(ord[-_\s][\w-]{3,})\b/i', $lower) === 1) {
            return 'order_lookup';
        }

        // 2b. Resi pengiriman (tracking) — cakupan per role, anti baca lintas pengguna.
        if (preg_match('/\btrk[-_\s][\w-]{3,}\b/i', $lower) === 1
            || preg_match('/\bresi\s+[\w-]{4,}\b/i', $lower) === 1) {
            return 'tracking_lookup';
        }

        // 3. Pengiriman/pesanan milik sendiri (kurir -> penugasan aktif).
        if ($this->matchesMyShipment($lower)) {
            return 'my_shipment';
        }

        if ($isHowto) {
            return null;
        }

        // 4. Stok menipis (sebelum jumlah produk: "berapa produk stok hampir habis").
        if (preg_match(
            '/\bhampir\s+habis\b|\bmenipis\b|\bstok(?:nya)?\s+(?:rendah|tinggal|sisa|sedikit)\b|\bstok\s+rendah\b/i',
            $lower
        ) === 1) {
            return 'low_stock';
        }

        // 5. Produk terlaris (agregasi join order_details x orders).
        if (preg_match(
            '/\b(?:paling\s+(?:banyak\s+)?terjual|terlaris|paling\s+laku|terjual\s+paling\s+banyak|penjualan\s+terbanyak|top\s*\d*\s*penjualan)\b/i',
            $lower
        ) === 1) {
            return 'best_selling';
        }

        // 6. Pendapatan/transaksi dalam rentang waktu.
        if ($this->matchesRevenue($lower)) {
            return 'revenue';
        }

        // 7. Jumlah pesanan (bukan milik sendiri — itu urusan langkah "pesanan saya").
        if (! $this->isPossessive($lower) && $this->matchesOrdersCount($lower)) {
            return 'orders_count';
        }

        // 8. Jumlah produk di katalog (termasuk singkatan sehari-hari: brp, byk, jml).
        if (preg_match(
            '/\b(?:ada\s+(?:berapa(?:kah)?|brp)|(?:berapa(?:kah)?|brp|jumlah|jml|jmlh|total|banyak(?:nya)?|byk))\s+'
            .'(?:jumlah\s+|jml\s+|jmlh\s+|banyak\s+|byk\s+|jenis\s+|total\s+)*(?:produk|barang|item)\b/i',
            $lower
        ) === 1) {
            return 'product_count';
        }

        return null;
    }

    /**
     * Jalankan jawaban untuk satu intent (semua query di sini read-only).
     */
    private function answer(string $intent, string $lower, ?User $user): string
    {
        return match ($intent) {
            'payment_pending' => $this->paymentPendingReply($user),
            'order_lookup' => $this->orderLookupReply($lower, $user),
            'tracking_lookup' => $this->trackingLookupReply($lower, $user),
            'my_shipment' => $this->myShipmentReply($user),
            'low_stock' => $this->lowStockReply(),
            'best_selling' => $this->bestSellingReply(),
            'revenue' => $this->isStaff($user) ? $this->revenueReply($lower) : $this->staffOnlyReply(),
            'orders_count' => $this->isStaff($user) ? $this->ordersCountReply($lower) : $this->staffOnlyReply(),
            'product_count' => $this->productCountReply(),
            default => '',
        };
    }

    /* =====================================================================
     | PERTANYAAN ANGKA PUBLIK (katalog — tanpa data pribadi)
     * ===================================================================*/

    private function productCountReply(): string
    {
        $total = Product::count();

        if ($total === 0) {
            return 'Katalog Agung ATK saat ini belum memiliki produk tercatat. '
                .'Stok akan muncul setelah admin menambahkan produk.';
        }

        $active = Product::where('is_active', true)->count();

        return 'Saat ini ada '.$total.' produk tercatat di katalog '
            .'('.$active.' aktif dijual). Angka ini diambil langsung dari database sekarang.';
    }

    private function lowStockReply(): string
    {
        $rows = Product::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereColumn('stock', '<=', 'min_stock')
                    ->orWhere('stock', 0);
            })
            ->orderBy('stock')
            ->take(self::MAX_LIST)
            ->get(['name', 'stock', 'min_stock']);

        if ($rows->isEmpty()) {
            return 'Kabar baik: tidak ada produk aktif yang stoknya menipis — '
                .'semua berada di atas stok minimum.';
        }

        $lines = ['Produk dengan stok hampir habis (≤ stok minimum, diambil dari database):'];

        foreach ($rows->values() as $index => $product) {
            $lines[] = ($index + 1).'. '.$product->name
                .' — stok '.(int) $product->stock
                .' (minimum '.(int) $product->min_stock.')';
        }

        if ($rows->count() === self::MAX_LIST) {
            $lines[] = '(Menampilkan '.self::MAX_LIST.' terendah.)';
        }

        $lines[] = 'Restok bisa dilakukan lewat menu produk admin — bukan lewat chat.';

        return implode("\n", $lines);
    }

    private function bestSellingReply(): string
    {
        $rows = OrderDetail::query()
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->whereIn('orders.status', self::SALES_STATUSES)
            ->groupBy('order_details.product_name')
            ->select([
                'order_details.product_name as name',
                DB::raw('SUM(order_details.quantity) as sold'),
                DB::raw('SUM(order_details.subtotal) as revenue'),
            ])
            ->orderByDesc('sold')
            ->take(self::MAX_LIST)
            ->get();

        if ($rows->isEmpty()) {
            return 'Belum ada penjualan tercatat (pesanan berstatus paid sampai completed), '
                .'jadi daftar terlaris masih kosong. Angka akan muncul setelah ada pesanan berbayar.';
        }

        $lines = ['Produk ATK paling banyak terjual (dari pesanan berbayar s/d selesai):'];

        foreach ($rows->values() as $index => $row) {
            $lines[] = ($index + 1).'. '.$row->name
                .' — '.(int) $row->sold.' unit'
                .' (Rp '.number_format((float) $row->revenue, 0, ',', '.').')';
        }

        $lines[] = 'Total dihitung dari tabel order_details yang terhubung ke pesanan valid.';

        return implode("\n", $lines);
    }

    /* =====================================================================
     | ANGKA OPERASIONAL (STAF: admin & anggota saja)
     * ===================================================================*/

    private function ordersCountReply(string $lower): string
    {
        [$start, $end, $label] = $this->resolveRange($lower, null, 'sepanjang waktu');

        $query = Order::query();

        if ($start !== null) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            return 'Jumlah pesanan '.$label.' adalah 0 — belum ada pesanan masuk pada rentang tersebut.';
        }

        $breakdown = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $lines = ['Jumlah pesanan '.$label.': '.$count.' pesanan.'];
        $lines[] = 'Per status: '.$breakdown
            ->map(fn (int $n, string $status) => $status.' ('.$n.')')
            ->implode(', ').'.';
        $lines[] = 'Rincian per pesanan ada di halaman Pesanan (dashboard).';

        return implode("\n", $lines);
    }

    private function revenueReply(string $lower): string
    {
        // Default "bulan ini" bila rentang tidak disebut — sesuai pertanyaan
        // bulanan yang umum; label selalu ditampilkan agar tidak menyesatkan.
        [$start, $end, $label] = $this->resolveRange($lower, now()->startOfMonth(), 'bulan ini');

        $query = Order::query()->whereIn('status', self::SALES_STATUSES);

        if ($start !== null) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        $sum = (float) (clone $query)->sum('grand_total');
        $count = (clone $query)->count();

        return implode("\n", [
            'Total transaksi '.$label.': Rp '.number_format($sum, 0, ',', '.')
                .' dari '.$count.' pesanan berstatus paid sampai completed.',
            'Catatan: pesanan pending & dibatalkan tidak dihitung; angka dihitung '
                .'langsung dari database (read-only) — bukan perkiraan.',
        ]);
    }

    /* =====================================================================
     | DATA MILIK SENDIRI & PENUGASAN KURIR (SCOPE PER ROLE)
     * ===================================================================*/

    private function myShipmentReply(?User $user): string
    {
        if ($user === null) {
            return 'Anda belum login, jadi saya tidak punya data pengiriman yang bisa ditampilkan. '
                .'Silakan login untuk melihat status pesanan atau penugasan Anda sendiri.';
        }

        if ($user->role?->name === 'kurir') {
            return $this->courierAssignmentsReply($user);
        }

        return $this->ownTrackingReply($user);
    }

    private function courierAssignmentsReply(User $user): string
    {
        $shipments = Shipment::query()
            ->where('courier_id', $user->id)
            ->whereIn('status', self::ACTIVE_SHIPMENT_STATUSES)
            ->with('order:id,order_number,status')
            ->latest('id')
            ->take(self::MAX_LIST)
            ->get();

        if ($shipments->isEmpty()) {
            return 'Belum ada penugasan pengiriman aktif untuk akun ini. '
                .'Tugas baru muncul setelah admin menugaskan pesanan kepada Anda — '
                .'saya tidak menampilkan tugas kurir lain.';
        }

        $total = Shipment::where('courier_id', $user->id)->count();
        $lines = ['Penugasan aktif Anda ('.$shipments->count().' dari '.$total.' total):'];

        foreach ($shipments->values() as $index => $shipment) {
            $lines[] = ($index + 1).'. '.$shipment->order?->order_number
                .' — pengiriman '.$shipment->status
                .' (pesanan: '.($shipment->order?->status ?? '-').')'
                .' — resi '.$shipment->tracking_number;
        }

        $lines[] = 'Detail & pembaruan status ada di menu Kurir (tugas yang ditugaskan kepada Anda saja).';

        return implode("\n", $lines);
    }

    private function ownTrackingReply(User $user): string
    {
        $order = Order::where('user_id', $user->id)->latest('id')->first();

        if ($order === null) {
            return 'Anda belum memiliki pesanan. Setelah checkout dari Katalog, '
                .'status & pengirimannya bisa dipantau lewat menu "Pesanan Saya".';
        }

        $shipment = Shipment::where('order_id', $order->id)->first();
        $payment = Payment::where('order_id', $order->id)->first();

        $lines = ['Pesanan terbaru Anda: '.$order->order_number
            .' — status '.$order->status
            .', Rp '.number_format((float) $order->grand_total, 0, ',', '.')
            .' ('.$order->created_at?->format('d M Y H:i').' WIB).'];

        if ($payment !== null) {
            $lines[] = 'Pembayaran: '.$payment->status.'.';
        }

        if ($shipment === null) {
            $lines[] = 'Pengiriman: belum ada — pesanan belum diserahkan ke kurir.';
        } else {
            $lines[] = 'Pengiriman: '.$shipment->status
                .' — resi '.$shipment->tracking_number
                .($shipment->courier_name !== null ? ', kurir '.$shipment->courier_name : '').'.';
        }

        $lines[] = 'Rincian lengkap ada di menu "Pesanan Saya".';

        return implode("\n", $lines);
    }

    /* =====================================================================
     | LOOKUP NOMOR PESANAN (LINTAS TABEL, ANTI-BACA-LINTAS-PENGGGUNA)
     * ===================================================================*/

    private function orderLookupReply(string $lower, ?User $user): string
    {
        preg_match('/\b(ord[-_\s][\w-]{3,})\b/i', $lower, $matches);
        $orderNumber = trim((string) ($matches[1] ?? ''));

        if ($user === null) {
            return 'Silakan login untuk melihat detail pesanan — saya tidak menampilkan '
                .'detail pesanan kepada tamu.';
        }

        // LIKE tanpa wildcard: kandidat hanya huruf/angka/dash (hasil regex),
        // sehingga pencocokan tidak peka huruf besar-kecil di semua engine.
        $order = Order::where('order_number', 'like', $orderNumber)->first();

        // Satu pesan untuk "tidak ada" DAN "bukan milik Anda" — mencegah
        // penelusuran keberadaan pesanan orang lain dari chat.
        $refusal = 'Pesanan dengan nomor "'.$orderNumber.'" tidak ditemukan atau bukan milik Anda. '
            .'Saya hanya menampilkan pesanan yang berhak Anda akses.';

        if ($order === null) {
            return $refusal;
        }

        if ($user->role?->name === 'kurir') {
            $shipment = Shipment::where('order_id', $order->id)->first();

            if ($shipment === null || $shipment->courier_id !== $user->id) {
                return 'Pesanan tersebut tidak ditugaskan kepada Anda, jadi detailnya tidak '
                    .'dapat saya tampilkan. Fokus pada tugas di menu Kurir saja.';
            }

            return $this->orderSummary($order, $shipment);
        }

        if (! $user->isAdmin() && ! $user->isAnggota() && $order->user_id !== $user->id) {
            return $refusal;
        }

        $shipment = Shipment::where('order_id', $order->id)->first();

        return $this->orderSummary($order, $shipment);
    }

    /**
     * Lookup resi pengiriman (tracking) — read-only & diskop per peran.
     * Satu pesan untuk "tidak ada" DAN "bukan milik Anda" agar tidak ada
     * penelusuran kepemilikan pengiriman orang lain dari chat.
     */
    private function trackingLookupReply(string $lower, ?User $user): string
    {
        $code = null;

        if (preg_match('/\btrk[-_\s][\w-]{3,}\b/i', $lower, $matches) === 1) {
            // Normalisasi "trk 001" / "trk_001" -> "TRK-001"
            $code = mb_strtoupper((string) preg_replace('/[\s_]+/', '-', $matches[0]));
        } elseif (preg_match('/\bresi\s+([\w-]{4,})\b/i', $lower, $matches) === 1) {
            $code = mb_strtoupper($matches[1]);
        }

        if ($code === null) {
            return 'Sebutkan nomor resi yang ingin dilacak, contoh: /lacak TRK-12345 '
                .'atau /lacak resi TRK-12345.';
        }

        if ($user === null) {
            return 'Silakan login untuk melacak pengiriman — saya tidak menampilkan '
                .'data pengiriman kepada tamu.';
        }

        $shipment = Shipment::with('order:id,order_number,user_id,status')
            ->where('tracking_number', 'like', $code)
            ->first();

        $refusal = 'Pengiriman dengan resi "'.$code.'" tidak ditemukan atau bukan milik Anda. '
            .'Saya hanya menampilkan pengiriman yang berhak Anda akses.';

        if ($shipment === null) {
            return $refusal;
        }

        $role = $user->role?->name;

        if ($role === 'kurir' && $shipment->courier_id !== $user->id) {
            return 'Pengiriman tersebut tidak ditugaskan kepada Anda, jadi detailnya tidak '
                .'dapat saya tampilkan. Fokus pada tugas di menu Kurir saja.';
        }

        if ($role === 'pelanggan' && $shipment->order?->user_id !== $user->id) {
            return $refusal;
        }

        return implode("\n", [
            'Pengiriman resi '.$shipment->tracking_number.':',
            '- Pesanan: '.$shipment->order?->order_number.' (status '.$shipment->order?->status.')',
            '- Status pengiriman: '.$shipment->status
                .($shipment->courier_name !== null ? ' — kurir '.$shipment->courier_name : ''),
            '- Diperbarui: '.($shipment->updated_at?->format('d M Y H:i') ?? '-').' WIB',
            'Rincian lengkap ada di menu "Pesanan Saya".',
        ]);
    }

    /**
     * Ringkasan satu pesanan untuk penerima yang BERHAK (tanpa data pribadi
     * pihak lain — hanya nomor, status, total, pembayaran, pengiriman).
     */
    private function orderSummary(Order $order, ?Shipment $shipment): string
    {
        $payment = Payment::where('order_id', $order->id)->first();

        $lines = ['Pesanan '.$order->order_number.': status '.$order->status
            .', Rp '.number_format((float) $order->grand_total, 0, ',', '.')
            .' — dibuat '.$order->created_at?->format('d M Y H:i').' WIB.'];

        $lines[] = 'Pembayaran: '.($payment !== null
            ? $payment->status.' ('.$payment->method.')'
            : 'belum ada.').'.';

        $lines[] = 'Pengiriman: '.($shipment !== null
            ? $shipment->status.' — resi '.$shipment->tracking_number
            : 'belum dibuat.').'.';

        $lines[] = 'Rincian lengkap ada pada halaman detail pesanan.';

        return implode("\n", $lines);
    }

    /* =====================================================================
     | PENJELASAN STATUS PEMBAYARAN (MILIK SENDIRI)
     * ===================================================================*/

    private function paymentPendingReply(?User $user): string
    {
        if ($user === null) {
            return 'Anda belum login, jadi saya belum bisa memeriksa status pembayaran Anda. '
                .'Silakan login, lalu cek riwayat pembayaran di menu "Pesanan Saya".';
        }

        $payment = Payment::whereIn(
            'order_id',
            Order::where('user_id', $user->id)->select('id')
        )->latest('id')->first();

        if ($payment === null) {
            return 'Belum ada riwayat pembayaran pada akun ini, jadi tidak ada yang menunggu '
                .'verifikasi. Pembayaran dibuat setelah Anda melakukan checkout.';
        }

        $amount = 'Rp '.number_format((float) $payment->amount, 0, ',', '.');

        return match ($payment->status) {
            'pending' => implode("\n", [
                'Pembayaran '.$payment->payment_number.' ('.$amount.', '.$payment->method.') '
                    .'masih PENDING — menunggu verifikasi ADMIN.',
                'Penjelasan: bukti pembayaran diverifikasi manual oleh admin, bukan otomatis. '
                    .'Setelah disetujui, status berubah menjadi verified dan pesanan lanjut ke tahap berikutnya.',
                'Jika sudah lama tanpa kabar, hubungi admin — bukan karena chatbot ini yang memproses.',
            ]),
            'verified' => 'Pembayaran '.$payment->payment_number.' ('.$amount.') sudah '
                .'TERKONFIRMASI (verified'
                .($payment->verified_at !== null ? ' sejak '.$payment->verified_at->format('d M Y H:i') : '')
                .'). Pesanan akan diproses sesuai alur toko.',
            'rejected' => 'Pembayaran '.$payment->payment_number.' DITOLAK oleh admin'
                .($payment->rejection_reason !== null
                    ? ' dengan alasan: '.mb_substr($payment->rejection_reason, 0, 200)
                    : '.')
                .' Silakan periksa kembali bukti transfer Anda, lalu unggah ulang lewat menu pembayaran.',
            default => 'Status pembayaran '.$payment->payment_number.': '.$payment->status
                .'. Rincian lengkap ada di menu "Pesanan Saya".',
        };
    }

    /* =====================================================================
     | UTILITAS INTENT, WAKTU, & HAK AKSES
     * ===================================================================*/

    private function matchesPaymentPending(string $lower): bool
    {
        return preg_match(
            '/\b(?:kenapa|mengapa|kok|kenpapa|knapa)\b[\s\S]{0,50}\b(?:pembayaran|bayar|transfer|bukti)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:pembayaran|bayar)\s*(?:saya|aku)?\s*(?:belum|tidak|kok)\s*'
            .'(?:dikonfirmasi|diverifikasi|dicek|masuk|berhasil|diproses)\b/i',
            $lower
        ) === 1;
    }

    private function matchesMyShipment(string $lower): bool
    {
        // Penugasan kurir ("pengiriman yang ditugaskan ke saya") langsung cocok.
        if (preg_match('/\bditugaskan\b|\btugaskan\s+ke\b/i', $lower) === 1) {
            return true;
        }

        $possessive = preg_match(
            '/\b(?:pesanan|order|pengiriman|paket|barang)\s+(?:saya|ku|aku|gw|gue)\b/i',
            $lower
        ) === 1;

        if (! $possessive) {
            return false;
        }

        // Milik sendiri + kata progres/status ("pesanan saya sudah sampai mana").
        // Tanpa "berapa"/"cek": pertanyaan hitungan ("Berapa pesanan saya?")
        // tetap dijawab langkah ringkasan pesanan lama.
        return preg_match(
            '/\b(?:sudah|udah|sampai|nyampe|kemana|di\s+kemana|di\s+mana|dimana|status|'
            .'dikirim|jalan|gimana|bagaimana|tracking|lacak|daftar|list|'
            .'terakhir|aktif|terkini|posisi)\b/i',
            $lower
        ) === 1;
    }

    private function matchesRevenue(string $lower): bool
    {
        // "pendapatan"/"laba" sengaja TANPA di sini — pertanyaan pendapatan
        // tetap dijawab langkah ringkasan operasional lama (kontrak tes lama).
        return preg_match(
            '/\b(?:berapa(?:kah)?|brp|jumlah|jml|total)\s+(?:jumlah\s+|jml\s+|total\s+)*'
            .'(?:transaksi|penjualan|omzet|revenue)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:omzet|revenue|transaksi|penjualan)\b[\s\S]{0,15}\b(?:berapa(?:kah)?|brp|jumlah|jml|total)\b/i',
            $lower
        ) === 1;
    }

    private function matchesOrdersCount(string $lower): bool
    {
        return preg_match(
            '/\b(?:berapa(?:kah)?|brp|jumlah|jml|total|banyak(?:nya)?|byk)\s+(?:jumlah\s+|jml\s+|total\s+|banyak\s+|byk\s+)*'
            .'(?:pesanan|order)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:pesanan|order)\b[\s\S]{0,25}\b(?:hari\s+ini|kemarin|minggu\s+ini|bulan\s+ini|tahun\s+ini)\b/i',
            $lower
        ) === 1;
    }

    private function isPossessive(string $lower): bool
    {
        return preg_match(
            '/\b(?:pesanan|order|transaksi|penjualan)\s*(?:saya|ku|aku|gw|gue|milik\s+saya)\b/i',
            $lower
        ) === 1;
    }

    /**
     * Rentang waktu relatif bahasa Indonesia.
     *
     * @param  Carbon|null  $defaultStart  bila tidak ada rentang disebut
     * @return array{0: Carbon|null, 1: Carbon|null, 2: string}
     */
    private function resolveRange(string $lower, $defaultStart, string $defaultLabel): array
    {
        if (preg_match('/\bhari\s+ini\b|\btoday\b/i', $lower) === 1) {
            return [now()->startOfDay(), now()->endOfDay(), 'hari ini'];
        }

        if (preg_match('/\bkemarin\b/i', $lower) === 1) {
            return [now()->subDay()->startOfDay(), now()->subDay()->endOfDay(), 'kemarin'];
        }

        if (preg_match('/\bminggu\s+ini\b/i', $lower) === 1) {
            return [now()->startOfWeek(), now()->endOfWeek(), 'minggu ini'];
        }

        if (preg_match('/\bbulan\s+lalu\b/i', $lower) === 1) {
            return [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
                'bulan lalu',
            ];
        }

        if (preg_match('/\bbulan\s+ini\b/i', $lower) === 1) {
            return [now()->startOfMonth(), now()->endOfMonth(), 'bulan ini'];
        }

        if (preg_match('/\btahun\s+ini\b|\byear\b/i', $lower) === 1) {
            return [now()->startOfYear(), now()->endOfYear(), 'tahun ini'];
        }

        if (preg_match('/\b(?:sepanjang\s+waktu|semua\s+waktu|total\s+seluruh|keseluruhan)\b/i', $lower) === 1) {
            return [null, null, 'sepanjang waktu'];
        }

        return [$defaultStart, $defaultStart !== null ? now()->endOfMonth() : null, $defaultLabel];
    }

    private function isStaff(?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $user->isAnggota());
    }

    /**
     * Penolakan angka operasional untuk peran non-staf — tanpa angka apa pun.
     */
    private function staffOnlyReply(): string
    {
        return 'Data operasional toko (jumlah pesanan & pendapatan) hanya tersedia untuk '
            .'peran admin dan anggota (staf toko) — saya tidak menampilkan angka internal kepada akun lain. '
            .'Yang bisa saya bantu untuk Anda: status pesanan/pengiriman sendiri, info produk & stok, '
            .'serta panduan fitur.';
    }
}
