<?php

namespace App\Providers;

use App\Models\AiConversation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Observers\OrderObserver;
use App\Observers\PaymentObserver;
use App\Observers\ProductObserver;
use App\Observers\ShipmentObserver;
use App\Observers\UserObserver;
use App\Services\ATKImageRecognitionService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Indeks fitur Visual Search (JSON besar) cukup dimuat sekali per
        // proses — aman karena indeks hanya berubah lewat atk:image-index.
        $this->app->singleton(ATKImageRecognitionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pembatas laju percobaan autentikasi (brute-force / pendaftaran massal).
        // Login dibatasi per kombinasi email+IP (melindungi 1 akun) DAN per IP
        // (membatasi pencurian kredensial yang menyasar banyak akun sekaligus).
        RateLimiter::for('login', function (Request $request): array {
            return [
                Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Gunakan tampilan pagination Bootstrap 5 (sesuai CSS yang dipakai aplikasi)
        Paginator::useBootstrapFive();

        // Pengamat kejadian pesanan/pembayaran/stok/pengiriman/akun:
        // menjembatani alur bisnis yang sudah ada ke sistem notifikasi otomatis.
        Order::observe(OrderObserver::class);
        Payment::observe(PaymentObserver::class);
        Shipment::observe(ShipmentObserver::class);
        Product::observe(ProductObserver::class);
        User::observe(UserObserver::class);

        // Sediakan riwayat pesan untuk widget chatbot mengambang di semua menu
        View::composer('partials.home-chatbot', function ($view) {
            if ($view->offsetExists('homeChatMessages')) {
                return;
            }

            try {
                $conversation = AiConversation::query()
                    ->when(
                        auth()->check(),
                        fn ($query) => $query->where('user_id', auth()->id()),
                        fn ($query) => $query->whereNull('user_id')->where('session_id', session()->getId())
                    )
                    ->latest('id')
                    ->first();

                $messages = $conversation
                    ? $conversation->messages()
                        ->reorder()
                        ->latest('id')
                        ->take(15)
                        ->get()
                        ->reverse()
                        ->values()
                    : collect();
            } catch (\Throwable) {
                $messages = collect();
            }

            $view->with('homeChatMessages', $messages);
        });
    }
}
