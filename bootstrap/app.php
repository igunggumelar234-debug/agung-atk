<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AnggotaMiddleware;
use App\Http\Middleware\CourierMiddleware;
use App\Http\Middleware\CustomerMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'anggota' => AnggotaMiddleware::class,
            'courier' => CourierMiddleware::class,
            'customer' => CustomerMiddleware::class,
            'pelanggan' => CustomerMiddleware::class,
        ]);

        // Webhook kanal chatbot tidak membawa token CSRF (dikirim Meta/Telegram);
        // keaslian pesannya diverifikasi lewat token verifikasi WhatsApp,
        // signature HMAC Meta, dan secret token Telegram di dalam controller.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // Akun dinonaktifkan Admin -> logout di seluruh rute ber-web
        // (berjalan setelah StartSession sehingga sesi pembaca valid).
        // SecurityHeaders: header keamanan dasar pada semua respons web.
        $middleware->web(append: [EnsureAccountIsActive::class, SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Respons yang lahir dari exception handler (redirect tamu ke /login,
        // 403/404/422/500) tidak melewati middleware SecurityHeaders —
        // pasang header keamanan dasar yang sama di titik akhir ini.
        $exceptions->respond(function (Response $response): Response {
            return SecurityHeaders::apply($response);
        });
    })->create();
