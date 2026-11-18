<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Route;

class NotificationMessageFactory
{
    /**
     * Susun isi pesan (judul + badan teks) untuk satu kejadian notifikasi.
     *
     * Konteks yang tersedia per kejadian:
     * - Pesanan:            ['order' => Order]
     * - Pembayaran:         ['payment' => Payment, 'order' => Order]
     * - Stok:               ['product' => Product]
     * - Pengiriman:         ['shipment' => Shipment, 'order' => Order]
     * - Pendaftaran:        ['user' => User]
     * - Ringkasan harian:   ['summary' => array]
     *
     * $audience menentukan tautan detail yang sesuai dengan peran penerima
     * (admin, penjual, kurir, atau pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    public function make(string $eventKey, array $data, ?string $audience = null): array
    {
        return match ($eventKey) {
            'order.new' => $this->orderNew($data, $audience),
            'order.created' => $this->orderCreated($data, $audience),
            'payment.instructions' => $this->paymentInstructions($data, $audience),
            'payment.proof_uploaded' => $this->paymentProofUploaded($data, $audience),
            'payment.verified' => $this->paymentVerified($data, $audience),
            'payment.rejected' => $this->paymentRejected($data, $audience),
            'order.paid' => $this->orderPaid($data, $audience),
            'order.processing' => $this->orderProcessing($data, $audience),
            'order.ready_to_ship' => $this->orderReadyToShip($data, $audience),
            'order.cancelled' => $this->orderCancelled($data, $audience),
            'stock.low' => $this->stockLow($data, $audience),
            'stock.out' => $this->stockOut($data, $audience),
            'shipment.assigned' => $this->shipmentAssigned($data, $audience),
            'shipment.status' => $this->shipmentStatus($data, $audience),
            'shipment.shipping' => $this->shipmentShipping($data, $audience),
            'shipment.delivered' => $this->shipmentDelivered($data, $audience),
            'account.registered' => $this->accountRegistered($data, $audience),
            'account.login' => $this->accountLogin($data),
            'report.daily' => $this->reportDaily($data),
            'system.issue' => $this->systemIssue($data),
            default => $this->fallback($eventKey, $data),
        };
    }

    /**
     * Pesanan baru (admin & penjual).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function orderNew(array $data, ?string $audience = null): array
    {
        /** @var Order $order */
        $order = $data['order'];
        $customer = $order->user?->name ?? 'Pelanggan';

        return [
            'subject' => 'Pesanan baru #'.$order->order_number,
            'body' => $this->lines([
                'Halo,',
                '',
                'Pesanan baru dari '.$customer.' masuk ke sistem.',
                '',
                '- No. Pesanan: '.$order->order_number,
                '- Metode Pembayaran: '.$this->paymentMethodLabel($order->payment?->method),
                '- Total: '.$this->rp($order->grand_total),
                '- Status: Menunggu pembayaran',
                '',
                'Silakan periksa pesanan pada menu Pesanan.',
                ...$this->footer('order.new', $data, $audience),
            ]),
        ];
    }

    /**
     * Pesanan berhasil dibuat (pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function orderCreated(array $data, ?string $audience = null): array
    {
        /** @var Order $order */
        $order = $data['order'];

        return [
            'subject' => 'Pesanan #'.$order->order_number.' berhasil dibuat',
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Pesanan Anda #'.$order->order_number.' berhasil dibuat.',
                '',
                '- Total: '.$this->rp($order->grand_total),
                '- Status: Menunggu pembayaran',
                '',
                'Terima kasih telah berbelanja di '.config('app.name').'.',
                ...$this->footer('order.created', $data, $audience),
            ]),
        ];
    }

    /**
     * Instruksi pembayaran tersedia (pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function paymentInstructions(array $data, ?string $audience = null): array
    {
        /** @var Order $order */
        $order = $data['order'];
        $method = $order->payment?->method;

        $instruction = match ($method) {
            'transfer' => 'Silakan transfer sesuai total pesanan ke rekening toko, '
                .'lalu unggah bukti pembayaran melalui halaman detail pesanan.',
            'qris' => 'Silakan bayar menggunakan QRIS sesuai total pesanan, '
                .'lalu unggah bukti pembayaran melalui halaman detail pesanan.',
            'cod' => 'Pembayaran dilakukan tunai saat barang diterima (COD).',
            default => 'Silakan selesaikan pembayaran sesuai metode yang dipilih.',
        };

        return [
            'subject' => 'Instruksi pembayaran pesanan #'.$order->order_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Instruksi pembayaran untuk pesanan #'.$order->order_number.' telah tersedia.',
                '',
                '- Metode: '.$this->paymentMethodLabel($method),
                '- Total: '.$this->rp($order->grand_total),
                '',
                $instruction,
                ...$this->footer('payment.instructions', $data, $audience),
            ]),
        ];
    }

    /**
     * Pelanggan mengunggah bukti pembayaran (admin).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function paymentProofUploaded(array $data, ?string $audience = null): array
    {
        /** @var Payment $payment */
        $payment = $data['payment'];
        $order = $data['order'] ?? $payment->order;

        return [
            'subject' => 'Bukti pembayaran diunggah #'.$payment->payment_number,
            'body' => $this->lines([
                'Halo Admin,',
                '',
                ($order->user?->name ?? 'Pelanggan').' telah mengunggah bukti pembayaran.',
                '',
                '- No. Pembayaran: '.$payment->payment_number,
                '- No. Pesanan: '.$order->order_number,
                '- Total: '.$this->rp($payment->amount),
                '',
                'Silakan verifikasi bukti pembayaran pada menu Pembayaran.',
                ...$this->footer('payment.proof_uploaded', $data, $audience),
            ]),
        ];
    }

    /**
     * Pembayaran diverifikasi (admin & pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function paymentVerified(array $data, ?string $audience = null): array
    {
        /** @var Payment $payment */
        $payment = $data['payment'];
        $order = $data['order'] ?? $payment->order;

        return [
            'subject' => 'Pembayaran diverifikasi #'.$payment->payment_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Pembayaran untuk pesanan #'.$order->order_number.' telah diverifikasi.',
                '',
                '- No. Pembayaran: '.$payment->payment_number,
                '- Total: '.$this->rp($payment->amount),
                '- Status pesanan: '.strtoupper($order->status),
                '',
                'Pesanan akan segera diproses. Terima kasih.',
                ...$this->footer('payment.verified', $data, $audience),
            ]),
        ];
    }

    /**
     * Pembayaran ditolak (admin & pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function paymentRejected(array $data, ?string $audience = null): array
    {
        /** @var Payment $payment */
        $payment = $data['payment'];
        $order = $data['order'] ?? $payment->order;

        return [
            'subject' => 'Pembayaran ditolak #'.$payment->payment_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Pembayaran untuk pesanan #'.$order->order_number.' ditolak dan perlu diperbaiki.',
                '',
                '- No. Pembayaran: '.$payment->payment_number,
                '- Alasan: '.($payment->rejection_reason ?? '-'),
                '',
                'Silakan perbaiki pembayaran Anda dan hubungi toko bila perlu bantuan.',
                ...$this->footer('payment.rejected', $data, $audience),
            ]),
        ];
    }

    /**
     * Pesanan sudah dibayar (penjual perlu menyiapkan barang).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function orderPaid(array $data, ?string $audience = null): array
    {
        /** @var Order $order */
        $order = $data['order'];
        $itemCount = $order->orderDetails()->sum('quantity');

        return [
            'subject' => 'Pesanan perlu disiapkan #'.$order->order_number,
            'body' => $this->lines([
                'Halo Anggota Toko,',
                '',
                'Pesanan #'.$order->order_number.' telah dibayar dan perlu disiapkan.',
                '',
                '- Pelanggan: '.($order->user?->name ?? '-'),
                '- Jumlah item: '.$itemCount.' barang',
                '- Total: '.$this->rp($order->grand_total),
                '',
                'Tandai pesanan ke status Diproses setelah barang disiapkan.',
                ...$this->footer('order.paid', $data, $audience),
            ]),
        ];
    }

    /**
     * Pesanan sedang diproses (pelanggan & penjual).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function orderProcessing(array $data, ?string $audience = null): array
    {
        /** @var Order $order */
        $order = $data['order'];

        return [
            'subject' => 'Pesanan sedang diproses #'.$order->order_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Pesanan #'.$order->order_number.' sedang diproses oleh toko.',
                '',
                '- Status: Diproses',
                '- Total: '.$this->rp($order->grand_total),
                '',
                'Kami akan segera menyiapkan pesanan Anda.',
                ...$this->footer('order.processing', $data, $audience),
            ]),
        ];
    }

    /**
     * Pesanan siap dikirim (admin).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function orderReadyToShip(array $data, ?string $audience = null): array
    {
        /** @var Order $order */
        $order = $data['order'];

        return [
            'subject' => 'Pesanan siap dikirim #'.$order->order_number,
            'body' => $this->lines([
                'Halo Admin,',
                '',
                'Pesanan #'.$order->order_number.' siap dikirim.',
                '',
                '- Pelanggan: '.($order->user?->name ?? '-'),
                '- Total: '.$this->rp($order->grand_total),
                '',
                'Buat penugasan pengiriman pada menu Pengiriman.',
                ...$this->footer('order.ready_to_ship', $data, $audience),
            ]),
        ];
    }

    /**
     * Pesanan dibatalkan (admin & pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function orderCancelled(array $data, ?string $audience = null): array
    {
        /** @var Order $order */
        $order = $data['order'];

        return [
            'subject' => 'Pesanan dibatalkan #'.$order->order_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Pesanan #'.$order->order_number.' telah dibatalkan.',
                '',
                '- Alasan: '.($order->cancelled_reason ?? '-'),
                '- Total: '.$this->rp($order->grand_total),
                '',
                'Bila ini adalah kesalahan, silakan hubungi toko.',
                ...$this->footer('order.cancelled', $data, $audience),
            ]),
        ];
    }

    /**
     * Stok hampir habis (admin).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function stockLow(array $data, ?string $audience = null): array
    {
        /** @var Product $product */
        $product = $data['product'];

        return [
            'subject' => 'Stok hampir habis: '.$product->name,
            'body' => $this->lines([
                'Halo,',
                '',
                'Stok produk berikut hampir habis:',
                '',
                '- Produk: '.$product->name,
                '- Kode SKU: '.$product->sku,
                '- Stok tersisa: '.$product->stock,
                '- Stok minimum: '.$product->min_stock,
                '',
                'Segera lakukan restok agar produk tetap dapat dibeli.',
                ...$this->footer('stock.low', $data, $audience),
            ]),
        ];
    }

    /**
     * Stok habis (admin).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function stockOut(array $data, ?string $audience = null): array
    {
        /** @var Product $product */
        $product = $data['product'];

        return [
            'subject' => 'Stok habis: '.$product->name,
            'body' => $this->lines([
                'Halo,',
                '',
                'Stok produk berikut sudah habis:',
                '',
                '- Produk: '.$product->name,
                '- Kode SKU: '.$product->sku,
                '- Stok tersisa: 0',
                '',
                'Produk tidak dapat dibeli sampai stok diisi kembali.',
                ...$this->footer('stock.out', $data, $audience),
            ]),
        ];
    }

    /**
     * Penugasan pengiriman baru (kurir & pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function shipmentAssigned(array $data, ?string $audience = null): array
    {
        /** @var Shipment $shipment */
        $shipment = $data['shipment'];
        $order = $data['order'] ?? $shipment->order;
        $address = $order->address;

        return [
            'subject' => 'Penugasan pengiriman #'.$shipment->tracking_number,
            'body' => $this->lines([
                'Halo,',
                '',
                'Penugasan pengiriman baru telah dibuat.',
                '',
                '- No. Resi: '.$shipment->tracking_number,
                '- No. Pesanan: '.$order->order_number,
                '- Kurir: '.($shipment->courier_name ?? '-'),
                '- Penerima: '.($address?->recipient_name ?? $order->user?->name ?? '-'),
                '- Telepon Penerima: '.($address?->phone ?? $order->user?->phone ?? '-'),
                '- Alamat: '.($address !== null
                    ? $address->full_address.', '.$address->district
                    : '-'),
                '- Status: Menunggu kurir mengambil barang',
                ...$this->footer('shipment.assigned', $data, $audience),
            ]),
        ];
    }

    /**
     * Status pengiriman diperbarui (pelanggan & admin).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function shipmentStatus(array $data, ?string $audience = null): array
    {
        /** @var Shipment $shipment */
        $shipment = $data['shipment'];
        $order = $data['order'] ?? $shipment->order;

        return [
            'subject' => 'Status pengiriman diperbarui #'.$shipment->tracking_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Status pengiriman untuk pesanan #'.$order->order_number.' diperbarui oleh kurir.',
                '',
                '- No. Resi: '.$shipment->tracking_number,
                '- Status baru: '.$this->shipmentStatusLabel($shipment->status),
                '- Kurir: '.($shipment->courier_name ?? '-'),
                ...$this->footer('shipment.status', $data, $audience),
            ]),
        ];
    }

    /**
     * Pesanan sedang dikirim (pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function shipmentShipping(array $data, ?string $audience = null): array
    {
        /** @var Shipment $shipment */
        $shipment = $data['shipment'];
        $order = $data['order'] ?? $shipment->order;

        return [
            'subject' => 'Pesanan sedang dikirim #'.$order->order_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Pesanan #'.$order->order_number.' sedang dikirim ke alamat Anda.',
                '',
                '- No. Resi: '.$shipment->tracking_number,
                '- Kurir: '.($shipment->courier_name ?? '-'),
                '- Status: Dalam pengiriman',
                '',
                'Silakan pantau pengiriman Anda.',
                ...$this->footer('shipment.shipping', $data, $audience),
            ]),
        ];
    }

    /**
     * Pesanan diterima pelanggan (admin & pelanggan).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function shipmentDelivered(array $data, ?string $audience = null): array
    {
        /** @var Shipment $shipment */
        $shipment = $data['shipment'];
        $order = $data['order'] ?? $shipment->order;

        return [
            'subject' => 'Pesanan diterima #'.$order->order_number,
            'body' => $this->lines([
                'Halo '.($order->user?->name ?? 'Pelanggan').',',
                '',
                'Pesanan #'.$order->order_number.' telah diterima.',
                '',
                '- No. Resi: '.$shipment->tracking_number,
                '- Waktu penerimaan: '.($shipment->delivered_at?->format('d M Y H:i') ?? '-'),
                '',
                'Terima kasih telah berbelanja di '.config('app.name').'.',
                ...$this->footer('shipment.delivered', $data, $audience),
            ]),
        ];
    }

    /**
     * Pendaftaran akun berhasil.
     *
     * - Pelanggan: sapaan selamat datang ke pemilik akun.
     * - Admin: pemberitahuan aktivitas pengguna baru (nama + email + peran).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function accountRegistered(array $data, ?string $audience = null): array
    {
        /** @var User $user */
        $user = $data['user'];

        if ($audience === 'admin') {
            return [
                'subject' => 'Pendaftaran akun baru: '.$user->name,
                'body' => $this->lines([
                    'Halo Admin,',
                    '',
                    'Pengguna baru mendaftar di '.config('app.name').':',
                    '',
                    '- Nama: '.$user->name,
                    '- Email: '.$user->email,
                    '- Peran: '.($user->role?->name ?? '-'),
                    '- Status: '.($user->is_active ? 'aktif' : 'nonaktif'),
                    '',
                    'Periksa data pengguna pada menu Pengguna bila perlu verifikasi.',
                    ...$this->footer('account.registered', $data, $audience),
                ]),
            ];
        }

        return [
            'subject' => 'Pendaftaran akun berhasil',
            'body' => $this->lines([
                'Halo '.$user->name.',',
                '',
                'Pendaftaran akun Anda berhasil. Selamat bergabung di '.config('app.name').'!',
                '',
                '- Email: '.$user->email,
                '',
                'Gunakan email tersebut untuk masuk ke akun Anda.',
                'Jaga kerahasiaan kata sandi Anda — pihak toko tidak pernah memintanya.',
                ...$this->footer('account.registered', $data, $audience),
            ]),
        ];
    }

    /**
     * Login pengguna berhasil (pemberitahuan keamanan ke admin).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function accountLogin(array $data): array
    {
        /** @var User $user */
        $user = $data['user'];
        $ip = isset($data['ip']) ? (string) $data['ip'] : null;

        return [
            'subject' => 'Login pengguna: '.$user->name,
            'body' => $this->lines([
                'Halo Admin,',
                '',
                'Login pengguna berhasil masuk ke sistem:',
                '',
                '- Nama: '.$user->name,
                '- Email: '.$user->email,
                '- Peran: '.($user->role?->name ?? '-'),
                $ip !== null ? '- Alamat IP: '.$ip : '- Alamat IP: -',
                '',
                'Bila ini bukan aktivitas yang Anda kenali, segera periksa keamanan akun.',
                ...$this->footer('account.login', $data, 'admin'),
            ]),
        ];
    }

    /**
     * Ringkasan penjualan harian (admin).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function reportDaily(array $data): array
    {
        /** @var array<string, int|float|string> $summary */
        $summary = $data['summary'];

        return [
            'subject' => 'Ringkasan Penjualan Harian '.($summary['date'] ?? now()->toDateString()),
            'body' => $this->lines([
                'Halo Admin,',
                '',
                'Ringkasan penjualan '.($summary['date'] ?? now()->toDateString()).':',
                '',
                '- Pesanan baru: '.($summary['new_orders'] ?? 0),
                '- Pendapatan: '.$this->rp($summary['revenue'] ?? 0),
                '- Pesanan selesai: '.($summary['completed'] ?? 0),
                '- Pembayaran menunggu verifikasi: '.($summary['pending_payments'] ?? 0),
                '- Pengiriman berjalan: '.($summary['active_shipments'] ?? 0),
            ]),
        ];
    }

    /**
     * Masalah sistem: pekerjaan antrean gagal (dipicu event JobFailed).
     *
     * Teks kesalahan disanitasi lebih dulu (sanitizeError) agar kredensial
     * yang mungkin ikut tercetak di pesan exception tidak bocor ke notifikasi.
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function systemIssue(array $data): array
    {
        $job = mb_substr((string) ($data['job'] ?? 'pekerjaan'), 0, 120);
        $error = $this->sanitizeError((string) ($data['error'] ?? 'tidak diketahui'));
        $attempts = (string) ($data['attempts'] ?? '-');

        return [
            'subject' => 'Masalah Sistem: '.$job,
            'body' => $this->lines([
                'Halo Admin,',
                '',
                'Pekerjaan antrean gagal diproses:',
                '- Pekerjaan: '.$job,
                '- Percobaan ke-: '.$attempts,
                '- Kesalahan: '.$error,
                '',
                'Periksa log aplikasi (storage/logs) dan jalankan "php artisan queue:failed" untuk detail.',
            ]),
        ];
    }

    /**
     * Sederhanakan teks kesalahan: buang potensi kredensial (token bot
     * Telegram, access token Graph, Authorization bearer) sebelum pesan
     * masuk ke notifikasi/log.
     */
    private function sanitizeError(string $error): string
    {
        $error = preg_replace('/bot\d+:[A-Za-z0-9_-]{10,}/', 'bot<token>', $error) ?? $error;
        $error = preg_replace('/access_token=[^&\s]+/', 'access_token=<redacted>', $error) ?? $error;
        $error = preg_replace('/Bearer\s+[A-Za-z0-9._-]{10,}/', 'Bearer <redacted>', $error) ?? $error;

        return mb_substr(trim($error), 0, 240);
    }

    /**
     * Pesan generik bila kejadian tidak dikenali (tetap aman & tanpa data sensitif).
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, body: string}
     */
    private function fallback(string $eventKey, array $data): array
    {
        $events = config('notifications.events');
        $label = $events[$eventKey]['label'] ?? $eventKey;
        $reference = $data['reference'] ?? null;

        return [
            'subject' => 'Notifikasi: '.$label,
            'body' => $this->lines([
                'Halo,',
                '',
                'Kejadian: '.$label,
                '- Referensi: '.($reference ?? '-'),
            ]),
        ];
    }

    /**
     * Baris kaki pesan: waktu kejadian + tautan detail sesuai peran penerima.
     *
     * Tautan hanya dibuat bila rute tersedia; tidak ada data sensitif yang
     * ikut disertakan.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function footer(string $eventKey, array $data, ?string $audience): array
    {
        $footer = ['', 'Waktu: '.$this->when($data)];

        $link = $this->detailLink($eventKey, $data, $audience);

        if ($link !== null) {
            $footer[] = $link;
        }

        return $footer;
    }

    /**
     * Tautan halaman detail yang sesuai dengan peran penerima.
     *
     * @param  array<string, mixed>  $data
     */
    private function detailLink(string $eventKey, array $data, ?string $audience): ?string
    {
        if ($audience === null) {
            return null;
        }

        $order = $data['order'] ?? $data['payment']?->order ?? $data['shipment']?->order ?? null;
        $payment = $data['payment'] ?? null;
        $shipment = $data['shipment'] ?? null;

        $url = match (true) {
            $audience === 'admin' && $payment !== null
                && in_array($eventKey, ['payment.proof_uploaded', 'payment.verified', 'payment.rejected'], true)
                && Route::has('admin.payments.show') => route('admin.payments.show', $payment, true),
            $audience === 'admin' && $order !== null && Route::has('admin.orders.show') => route('admin.orders.show', $order, true),
            $audience === 'seller' && $order !== null && Route::has('seller.orders.show') => route('seller.orders.show', $order, true),
            $audience === 'courier' && $shipment !== null && Route::has('courier.shipments.show') => route('courier.shipments.show', $shipment, true),
            $audience === 'customer' && $payment !== null && Route::has('payments.show') => route('payments.show', $payment, true),
            $audience === 'customer' && $order !== null && Route::has('orders.show') => route('orders.show', $order, true),
            default => null,
        };

        return $url !== null ? 'Lihat detail: '.$url : null;
    }

    /**
     * Waktu kejadian yang relevan untuk pesan.
     *
     * @param  array<string, mixed>  $data
     */
    private function when(array $data): string
    {
        if (isset($data['summary']['date'])) {
            return $data['summary']['date'];
        }

        $timestamp = $data['order']?->created_at
            ?? $data['payment']?->updated_at
            ?? $data['shipment']?->updated_at
            ?? $data['product']?->updated_at
            ?? $data['user']?->created_at
            ?? now();

        return $timestamp->format('d M Y H:i');
    }

    /**
     * Gabungkan baris pesan menjadi satu badan teks.
     *
     * Setiap pesan otomatis dibungkus header "🔔 NOTIFIKASI APLIKASI" dan
     * penutup "Pesan ini dikirim secara otomatis oleh sistem aplikasi."
     * agar format seragam, profesional, dan mudah dikenali penerima.
     *
     * @param  list<string>  $lines
     */
    private function lines(array $lines): string
    {
        return implode("\n", array_merge(
            ['🔔 NOTIFIKASI APLIKASI', ''],
            $lines,
            ['', 'Pesan ini dikirim secara otomatis oleh sistem aplikasi.'],
        ));
    }

    /**
     * Format nominal rupiah tanpa desimal.
     */
    private function rp(float|int|string|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    /**
     * Label metode pembayaran untuk pesan.
     */
    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS',
            'cod' => 'Bayar di Tempat (COD)',
            null => '-',
            default => strtoupper($method),
        };
    }

    /**
     * Label status pengiriman untuk pesan.
     */
    private function shipmentStatusLabel(string $status): string
    {
        return match ($status) {
            'waiting' => 'Menunggu kurir mengambil barang',
            'picked_up' => 'Diambil kurir',
            'shipping' => 'Dalam pengiriman',
            'delivered' => 'Terkirim / Diterima',
            default => strtoupper($status),
        };
    }
}
