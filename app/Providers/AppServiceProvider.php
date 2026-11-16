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
use App\Services\Notifications\NotificationManager;
use App\Support\DatabaseKnowledgeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

        // Masalah sistem: job antrean yang gagal memberi tahu admin (kejadian
        // system.issue). Dedupe per referensi (window 60 detik) pada
        // NotificationManager mencegah banjir bila banyak job gagal beruntun,
        // dan teks error disanitasi pabrik pesan sebelum menjadi notifikasi.
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            try {
                if (! config('notifications.enabled')) {
                    return;
                }

                app(NotificationManager::class)->dispatch('system.issue', [
                    'reference' => 'system.issue',
                    'job' => class_basename($event->job->resolveName()),
                    'error' => $event->exception->getMessage(),
                    'attempts' => $event->job->attempts(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('notification.system_issue_dispatch_failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // Pengetahuan skema chatbot di-cache lintas request — segarkan
        // otomatis setelah `php artisan migrate` selesai agar tabel/kolom
        // baru langsung dikenali tanpa perintah manual.
        Event::listen(MigrationsEnded::class, function (): void {
            Cache::forget(DatabaseKnowledgeService::SCHEMA_CACHE_KEY);
        });

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
