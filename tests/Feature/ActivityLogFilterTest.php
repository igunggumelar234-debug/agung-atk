<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bagian 6 MASTER — filter rentang tanggal halaman audit aktivitas.
 * whereDate (fungsi DATE() pada kolom) diganti rentang eksplisit agar
 * index created_at terpakai; cakupan hari harus identik: rentang dari/
 * ke memasukkan seluruh hari (termasuk jam sore) dan input tidak valid
 * tetap tanpa error (perilaku lama juga tidak melempar exception).
 */
class ActivityLogFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
    }

    private function logAt(string $summary, string $datetime): ActivityLog
    {
        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'role' => 'admin',
            'activity_type' => 'uji.filter',
            'summary' => $summary,
        ]);

        $log->forceFill(['created_at' => $datetime])->save();

        return $log;
    }

    public function test_date_range_filter_covers_whole_end_day(): void
    {
        $this->logAt('Log UJI-SIANG-HARI-INI', now()->format('Y-m-d').' 12:00:00');
        $this->logAt('Log UJI-LAMA-2005', '2005-06-15 15:30:00');

        $today = now()->format('Y-m-d');

        // Rentang hari ini (termasuk jam siang) — harus tampil.
        $this->actingAs($this->admin)
            ->get('/admin/activity-logs?from='.$today.'&to='.$today)
            ->assertOk()
            ->assertSee('Log UJI-SIANG-HARI-INI')
            ->assertDontSee('Log UJI-LAMA-2005');

        // Rentang hari 2005 dengan konten jam 15:30 — membuktikan batas
        // 'to' mencakup SELURUH hari (bukan berhenti di tengah malam).
        $this->get('/admin/activity-logs?from=2005-06-15&to=2005-06-15')
            ->assertOk()
            ->assertSee('Log UJI-LAMA-2005')
            ->assertDontSee('Log UJI-SIANG-HARI-INI');
    }

    public function test_invalid_date_input_is_ignored_without_error(): void
    {
        $this->logAt('Log UJI-VALID', now()->format('Y-m-d').' 08:00:00');

        // Input tanggal tidak valid diperlakukan tanpa exception (perilaku
        // whereDate lama juga tidak melempar error). Cakupan baris setelah
        // input sampah bisa berbeda antar engine (MySQL vs SQLite) — yang
        // dijaga di sini adalah TANPA ERROR.
        $this->actingAs($this->admin)
            ->get('/admin/activity-logs?from=bukan-tanggal&to=xxx')
            ->assertOk();
    }
}
