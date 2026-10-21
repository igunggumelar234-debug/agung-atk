<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Notifications\StockLevelNotifier;
use App\Support\CartSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /**
     * Metode pembayaran yang didukung.
     */
    private const PAYMENT_METHODS = ['transfer', 'qris', 'cod'];

    public function __construct(private readonly StockLevelNotifier $stockNotifier) {}

    /**
     * Halaman checkout: pilih alamat, metode pembayaran, dan ringkasan pesanan.
     */
    public function index(Request $request): RedirectResponse|View
    {
        $cart = CartSession::items();

        if ($cart === []) {
            return redirect()->route('cart.index')
                ->with('error', 'Keranjang kosong. Tambahkan produk terlebih dahulu sebelum checkout.');
        }

        // Sanitasi seperti halaman keranjang: buang produk nonaktif/hilang,
        // dan turunkan quantity yang melebihi stok tersedia.
        $products = Product::with(['category', 'primaryImage'])
            ->whereIn('id', array_keys($cart))
            ->get()
            ->keyBy('id');

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $product->is_active || $product->stock <= 0) {
                CartSession::remove($productId);

                continue;
            }

            if ($quantity > $product->stock) {
                CartSession::put($productId, $product->stock);
            }
        }

        $cart = CartSession::items();

        if ($cart === []) {
            return redirect()->route('cart.index')
                ->with('error', 'Semua produk di keranjang sudah tidak tersedia.');
        }

        // Susun item & subtotal
        $items = [];
        $subtotal = 0.0;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $lineSubtotal = $quantity * (float) $product->price;
            $subtotal += $lineSubtotal;

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'subtotal' => $lineSubtotal,
            ];
        }

        // Alamat milik pelanggan yang login
        $addresses = Address::where('user_id', auth()->id())
            ->orderByDesc('is_primary')
            ->get();

        $selectedAddressId = old('address_id')
            ?? ($request->filled('address_id') ? $request->integer('address_id') : null)
            ?? $addresses->firstWhere('is_primary', true)?->id
            ?? $addresses->first()?->id;

        $shippingFee = (float) config('app.shipping_fee');
        $discount = 0.0;

        return view('checkout.index', [
            'items' => $items,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'discount' => $discount,
            'total' => $subtotal + $shippingFee - $discount,
            'addresses' => $addresses,
            'selectedAddressId' => $selectedAddressId,
            'paymentMethods' => self::PAYMENT_METHODS,
        ]);
    }

    /**
     * Buat pesanan dari isi keranjang.
     *
     * Seluruh proses (validasi stok → order → order_details → kurangi stok →
     * payment) berjalan dalam satu database transaction dengan lockForUpdate
     * pada baris produk, sehingga stok tidak dapat menjadi negatif meskipun
     * ada transaksi bersamaan. Keranjang hanya dikosongkan setelah sukses.
     *
     * Klik ganda / request paralel dari pengguna yang sama dilindungi cache
     * lock ("checkout:{user}") — proses kedua ditolak sampai yang pertama selesai.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'address_id' => [
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', auth()->id()),
            ],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'address_id.required' => 'Pilih alamat pengiriman terlebih dahulu.',
            'address_id.exists' => 'Alamat tidak ditemukan atau bukan milik Anda.',
            'payment_method.required' => 'Pilih metode pembayaran.',
            'payment_method.in' => 'Metode pembayaran tidak dikenali.',
        ]);

        $lock = Cache::lock('checkout:'.auth()->id(), 15);

        if (! $lock->get()) {
            return redirect()->route('checkout.index')
                ->with('error', 'Pesanan Anda sedang diproses. Mohon tunggu beberapa saat sebelum mengirim ulang.');
        }

        try {
            return $this->processCheckout($data);
        } finally {
            // Selalu dilepas (sukses/gagal) agar checkout berikutnya lancar;
            // sisa lock mati otomatis setelah 15 detik bila proses crash.
            $lock->release();
        }
    }

    /**
     * Inti proses checkout (dipanggil di bawah kunci anti double-submit).
     *
     * @param  array{address_id: int, payment_method: string, notes?: string|null}  $data
     */
    private function processCheckout(array $data): RedirectResponse
    {
        $cart = CartSession::items();

        if ($cart === []) {
            return redirect()->route('cart.index')
                ->with('error', 'Keranjang kosong. Tambahkan produk terlebih dahulu.');
        }

        $shippingFee = (float) config('app.shipping_fee');
        $discount = 0.0;

        $order = DB::transaction(function () use ($cart, $data, $shippingFee, $discount) {
            // Kunci baris produk agar tidak ada pengurangan stok bersamaan
            $products = Product::whereIn('id', array_keys($cart))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0.0;
            $lines = [];

            foreach ($cart as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'cart' => 'Salah satu produk di keranjang sudah tidak tersedia. Silakan periksa kembali keranjang Anda.',
                    ]);
                }

                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'cart' => 'Stok "'.$product->name.'" tidak mencukupi (tersisa '.$product->stock.'). Silakan kurangi jumlah pesanan Anda.',
                    ]);
                }

                $lineSubtotal = $quantity * (float) $product->price;
                $subtotal += $lineSubtotal;
                $lines[] = ['product' => $product, 'quantity' => $quantity, 'subtotal' => $lineSubtotal];
            }

            $grandTotal = $subtotal + $shippingFee - $discount;

            // 1. Buat order (status awal: pending)
            $order = Order::create([
                'order_number' => self::generateNumber('ORD'),
                'user_id' => auth()->id(),
                'address_id' => (int) $data['address_id'],
                'total_amount' => $subtotal,
                'shipping_cost' => $shippingFee,
                'discount_amount' => $discount,
                'grand_total' => $grandTotal,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                // 2. Buat order detail (snapshot nama & harga)
                $order->orderDetails()->create([
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'price' => $line['product']->price,
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                ]);

                // 3. Kurangi stok (baris sudah terkunci; stok sudah divalidasi >= qty)
                Product::whereKey($line['product']->id)->decrement('stock', $line['quantity']);
            }

            // 4. Buat payment (status awal: pending)
            $order->payment()->create([
                'payment_number' => self::generateNumber('PAY'),
                'method' => $data['payment_method'],
                'amount' => $grandTotal,
                'status' => 'pending',
            ]);

            return $order;
        });

        // 5. Kosongkan keranjang HANYA setelah transaksi sukses
        CartSession::clear();

        // Jejak audit: pesanan baru tercatat (pelaku = pelanggan yang login)
        ActivityLog::record(
            'order.created',
            $order,
            'Pesanan #'.$order->order_number.' dibuat, total Rp '.number_format((float) $order->grand_total, 0, ',', '.')
        );

        // 6. Periksa ambang stok untuk produk yang baru berkurang.
        //    Pengurangan stok memakai query builder (decrement) sehingga tidak
        //    memicu event model — pemeriksaan dilakukan manual di sini.
        //    Satu query whereIn (bukan Product::find per baris keranjang).
        $stockedProducts = Product::whereIn('id', array_keys($cart))
            ->get(['id', 'name', 'stock', 'min_stock'])
            ->keyBy('id');

        foreach (array_keys($cart) as $productId) {
            if ($stockedProducts->has($productId)) {
                $this->stockNotifier->check($stockedProducts[$productId]);
            }
        }

        // Arahkan pelanggan langsung ke halaman detail pembayaran
        // (unggah bukti untuk transfer/QRIS, atau instruksi COD)
        return redirect()->route('payments.show', $order->payment)->with(
            'success',
            'Pesanan #'.$order->order_number.' berhasil dibuat. Total Rp '.number_format((float) $order->grand_total, 0, ',', '.').'. Silakan lakukan pembayaran sesuai metode yang dipilih.'
        );
    }

    /**
     * Nomor unik untuk order (ORD-YYMMDD-XXXX) dan payment (PAY-...).
     */
    private static function generateNumber(string $prefix): string
    {
        do {
            $number = $prefix.'-'.now()->format('ymd').'-'.strtoupper(Str::random(8));
        } while (
            Order::where('order_number', $number)->exists()
            || Payment::where('payment_number', $number)->exists()
        );

        return $number;
    }
}
