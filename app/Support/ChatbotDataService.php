<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
     * Batas baris daftar akun pelanggan (hanya admin).
     */
    private const MAX_CUSTOMER_LIST = 10;

    /**
     * Penolakan baku atas pertanyaan informasi AKUN yang tidak berhak
     * diakses peran tersebut (spesifikasi privasi chatbot).
     */
    private const ACCOUNT_DENIED_REPLY = 'Maaf, Anda tidak memiliki izin untuk melihat informasi akun tersebut.';

    /**
     * Penolakan baku atas pertanyaan informasi akun bagi tamu (belum login).
     */
    private const GUEST_ACCOUNT_REPLY = 'Anda belum login — silakan login terlebih dahulu '
        .'untuk menanyakan informasi akun pelanggan.';

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

        // 3b. Nama pelanggan terkait penugasan/pesanan SENDIRI
        //     ("siapa nama pelanggan pesanan yang saya antar?") — diskop per
        //     peran; bukan pencarian daftar global.
        if ($this->matchesShipmentCustomer($lower)) {
            return 'shipment_customer';
        }

        // 3b2. Peringkat transaksi pelanggan ("pelanggan dengan transaksi
        //      terbanyak") — spesifik, dihitung sebelum hitungan/daftar
        //      agar tidak salah tertangkap intent pelanggan umum.
        if ($this->matchesTopCustomer($lower)) {
            return 'top_customer';
        }

        // 3c. Informasi akun pelanggan: hitung jumlah → daftar → pencarian
        //     nama/username. Otorisasi per role ditegakkan saat jawaban
        //     disusun; deteksi di sini hanya memetakan maksud pesan.
        if ($this->matchesCustomerCount($lower)) {
            return 'customer_count';
        }

        if ($this->matchesCustomerList($lower)) {
            return 'customer_list';
        }

        if ($this->matchesCustomerSearch($lower)) {
            return 'customer_search';
        }

        // 4a. Stok terbanyak & produk terbaru — diprioritaskan sebelum pola
        //     "stok menipis" dan hitungan jumlah produk.
        if ($this->matchesTopStock($lower)) {
            return 'top_stock';
        }

        if ($this->matchesNewestProducts($lower)) {
            return 'newest_products';
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

        // 5b. Intent data baru (Bagian 4 MASTER): pengguna (per role &
        //     terbaru), transaksi belum dibayar, serta pesanan terbaru —
        //     diprioritaskan sebelum hitungan pendapatan/pesanan umum agar
        //     tidak tertangkap intent yang lebih luas.
        if ($this->matchesUsersByRole($lower)) {
            return 'users_by_role';
        }

        if ($this->matchesNewestUsers($lower)) {
            return 'newest_users';
        }

        if (! $this->isPossessive($lower) && $this->matchesPendingPayments($lower)) {
            return 'pending_payments';
        }

        if (! $this->isPossessive($lower) && $this->matchesNewestOrders($lower)) {
            return 'newest_orders';
        }

        // 6. Pendapatan/transaksi dalam rentang waktu.
        if ($this->matchesRevenue($lower)) {
            return 'revenue';
        }

        // 7. Jumlah pesanan (bukan milik sendiri — itu urusan langkah "pesanan saya").
        if (! $this->isPossessive($lower) && $this->matchesOrdersCount($lower)) {
            return 'orders_count';
        }

        // 8. Jumlah produk di katalog (termasuk singkatan sehari-hari: brp,
        //    byk, jml — dan penegas "seluruh/semua produk").
        if (preg_match(
            '/\b(?:ada\s+(?:berapa(?:kah)?|brp)|(?:berapa(?:kah)?|brp|jumlah|jml|jmlh|total|banyak(?:nya)?|byk))\s+'
            .'(?:jumlah\s+|jml\s+|jmlh\s+|banyak\s+|byk\s+|jenis\s+|total\s+|'
            .'seluruh\s+|semua\s+|keseluruhan\s+)*(?:produk|barang|item)\b/i',
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
            'shipment_customer' => $this->shipmentCustomerReply($user),
            'customer_count' => $this->customerCountReply($user),
            'customer_list' => $this->customerListReply($user),
            'customer_search' => $this->customerSearchReply($lower, $user),
            'top_customer' => $this->topCustomerReply($user),
            'top_stock' => $this->topStockReply(),
            'newest_products' => $this->newestProductsReply(),
            'users_by_role' => $this->isStaff($user) ? $this->usersByRoleReply() : $this->staffOnlyReply(),
            'newest_users' => $this->isAdmin($user) ? $this->newestUsersReply() : self::ACCOUNT_DENIED_REPLY,
            'pending_payments' => $this->isStaff($user) ? $this->pendingPaymentsReply($lower) : $this->staffOnlyReply(),
            'newest_orders' => $this->isStaff($user) ? $this->newestOrdersReply() : $this->staffOnlyReply(),
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

    private function topStockReply(): string
    {
        $rows = Product::query()
            ->where('is_active', true)
            ->orderByDesc('stock')
            ->take(self::MAX_LIST)
            ->get(['name', 'stock', 'unit']);

        if ($rows->isEmpty()) {
            return 'Katalog saat ini tidak memiliki produk aktif yang bisa dibandingkan stoknya.';
        }

        $lines = ['Produk dengan stok terbanyak (produk aktif, dihitung langsung dari database):'];

        foreach ($rows->values() as $index => $product) {
            $lines[] = ($index + 1).'. '.$product->name
                .' — stok '.(int) $product->stock.' '.($product->unit ?: 'pcs');
        }

        $lines[] = 'Stok berubah mengikuti penjualan & restok — angka ini dihitung ulang setiap kali Anda bertanya.';

        return implode("\n", $lines);
    }

    private function newestProductsReply(): string
    {
        $rows = Product::query()
            ->with('category:id,name')
            ->latest('id')
            ->take(self::MAX_LIST)
            ->get(['id', 'category_id', 'name', 'price', 'stock', 'is_active']);

        if ($rows->isEmpty()) {
            return 'Katalog Agung ATK saat ini belum memiliki produk tercatat.';
        }

        $total = Product::count();
        $lines = ['Produk terbaru di katalog (menampilkan '.$rows->count().' dari '.$total.' produk):'];

        foreach ($rows->values() as $index => $product) {
            $lines[] = ($index + 1).'. '.$product->name
                .' — Rp '.number_format((float) $product->price, 0, ',', '.')
                .' — stok '.(int) $product->stock
                .' — '.($product->is_active ? 'aktif' : 'nonaktif')
                .($product->category?->name !== null ? ' — kategori '.$product->category->name : '');
        }

        $lines[] = 'Katalog lengkap dengan gambar ada di halaman Katalog.';

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

    /**
     * "Berapa transaksi yang belum dibayar?" — pesanan berstatus pending,
     * dipecah antara yang sudah mengunggah bukti (menunggu verifikasi)
     * dan yang sama sekali belum ada bukti pembayaran.
     */
    private function pendingPaymentsReply(string $lower): string
    {
        [$start, $end, $label] = $this->resolveRange($lower, null, 'sepanjang waktu');

        $query = Order::query()->where('status', 'pending');

        if ($start !== null) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            return 'Tidak ada pesanan berstatus pending (belum dibayar) pada rentang '.$label.'.';
        }

        $withProof = (clone $query)->whereHas('payment')->count();

        return implode("\n", [
            'Pesanan belum dibayar (status pending) '.$label.': '.$count.' pesanan.',
            'Sudah mengunggah bukti pembayaran (menunggu verifikasi admin): '.$withProof.' pesanan.',
            'Belum ada unggahan bukti: '.($count - $withProof).' pesanan.',
            'Angka dihitung langsung dari database (read-only); verifikasi pembayaran '
                .'dilakukan admin lewat menu Pembayaran — bukan lewat chat.',
        ]);
    }

    /**
     * "Transaksi/pesanan terbaru apa?" — daftar pesanan terakhir masuk
     * (bukan milik sendiri; itu jalur pesanan saya).
     */
    private function newestOrdersReply(): string
    {
        $orders = Order::with('user:id,name')
            ->latest('id')
            ->take(self::MAX_LIST)
            ->get(['id', 'order_number', 'user_id', 'status', 'grand_total', 'created_at']);

        if ($orders->isEmpty()) {
            return 'Belum ada pesanan tercatat di sistem Agung ATK.';
        }

        $total = Order::count();
        $lines = ['Pesanan terbaru masuk (menampilkan '.$orders->count().' dari '.$total.' pesanan):'];

        foreach ($orders->values() as $index => $order) {
            $lines[] = ($index + 1).'. '.$order->order_number
                .' — '.($order->user?->name ?? '-')
                .' — status '.$order->status
                .' — Rp '.number_format((float) $order->grand_total, 0, ',', '.')
                .' — '.($order->created_at?->format('d M Y H:i') ?? '-').' WIB';
        }

        $lines[] = 'Rincian & pembaruan status ada di halaman Pesanan (dashboard).';

        return implode("\n", $lines);
    }

    /**
     * "Berapa jumlah pengguna berdasarkan role?" — agregat per peran
     * (tanpa menampilkan nama/email akun per satu).
     */
    private function usersByRoleReply(): string
    {
        $total = User::count();

        if ($total === 0) {
            return 'Belum ada akun pengguna tercatat di sistem.';
        }

        $byRole = User::query()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->groupBy('roles.name')
            ->selectRaw('roles.name as role_name, COUNT(*) as total')
            ->orderByDesc('total')
            ->pluck('total', 'role_name');

        $lines = ['Jumlah pengguna terdaftar: '.$total.' akun.'];
        $lines[] = 'Per peran: '.$byRole
            ->map(fn ($count, string $role) => $role.' ('.$count.')')
            ->implode(', ').'.';
        $lines[] = 'Rincian akun ada di menu Pengguna (dashboard admin).';

        return implode("\n", $lines);
    }

    /**
     * "Siapa pengguna terbaru?" — daftar akun terakhir terdaftar (admin
     * saja; menampilkan nama, peran, status, waktu — tanpa email/HP).
     */
    private function newestUsersReply(): string
    {
        $users = User::with('role:id,name')
            ->latest('id')
            ->take(self::MAX_LIST)
            ->get(['id', 'name', 'role_id', 'is_active', 'created_at']);

        if ($users->isEmpty()) {
            return 'Belum ada akun pengguna tercatat di sistem.';
        }

        $total = User::count();
        $lines = ['Pengguna terbaru terdaftar (menampilkan '.$users->count().' dari '.$total.' akun):'];

        foreach ($users->values() as $index => $account) {
            $lines[] = ($index + 1).'. '.$account->name
                .' — peran '.($account->role?->name ?? '-')
                .' — '.((bool) $account->is_active ? 'aktif' : 'nonaktif')
                .' — '.($account->created_at?->format('d M Y H:i') ?? '-').' WIB';
        }

        $lines[] = 'Pengaturan akun (aktif/nonaktif & peran) ada di menu Pengguna.';

        return implode("\n", $lines);
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
     * Ringkasan satu pesanan untuk penerima yang BERHAK (hanya nomor,
     * status, total, pembayaran, pengiriman, serta nama pelanggan pesanan —
     * penerima sudah lolos pemeriksaan otorisasi sebelum fungsi ini dipanggil).
     */
    private function orderSummary(Order $order, ?Shipment $shipment): string
    {
        $payment = Payment::where('order_id', $order->id)->first();

        $lines = ['Pesanan '.$order->order_number.': status '.$order->status
            .', Rp '.number_format((float) $order->grand_total, 0, ',', '.')
            .' — dibuat '.$order->created_at?->format('d M Y H:i').' WIB.'];

        if ($order->user?->name !== null) {
            $lines[] = 'Pelanggan: '.$order->user->name.'.';
        }

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

    /* =====================================================================
     | INFORMASI AKUN PELANGGAN — IDENTITAS, HITUNGAN, DAFTAR, PENCARIAN
     | (baca-only; otorisasi per role ditegakkan di SERVER)
     * ===================================================================*/

    /**
     * Cakupan informasi akun pelanggan per role dari session backend.
     *
     * - full: admin — hitung, daftar, dan pencarian nama/username.
     * - search: anggota — hitung & pencarian nama spesifik; tanpa daftar
     *   seluruh akun (anggota bukan pengelola akun).
     * - none: kurir & pelanggan — hanya identitas & penugasan sendiri.
     * - guest: tamu — belum login.
     *
     * @return 'full'|'search'|'none'|'guest'
     */
    private function customerAccountScope(?User $user): string
    {
        if ($user === null) {
            return 'guest';
        }

        if ($user->isAdmin()) {
            return 'full';
        }

        if ($user->isAnggota()) {
            return 'search';
        }

        return 'none';
    }

    /**
     * Query builder akun pelanggan (role 'pelanggan') — dipakai bersama
     * oleh hitungan, daftar, dan pencarian.
     *
     * @return Builder<User>
     */
    private function customerQuery()
    {
        return User::whereHas('role', function ($query): void {
            $query->where('name', 'pelanggan');
        });
    }

    /**
     * "Siapa nama pelanggan pesanan yang saya antar?" — pertanyaan tentang
     * pelanggan yang terkait dengan penugasan/pesanan yang disebut pengguna.
     * BUKAN pencarian daftar global.
     */
    private function matchesShipmentCustomer(string $lower): bool
    {
        if (preg_match('/\b(?:pelanggan|customer|pemesan|penerima)\b/', $lower) !== 1) {
            return false;
        }

        return preg_match('/\b(?:saya|ku|aku|gue|gw)\s+antar(?:kan)?\b|\bsaya\s+kirim\b/i', $lower) === 1
            || preg_match('/\b(?:pesanan|pengiriman|paket|tugas|order)\s+(?:saya|ku|aku|gue|gw)\b/i', $lower) === 1
            || preg_match('/\byang\s+(?:saya\s+)?antar\b/i', $lower) === 1;
    }

    /**
     * "Berapa jumlah pelanggan yang terdaftar?" — termasuk singkatan
     * sehari-hari (brp, jml, byk) dan urutan terbalik.
     */
    private function matchesCustomerCount(string $lower): bool
    {
        return preg_match(
            '/\b(?:berapa(?:kah)?|brp|jumlah|jml|jmlh|total|banyak(?:nya)?|byk)\s+'
            .'(?:jumlah\s+|jml\s+|jmlh\s+|banyak\s+|byk\s+|orang\s+|akun\s+|total\s+)*'
            .'(?:pelanggan|customer)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:pelanggan|customer)\b[\s\S]{0,30}\b(?:berapa(?:kah)?|brp|jumlah|jml|total)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:ada|punya)\s+(?:berapa(?:kah)?|brp)\s+(?:orang\s+|akun\s+)?(?:pelanggan|customer)\b/i',
            $lower
        ) === 1;
    }

    /**
     * "Sebutkan/daftar pelanggan yang terdaftar" — permintaan daftar.
     * Pertanyaan yang menyebut nama/username spesifik dialihkan ke pencarian.
     */
    private function matchesCustomerList(string $lower): bool
    {
        if (preg_match(
            '/\b(?:bernama|dengan\s+nama|namanya|atas\s+nama|username|email)\b'
            .'|\bnama\s+(?:pelanggan|customer)\s+(?!yang|terdaftar|ada\b)/i',
            $lower
        ) === 1) {
            return false;
        }

        return preg_match(
            '/\b(?:sebutkan|daftarkan|daftar|tampilkan|list|sebut|tulis)\b[\s\S]{0,50}'
            .'(?:daftar\s+|nama[-\s]?nama\s+|semua\s+|seluruh\s+)*(?:pelanggan|customer)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\bsiapa\s+(?:saja|sajakah)?\s*(?:pelanggan|customer)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\bnama[-\s]?nama\s+(?:pelanggan|customer)\b/i',
            $lower
        ) === 1;
    }

    /**
     * Pencarian akun pelanggan by nama/username — hanya bila pesan punya
     * konteks akun + penanda pencarian, ATAU pola "X sudah terdaftar",
     * dan bukan pertanyaan tentang diri sendiri (itu urusan jalur identitas).
     */
    private function matchesCustomerSearch(string $lower): bool
    {
        // Pertanyaan diri sendiri → biarkan jalur identitas (siapa saya /
        // sudah terdaftar) yang menjawab, bukan pencarian pelanggan.
        if (preg_match('/\b(?:saya|sya|aku|aq|gue|gw|diri\s+(?:saya|ku))\b/', $lower) === 1) {
            return false;
        }

        $hasCustomerContext = preg_match(
            '/\b(?:pelanggan|customer|username|user\s*name|akun|email)\b/i',
            $lower
        ) === 1;

        $hasSearchMarker = preg_match(
            '/\b(?:bernama|namanya|nama|terdaftar|teregistrasi|daftar|register|registrasi|'
            .'cari|carikan|cek|temukan|punya\s+akun|tersedia|siapa|ada)\b/i',
            $lower
        ) === 1;

        if ($hasCustomerContext && $hasSearchMarker) {
            return true;
        }

        // "agung gumelar sudah terdaftar belum?" — tanpa kata "pelanggan".
        return preg_match(
            '/\b[\w\s.@-]{3,60}?\s+(?:sudah|udah|pernah)\s+'
            .'(?:terdaftar|teregistrasi|daftar|register)\b/i',
            $lower
        ) === 1;
    }

    /**
     * "Pelanggan dengan transaksi terbanyak?" — peringkat transaksi,
     * dipisah dari hitungan jumlah & daftar alfabetis pelanggan.
     */
    private function matchesTopCustomer(string $lower): bool
    {
        return preg_match(
            '/\b(?:pelanggan|customer)\b[\s\S]{0,40}\b(?:terbanyak|paling\s+banyak|paling\s+sering)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:terbanyak|paling\s+banyak|paling\s+sering)\b[\s\S]{0,40}\b(?:pelanggan|customer)\b/i',
            $lower
        ) === 1;
    }

    /**
     * Ambil kata kunci nama/username dari pesan pencarian. Hanya kata yang
     * tersisa setelah membuang kata penghubung — dipakai sebagai parameter
     * LIKE terikat (tanpa SQL bebas; wildcard pengguna di-escape).
     */
    private function customerSearchKeyword(string $lower): string
    {
        // Email/username login yang ditulis pengguna dipakai apa adanya —
        // jangan dipecah oleh pembersih tanda baca atau kata penghubung.
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $lower, $emailMatch) === 1) {
            return $emailMatch[0];
        }

        // Tanda baca kalimat dibuang; titik hanya bila di akhir kata/kalimat
        // agar username seperti "agung.username" tetap utuh.
        $keyword = (string) preg_replace('/[?!,;:]+|\.+(?=\s|$)/', ' ', $lower);
        $keyword = (string) preg_replace(
            '/\b(?:di\s+)?(?:sistem\s+)?agung\s+atk\b/',
            ' ',
            $keyword
        );
        $keyword = (string) preg_replace(
            '/\b(?:siapa|apakah|adakah|ada|kah|sudah|udah|pernah|terdaftar|teregistrasi|'
            .'daftar|register|registrasi|belum|blm|bisa|dapat|tersedia|pelanggan|customer|'
            .'akun|username|user|email|nama|bernama|dengan|namanya|orang|ini|tersebut|yang|'
            .'untuk|cari|carikan|cek|temukan|tolong|dong|mas|mbak|kak|pak|bu|list|sebutkan|'
            .'tampilkan|lihat|periksa|sistem|punya|memiliki|aktif|nonaktif|tidak|bukan|iya|'
            .'ya|nomor|id|nik|punyakah|akah)\b/',
            ' ',
            $keyword
        );
        $keyword = (string) preg_replace('/\s+/', ' ', $keyword);

        return trim($keyword);
    }

    /**
     * "Pelanggan dengan transaksi terbanyak?" — peringkat jumlah pesanan
     * per pelanggan (agregat read-only), dengan cakupan izin yang sama
     * seperti hitungan/daftar pelanggan.
     */
    private function topCustomerReply(?User $user): string
    {
        $scope = $this->customerAccountScope($user);

        if ($scope === 'guest') {
            return self::GUEST_ACCOUNT_REPLY;
        }

        if ($scope === 'none') {
            return self::ACCOUNT_DENIED_REPLY;
        }

        if ($scope === 'search') {
            return 'Peringkat transaksi seluruh pelanggan hanya tersedia untuk admin. '
                .'Sebutkan nama pelanggan spesifik yang ingin dicari — saya bantu carikan.';
        }

        $total = (int) $this->customerQuery()->count();

        if ($total === 0) {
            return 'Belum ada akun pelanggan yang terdaftar di sistem Agung ATK.';
        }

        $ranked = $this->customerQuery()
            ->withCount('orders')
            ->orderByDesc('orders_count')
            ->limit(self::MAX_CUSTOMER_LIST)
            ->get(['id', 'name', 'is_active'])
            ->filter(fn (User $customer) => (int) $customer->orders_count > 0);

        if ($ranked->isEmpty()) {
            return 'Belum ada pelanggan dengan pesanan tercatat — peringkat ini akan '
                .'muncul setelah ada transaksi dari pelanggan.';
        }

        $lines = ['Pelanggan dengan transaksi (jumlah pesanan) terbanyak:'];

        foreach ($ranked->values() as $index => $customer) {
            $lines[] = ($index + 1).'. '.$customer->name
                .' — '.(int) $customer->orders_count.' pesanan'
                .' ('.((bool) $customer->is_active ? 'aktif' : 'nonaktif').')';
        }

        $lines[] = 'Dihitung dari seluruh pesanan pelanggan (semua status) langsung di database — '
            .'bukan perkiraan.';

        return implode("\n", $lines);
    }

    /**
     * "Berapa jumlah pelanggan yang terdaftar?" — hitungan live dari
     * database (dengan rincian status akun), sesuai izin per role.
     */
    private function customerCountReply(?User $user): string
    {
        $scope = $this->customerAccountScope($user);

        if ($scope === 'guest') {
            return self::GUEST_ACCOUNT_REPLY;
        }

        if ($scope === 'none') {
            return self::ACCOUNT_DENIED_REPLY;
        }

        $query = $this->customerQuery();
        $total = (int) (clone $query)->count();
        $aktif = (int) (clone $query)->where('is_active', true)->count();

        if ($total === 0) {
            return 'Belum ada akun pelanggan yang terdaftar di sistem Agung ATK.';
        }

        return 'Terdapat '.$total.' pelanggan terdaftar di sistem Agung ATK '
            .'('.$aktif.' aktif dan '.($total - $aktif).' nonaktif).';
    }

    /**
     * "Sebutkan pelanggan yang terdaftar" — daftar nama (tanpa email/HP)
     * sesuai kewenangan; anggota diarahkan ke pencarian nama spesifik.
     */
    private function customerListReply(?User $user): string
    {
        $scope = $this->customerAccountScope($user);

        if ($scope === 'guest') {
            return self::GUEST_ACCOUNT_REPLY;
        }

        if ($scope === 'none') {
            return self::ACCOUNT_DENIED_REPLY;
        }

        if ($scope === 'search') {
            return 'Daftar seluruh akun pelanggan hanya tersedia untuk admin. '
                .'Sebutkan nama atau username pelanggan spesifik yang ingin dicari — saya bantu carikan.';
        }

        $total = (int) $this->customerQuery()->count();

        if ($total === 0) {
            return 'Belum ada akun pelanggan yang terdaftar di sistem Agung ATK.';
        }

        $customers = $this->customerQuery()
            ->orderBy('name')
            ->limit(self::MAX_CUSTOMER_LIST)
            ->get(['id', 'name', 'is_active', 'role_id']);

        $lines = ['Daftar pelanggan terdaftar (menampilkan '.$customers->count().' dari '.$total.'):'];

        foreach ($customers->values() as $index => $customer) {
            $lines[] = ($index + 1).'. '.$customer->name
                .' — '.((bool) $customer->is_active ? 'aktif' : 'nonaktif');
        }

        $lines[] = 'Untuk pencarian nama tertentu, sebutkan nama atau username pelanggan yang ingin dicari.';

        return implode("\n", $lines);
    }

    /**
     * "Apakah pelanggan bernama X ada?" — pencarian live by nama ATAU
     * username (email login); kecocokan ganda meminta pembeda yang aman,
     * kegagalan disampaikan jujur (tanpa mengarang).
     */
    private function customerSearchReply(string $lower, ?User $user): string
    {
        $scope = $this->customerAccountScope($user);

        if ($scope === 'guest') {
            return self::GUEST_ACCOUNT_REPLY;
        }

        if ($scope === 'none') {
            return self::ACCOUNT_DENIED_REPLY;
        }

        $keyword = $this->customerSearchKeyword($lower);

        if ($keyword === '') {
            return 'Sebutkan nama atau username pelanggan yang ingin dicari, '
                .'misalnya: "ada pelanggan namanya Agung" atau "cek akun dengan username budi@contoh.com".';
        }

        // LIKE tanpa wildcard bebas: karakter % dan _ dari pengguna di-escape.
        $like = '%'.addcslashes($keyword, '%_').'%';

        $matches = $this->customerQuery()
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->limit(2)
            ->get(['id', 'name', 'email', 'is_active', 'role_id', 'employee_code']);

        if ($matches->isEmpty()) {
            return 'Maaf, saya tidak menemukan akun pelanggan dengan nama tersebut.';
        }

        $exact = $matches->first(
            fn (User $customer): bool => mb_strtolower($customer->name) === mb_strtolower($keyword)
        );

        if ($matches->count() > 1 && $exact === null) {
            return 'Ditemukan lebih dari satu pelanggan dengan nama serupa. '
                .'Untuk mempersempit, sebutkan username/email atau ID akun yang berhak Anda akses.';
        }

        $customer = $exact ?? $matches->first();
        $status = (bool) $customer->is_active ? 'aktif' : 'nonaktif';

        if (str_contains($keyword, '@')) {
            return 'Ya, akun dengan username "'.$keyword.'" terdaftar atas nama '.$customer->name
                .' — status akun '.$status.'.';
        }

        return 'Ya, pelanggan dengan nama '.$customer->name.' terdaftar di sistem Agung ATK '
            .'— status akun '.$status.'.';
    }

    /**
     * "Siapa nama pelanggan pesanan yang saya antar?" — kurir hanya
     * mendapat pelanggan penugasan aktif miliknya; staf meminta nomor
     * pesanan; pelanggan melihat identitas pesanannya sendiri.
     */
    private function shipmentCustomerReply(?User $user): string
    {
        if ($user === null) {
            return 'Silakan login — informasi pelanggan pesanan hanya tersedia untuk akun yang login.';
        }

        if ($user->isCourier()) {
            $shipment = Shipment::with(['order:id,order_number,user_id', 'order.user:id,name'])
                ->where('courier_id', $user->id)
                ->whereIn('status', self::ACTIVE_SHIPMENT_STATUSES)
                ->latest('id')
                ->first();

            if ($shipment === null) {
                return 'Belum ada penugasan aktif untuk akun ini, jadi saya tidak punya data pelanggan '
                    .'yang sedang diantar. Tugas baru muncul setelah admin menugaskan pesanan kepada Anda.';
            }

            $line = 'Pelanggan pesanan '.$shipment->order?->order_number.' adalah '
                .($shipment->order?->user?->name ?? 'belum tercatat').'.';

            if ($shipment->recipient_name !== null && $shipment->recipient_name !== '') {
                $line .= ' Penerima tercatat: '.$shipment->recipient_name.'.';
            }

            return $line.' Rincian alamat ada di menu Pengiriman Saya.';
        }

        if ($user->isAdmin() || $user->isAnggota()) {
            return 'Sebutkan nomor pesanan (mis. ORD-...) agar saya dapat menampilkan '
                .'pelanggan pesanan tersebut.';
        }

        // Pelanggan: identitas pesanan miliknya sendiri.
        $order = Order::with('user:id,name')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($order === null) {
            return 'Anda belum memiliki pesanan, jadi belum ada data pelanggan atas pesanan.';
        }

        return 'Pesanan terbaru Anda tercatat atas nama '.($order->user?->name ?? $user->name).'.';
    }

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

    /**
     * "Produk apa yang stoknya paling banyak?" — peringkat stok (publik).
     */
    private function matchesTopStock(string $lower): bool
    {
        return preg_match(
            '/\b(?:stok(?:nya)?|persediaan)\b[\s\S]{0,25}\b'
            .'(?:paling\s+banyak|terbanyak|tertinggi|terbesar|paling\s+tinggi)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:paling\s+banyak|terbanyak|tertinggi)\b[\s\S]{0,20}\b(?:stok|persediaan)\b/i',
            $lower
        ) === 1;
    }

    /**
     * "Tampilkan produk terbaru" — produk terakhir ditambahkan (publik).
     */
    private function matchesNewestProducts(string $lower): bool
    {
        return preg_match(
            '/\b(?:produk|barang|item)\b[\s\S]{0,25}\b'
            .'(?:terbaru|paling\s+baru|terakhir\s+(?:masuk|ditambahkan|diperbarui)|'
            .'baru\s+masuk|update\s+terbaru)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:terbaru|paling\s+baru)\b[\s\S]{0,25}\b(?:produk|barang|item)\b/i',
            $lower
        ) === 1;
    }

    /**
     * "Berapa jumlah pengguna berdasarkan role?" — HANYA pertanyaan yang
     * secara eksplisit menyebut pembagian role/peran (kemampuan baru).
     * Pertanyaan jumlah pengguna generik sengaja TANPA pola di sini agar
     * tetap dijawab jalur ringkasan operasional lama (kontrak tes lama).
     */
    private function matchesUsersByRole(string $lower): bool
    {
        return preg_match(
            '/\b(?:pengguna|user|akun)\b[\s\S]{0,40}\b'
            .'(?:berdasarkan\s+(?:role|peran)|per\s+role|per\s+peran|tiap\s+(?:role|peran)|'
            .'role\s+mana|peran\s+mana|dibagi\s+(?:role|peran)|dalam\s+(?:role|peran))\b/i',
            $lower
        ) === 1;
    }

    /**
     * "Siapa pengguna terbaru?" — akun terakhir terdaftar (admin).
     */
    private function matchesNewestUsers(string $lower): bool
    {
        return preg_match(
            '/\b(?:pengguna|user|akun)\b[\s\S]{0,30}\b'
            .'(?:terbaru|paling\s+baru|terakhir\s+(?:mendaftar|daftar|registrasi|ditambahkan)|'
            .'baru\s+(?:mendaftar|daftar|registrasi))\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:terbaru|paling\s+baru)\b[\s\S]{0,25}\b(?:pengguna|user|akun)\b/i',
            $lower
        ) === 1;
    }

    /**
     * "Berapa transaksi yang belum dibayar?" — pesanan pending; SENGAJA
     * diprioritaskan sebelum intent pendapatan agar tidak dijawab omzet.
     * Pertanyaan pembayaran milik sendiri tetap urusan payment_pending.
     */
    private function matchesPendingPayments(string $lower): bool
    {
        return preg_match(
            '/\b(?:transaksi|pesanan|order|pembayaran|belanjaan)\b[\s\S]{0,40}\b'
            .'(?:belum\s+(?:dibayar|bayar|lunas|dikonfirmasi|diverifikasi|diproses)|'
            .'menunggu\s+(?:pembayaran|verifikasi|konfirmasi)|pending\b)/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:berapa(?:kah)?|brp|jumlah|jml|total|banyak(?:nya)?)\s+'
            .'(?:yang\s+)?(?:belum\s+(?:dibayar|bayar|lunas))\b/i',
            $lower
        ) === 1;
    }

    /**
     * "Transaksi/pesanan terbaru apa?" — daftar pesanan terakhir masuk
     * (bukan milik sendiri; dijaga isPossessive di tempat pemanggilan).
     */
    private function matchesNewestOrders(string $lower): bool
    {
        return preg_match(
            '/\b(?:pesanan|order|transaksi)\b[\s\S]{0,30}\b'
            .'(?:terbaru|paling\s+baru|terakhir\s+(?:masuk|dibuat|dipesan)|'
            .'yang\s+terakhir\s+masuk|update\s+terakhir)\b/i',
            $lower
        ) === 1 || preg_match(
            '/\b(?:terbaru|paling\s+baru)\b[\s\S]{0,25}\b(?:pesanan|order|transaksi)\b/i',
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
     * Admin saja (dipakai intent daftar akun lintas peran).
     */
    private function isAdmin(?User $user): bool
    {
        return $user !== null && $user->isAdmin();
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
