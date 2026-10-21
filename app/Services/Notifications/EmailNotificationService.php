<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Mail;
use RuntimeException;

class EmailNotificationService
{
    /**
     * Kirim email notifikasi (HTML responsif) melalui Mail Laravel.
     *
     * Konfigurasi SMTP dibaca dari MAIL_* pada .env — kata sandi email
     * (App Password) tidak pernah ditulis di dalam kode.
     *
     * @param  list<string>  $lines
     *
     * @throws RuntimeException bila alamat tujuan tidak valid atau pengiriman gagal.
     */
    public function send(string $to, string $subject, string $text): void
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Alamat email tujuan tidak valid: "'.$to.'".');
        }

        $lines = collect(preg_split('/\R/', $text))
            ->map(fn (string $line) => trim($line))
            ->values()
            ->all();

        Mail::send('emails/notification', [
            'subject' => $subject,
            'lines' => $lines,
            'appName' => config('app.name'),
            'year' => now()->year,
        ], function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });
    }
}
