<?php

namespace App\Console\Commands;

use App\Support\DatabaseKnowledgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Segarkan cache pengetahuan skema chatbot.
 *
 * Biasanya TIDAK perlu dipanggil manual: `php artisan migrate` sudah
 * memicu listener MigrationsEnded yang melupakan cache yang sama.
 * Gunakan setelah perubahan struktur di luar artisan migrate (mis.
 * ALTER manual) atau bila chatbot terlihat memakai struktur lama.
 *
 * Contoh:
 *   php artisan chatbot:refresh-knowledge
 */
class RefreshChatbotKnowledge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:refresh-knowledge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Segarkan cache pengetahuan skema chatbot (tabel/kolom terbaru)';

    /**
     * Execute the console command.
     */
    public function handle(DatabaseKnowledgeService $knowledge): int
    {
        Cache::forget(DatabaseKnowledgeService::SCHEMA_CACHE_KEY);

        // Bangun ulang segera agar hasilnya bisa diverifikasi langsung.
        $tables = $knowledge->appTables();

        $this->info('Cache pengetahuan skema disegarkan. Tabel domain terbaca: '.count($tables).'.');
        $this->line(implode(', ', $tables));

        return self::SUCCESS;
    }
}
