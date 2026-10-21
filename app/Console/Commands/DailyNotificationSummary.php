<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Services\Notifications\NotificationManager;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DailyNotificationSummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:daily-summary
        {--date= : Tanggal ringkasan dalam format Y-m-d (default: kemarin)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim ringkasan penjualan harian kepada admin melalui notifikasi yang aktif';

    /**
     * Susun ringkasan penjualan kemarin lalu kirim sebagai notifikasi.
     *
     * Jadwal bawaan: setiap hari pukul 07:00 WIB (lihat routes/console.php).
     * Dapat dijalankan manual: php artisan notifications:daily-summary
     * Pengulangan pada tanggal yang sama otomatis dicegah oleh anti-duplikat.
     */
    public function handle(NotificationManager $notifications): int
    {
        $date = $this->option('date') ?: now('Asia/Jakarta')->subDay()->toDateString();

        try {
            $start = Carbon::parse($date, 'Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone'));
            $end = $start->copy()->addDay();
        } catch (\Throwable) {
            $this->error('Format --date tidak valid. Gunakan Y-m-d, misal: 2026-10-15.');

            return self::FAILURE;
        }

        $createdWithin = fn ($query) => $query->whereBetween('created_at', [$start, $end]);

        $summary = [
            'date' => $date,
            'new_orders' => $createdWithin(Order::query())->count(),
            'revenue' => (float) $createdWithin(Order::query())
                ->where('status', '!=', 'cancelled')
                ->sum('grand_total'),
            'completed' => $createdWithin(Order::query())
                ->where('status', 'completed')
                ->count(),
            'pending_payments' => Payment::query()->where('status', 'pending')->count(),
            'active_shipments' => Shipment::query()
                ->whereIn('status', ['waiting', 'picked_up', 'shipping'])
                ->count(),
        ];

        $logs = $notifications->dispatch('report.daily', [
            'summary' => $summary,
            'reference' => 'daily:'.$date,
        ]);

        if ($logs->isEmpty()) {
            $this->info('Ringkasan '.$date.' tidak dikirim (notifikasi nonaktif, saluran belum dikonfigurasi, atau sudah terkirim sebelumnya).');

            return self::SUCCESS;
        }

        $this->info('Ringkasan penjualan '.$date.' diproses untuk '.$logs->count().' pengiriman notifikasi.');

        return self::SUCCESS;
    }
}
