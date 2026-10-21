<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --------------------------------------------------------------------------
// Tugas terjadwal (dijalankan otomatis oleh cron: php artisan schedule:run
// setiap menit, atau process manager: php artisan schedule:work)
// --------------------------------------------------------------------------

// Ringkasan penjualan harian -> notifikasi admin (Telegram/WhatsApp/Email)
// setiap hari pukul 07:00 waktu Asia/Jakarta.
// Jalankan otomatis via cron/scheduler, atau manual: php artisan notifications:daily-summary
Schedule::command('notifications:daily-summary')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Pembersihan data antrean yang sudah tua (retensi 30 hari):
// job gagal & batch selesai — mencegah tabel queue membesar tanpa batas.
Schedule::command('queue:prune-failed', ['--hours' => 720])
    ->dailyAt('02:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/scheduler.log'));

Schedule::command('queue:prune-batches', ['--hours' => 720])
    ->dailyAt('02:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/scheduler.log'));
