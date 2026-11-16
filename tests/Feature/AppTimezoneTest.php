<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Zona waktu aplikasi harus mengikuti zona bisnis (WIB, Asia/Jakarta).
 *
 * Perbaikan AUD-002: config/app.php sebelumnya hardcode UTC sehingga tanggal
 * dashboard dan filter laporan harian meleset pada pukul 00.00-07.00 WIB
 * (pengguna melihat tanggal kemarin; whereDate mengelompokkan pesanan pagi
 * hari ke tanggal sebelumnya).
 */
class AppTimezoneTest extends TestCase
{
    public function test_application_uses_business_timezone(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    public function test_application_clock_matches_wib_wall_clock(): void
    {
        // Jam aplikasi (now()) harus identik dengan jam WIB, bukan UTC.
        $this->assertSame(
            Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s'),
            now()->format('Y-m-d H:i:s')
        );
    }
}
