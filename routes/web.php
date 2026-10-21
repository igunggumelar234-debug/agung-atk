<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CourierController as AdminCourierController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\ShipmentController as AdminShipmentController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\Anggota\StockController as AnggotaStockController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Courier\ShipmentController as CourierShipmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImageSearchController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\Seller\OrderController as SellerOrderController;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Agung ATK Kuningan
|--------------------------------------------------------------------------
*/

// Halaman Beranda Utama
Route::get('/', function () {
    // Data dinamis untuk halaman beranda (gagal jika DB tidak tersedia
    // tetap menampilkan landing page tanpa produk)
    $featured = collect();
    $catalogCategories = collect();

    try {
        $featured = Product::with(['category', 'primaryImage'])
            ->active()
            ->latest()
            ->take(8)
            ->get();

        $catalogCategories = DB::table('categories')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    } catch (Throwable $e) {
        $featured = collect();
        $catalogCategories = collect();
    }

    return view('home', [
        'featured' => $featured,
        'catalogCategories' => $catalogCategories,
    ]);
})->name('home');

// Chatbot Beranda (publik: tamu & semua role) — dibatasi throttle agar
// kunci API tidak bisa disalahgunakan untuk memanggil model berulang kali.
Route::post('/home/chat', [AIController::class, 'homeChat'])
    ->middleware('throttle:20,1')
    ->name('home.chat');

// Katalog Produk (publik: tamu & semua role)
Route::get('/products', [CatalogController::class, 'index'])->name('products.index');
// Sebelum route {product:slug} agar "scan" tidak dianggap slug produk.
Route::get('/products/scan/{code}', [CatalogController::class, 'scan'])->name('products.scan');
Route::get('/products/{product:slug}', [CatalogController::class, 'show'])->name('products.show');

// Visual Search: pencarian produk ATK berbasis gambar (foto kamera / unggah).
// Publik seperti katalog; dibatasi rate limit per IP/user agar server tidak
// dibombardir unggahan gambar berat.
Route::post('/image-search', ImageSearchController::class)
    ->middleware('throttle:10,1')
    ->name('image.search');

// Review produk (hanya pelanggan; kelayakan pesanan divalidasi di controller)
Route::post('/products/{product:slug}/reviews', [ReviewController::class, 'store'])
    ->middleware(['auth', 'pelanggan'])
    ->name('reviews.store');

// Keranjang Belanja (hanya pelanggan yang login)
Route::middleware(['auth', 'pelanggan'])->prefix('cart')->name('cart.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('index');
    Route::post('/', [CartController::class, 'store'])->name('store');
    Route::patch('/{product}', [CartController::class, 'update'])->name('update');
    Route::delete('/{product}', [CartController::class, 'destroy'])->name('destroy');
});

// Checkout & Alamat (hanya pelanggan yang login)
Route::middleware(['auth', 'pelanggan'])->group(function () {
    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::post('/addresses', [AddressController::class, 'store'])->name('addresses.store');

    // Detail & unggah bukti pembayaran (khusus pelanggan pemilik)
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{payment}/proof', [PaymentController::class, 'uploadProof'])->name('payments.proof.upload');
});

// Streaming bukti pembayaran: pemilik pesanan ATAU admin (diverifikasi di controller)
Route::middleware('auth')->group(function () {
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');
});

// Profil & keamanan akun: berlaku untuk SEMUA role yang sudah login
// (admin, anggota, kurir, pelanggan) — selalu data akun sendiri,
// dicek oleh controller terhadap $request->user(), bukan input browser.
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // throttle:10,1 -> ganti password memverifikasi hash (loop berat); tanpa batas,
    // sesi yang dicuri bisa menebak current_password tanpa henti.
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:10,1')->name('profile.password');
});

// Autentikasi (Tamu / Guest)
// GET /login & /register sengaja DI LUAR group guest: controller sudah mengarahkan
// user yang login ke dashboard sesuai role (redirectBasedOnRole). Bila dibungkus
// guest, middleware melempar semua role ke /dashboard -> 403 untuk admin/penjual/kurir.
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');

// throttle:login & throttle:register didefinisikan di AppServiceProvider
// (5 percobaan/menit per email+IP untuk login, 5/menit per IP untuk register)
// agar akun dan formulir pendaftaran tidak dapat dibombardir brute-force.
Route::middleware('guest')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.post');

    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register.post');
});

// Logout (Pengguna Terautentikasi)
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Dashboard Berdasarkan Role
Route::middleware('auth')->group(function () {
    // 1. Role Admin (/admin/...)
    Route::middleware('admin')->group(function () {
        Route::get('/admin/dashboard', [DashboardController::class, 'admin'])->name('admin.dashboard');

        // Modul Admin: Kategori, Produk, Gambar Produk, dan Stok
        Route::prefix('admin')->name('admin.')->group(function () {
            // Pesanan seluruh pelanggan (rincian & status)
            Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
            // Pembatalan pesanan oleh admin -> stok otomatis dikembalikan
            Route::patch('orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');

            // Manajemen pengguna & pegawai (daftar, buat, ubah, reset password)
            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('users/create', [AdminUserController::class, 'create'])->name('users.create');
            Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::patch('users/{user}/toggle-status', [AdminUserController::class, 'toggleStatus'])->name('users.toggle-status');
            Route::patch('users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');
            Route::get('users/{user}/activities', [AdminUserController::class, 'activities'])->name('users.activities');

            // Audit log aktivitas seluruh pengguna (hanya admin)
            Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');

            // Manajemen kurir (daftar kurir & status aktif)
            Route::get('couriers', [AdminCourierController::class, 'index'])->name('couriers.index');
            Route::patch('couriers/{courier}/toggle-status', [AdminCourierController::class, 'toggleStatus'])->name('couriers.toggle-status');

            // CRUD Kategori
            Route::resource('categories', CategoryController::class)->except('show');

            // CRUD Produk
            Route::resource('products', ProductController::class);

            // Stok & status produk
            Route::patch('products/{product}/stock', [ProductController::class, 'updateStock'])->name('products.stock');
            Route::patch('products/{product}/toggle-status', [ProductController::class, 'toggleStatus'])->name('products.toggle-status');

            // Gambar produk (upload, set primary, hapus)
            Route::post('products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
            Route::patch('products/{product}/images/{image}/primary', [ProductImageController::class, 'setPrimary'])->name('products.images.primary');
            Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');

            // Manajemen pembayaran (verifikasi & penolakan hanya oleh admin)
            Route::get('payments', [AdminPaymentController::class, 'index'])->name('payments.index');
            Route::get('payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');
            Route::patch('payments/{payment}/verify', [AdminPaymentController::class, 'verify'])->name('payments.verify');
            Route::patch('payments/{payment}/reject', [AdminPaymentController::class, 'reject'])->name('payments.reject');

            // Pengiriman: pesanan siap kirim, penugasan kurir, & penyelesaian pesanan
            Route::get('shipments', [AdminShipmentController::class, 'index'])->name('shipments.index');
            Route::post('shipments', [AdminShipmentController::class, 'store'])->name('shipments.store');
            Route::patch('shipments/{shipment}/complete', [AdminShipmentController::class, 'complete'])->name('shipments.complete');

            // Moderasi review: admin dapat menghapus review yang tidak sesuai
            Route::delete('reviews/{review}', [AdminReviewController::class, 'destroy'])->name('reviews.destroy');

            // Laporan & analitik (hanya admin): layar, cetak/PDF, dan export CSV
            Route::prefix('reports')->name('reports.')->group(function () {
                Route::get('/', [AdminReportController::class, 'index'])->name('index');
                Route::get('/export', [AdminReportController::class, 'export'])->name('export');
                Route::get('/print', [AdminReportController::class, 'printView'])->name('print');
            });

            // Pengaturan notifikasi otomatis (Telegram, WhatsApp, Email):
            // saklar saluran & kejadian, status konfigurasi, uji kirim, riwayat.
            Route::get('notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications', [AdminNotificationController::class, 'updateSettings'])->name('notifications.settings');
            Route::post('notifications/test', [AdminNotificationController::class, 'test'])
                ->name('notifications.test')
                ->middleware('throttle:5,1'); // maks. 5 pesan uji per menit per IP/admin
            Route::post('notifications/telegram/chat-id', [AdminNotificationController::class, 'resolveTelegramChatId'])
                ->name('notifications.telegram.chat-id')
                ->middleware('throttle:10,1');
        });
    });

    // 2. Role Anggota / Pegawai Toko (/anggota/...)
    //    + alias legacy /seller & /penjual agar tautan lama tetap berfungsi
    Route::middleware('anggota')->group(function () {
        Route::get('/anggota/dashboard', [DashboardController::class, 'anggota'])->name('anggota.dashboard');
        Route::get('/seller/dashboard', [DashboardController::class, 'anggota'])->name('seller.dashboard');
        Route::get('/penjual/dashboard', [DashboardController::class, 'anggota'])->name('penjual.dashboard');
    });

    // 2b. Modul Anggota: pesanan yang perlu diproses, stok barang, riwayat
    Route::middleware('anggota')->group(function () {
        Route::prefix('anggota')->name('anggota.')->group(function () {
            Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/{order}', [SellerOrderController::class, 'show'])->name('orders.show');
            Route::patch('/orders/{order}/processing', [SellerOrderController::class, 'processing'])->name('orders.processing');
            Route::patch('/orders/{order}/ready-to-ship', [SellerOrderController::class, 'readyToShip'])->name('orders.ready-to-ship');
            Route::get('/stock', [AnggotaStockController::class, 'index'])->name('stock.index');
            Route::patch('/stock/{product}', [AnggotaStockController::class, 'update'])->name('stock.update');
        });

        // Alias legacy prefix 'seller.*' (nama role lama) — handler sama.
        Route::prefix('seller')->name('seller.')->group(function () {
            Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/{order}', [SellerOrderController::class, 'show'])->name('orders.show');
            Route::patch('/orders/{order}/processing', [SellerOrderController::class, 'processing'])->name('orders.processing');
            Route::patch('/orders/{order}/ready-to-ship', [SellerOrderController::class, 'readyToShip'])->name('orders.ready-to-ship');
        });
    });

    // 3. Role Kurir (/courier/dashboard)
    Route::middleware('courier')->group(function () {
        Route::get('/courier/dashboard', [DashboardController::class, 'courier'])->name('courier.dashboard');
        Route::get('/kurir/dashboard', [DashboardController::class, 'courier'])->name('kurir.dashboard');
    });

    // 3b. Modul Kurir: daftar & proses pengiriman (hanya miliknya)
    Route::middleware('courier')->prefix('courier')->name('courier.')->group(function () {
        Route::get('/shipments', [CourierShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/{shipment}', [CourierShipmentController::class, 'show'])->name('shipments.show');
        Route::patch('/shipments/{shipment}/status', [CourierShipmentController::class, 'updateStatus'])->name('shipments.status');
    });

    // 4. Role Pelanggan (/dashboard)
    Route::middleware('customer')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'customer'])->name('dashboard');
        Route::get('/pelanggan/dashboard', [DashboardController::class, 'customer'])->name('customer.dashboard');

        // 4a. Riwayat & detail pesanan (hanya milik sendiri)
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        // Pelanggan membatalkan pesanan sendiri (hanya sebelum bayar) -> stok kembali
        Route::patch('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

        // 4b. Kelola alamat pengiriman
        Route::get('/addresses', [AddressController::class, 'index'])->name('addresses.index');
        Route::get('/addresses/{address}/edit', [AddressController::class, 'edit'])->name('addresses.edit');
        Route::put('/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::patch('/addresses/{address}/primary', [AddressController::class, 'setPrimary'])->name('addresses.primary');
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
    });

    // 4b. AI Assistant khusus pelanggan (chat, kirim pesan, percakapan baru)
    // throttle: setiap pesan berpotensi memanggil API AI berbayar — tanpa batas,
    // satu sesi bisa membakar kuota API (chatbot beranda publik juga throttle 20/1).
    Route::middleware(['customer', 'throttle:20,1'])->prefix('ai')->name('ai.')->group(function () {
        Route::get('/', [AIController::class, 'chat'])->name('chat');
        Route::post('/message', [AIController::class, 'sendMessage'])->name('message');
        Route::post('/reset', [AIController::class, 'reset'])->name('reset');
    });
});
