<?php

namespace App\Http\Controllers;

use App\Jobs\SendChannelReplyJob;
use App\Models\ChannelIdentity;
use App\Services\Channels\ChannelIdentityService;
use App\Services\Channels\InboundChatService;
use App\Services\Notifications\WhatsAppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhook resmi WhatsApp Business Cloud API (Meta) & Telegram Bot API.
 *
 * - Verifikasi sumber: token verifikasi (WA GET), signature HMAC (WA POST),
 *   dan secret token (Telegram).
 * - Anti-duplikat: wamid (WA) / update_id (Telegram) lewat cache.
 * - Identitas pengguna diverifikasi lewat kode (bukan username/nomor mentah).
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly ChannelIdentityService $identities,
        private readonly InboundChatService $chat,
    ) {}

    /**
     * Verifikasi webhook WhatsApp dari Meta (GET hub.challenge).
     *
     * Meta menuntut body berisi challenge apa adanya dengan status 200.
     */
    public function whatsappVerify(Request $request): Response
    {
        // PHP mengubah titik pada nama parameter query menjadi garis bawah
        // ('hub.mode' -> 'hub_mode'), jadi kedua bentuk dibaca; key Meta
        // tidak dibaca via query('...') karena jatuh ke dot-notation.
        $query = $request->query();

        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');
        $expected = (string) config('services.whatsapp.verify_token', '');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            return response('Verification token mismatch', 403);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Pesan masuk WhatsApp dari Meta (POST notifikasi webhook).
     */
    public function whatsappReceive(Request $request): JsonResponse
    {
        if (! config('services.whatsapp.enabled')) {
            return response()->json(['ok' => true, 'ignored' => 'disabled']);
        }

        $raw = (string) $request->getContent();

        // Validasi signature Meta (X-Hub-Signature-256) bila app secret tersedia.
        // Tanpa app secret, sumber pesan tetap dibatasi oleh throttle + isi payload.
        $appSecret = (string) config('services.whatsapp.app_secret', '');

        if ($appSecret !== '') {
            $signature = (string) $request->header('X-Hub-Signature-256', '');
            $expected = 'sha256='.hash_hmac('sha256', $raw, $appSecret);

            if ($signature === '' || ! hash_equals($expected, $signature)) {
                Log::warning('webhook.whatsapp.signature_invalid');

                return response()->json(['ok' => false, 'error' => 'Signature tidak valid.'], 403);
            }
        }

        $payload = $request->json()->all();

        foreach ($this->extractWhatsAppStatuses($payload) as $status) {
            // Meta dapat mengirim ulang callback status yang sama.
            $dedupeKey = 'chatbot:wa:status:'.$status['id'].':'.$status['status'];

            if (! Cache::add($dedupeKey, 1, now()->addDay())) {
                continue;
            }

            // Status pengiriman terakhir disimpan untuk ditampilkan pada
            // dashboard (tanpa menyimpan isi pesan atau kredensial apa pun).
            Cache::put(
                'chatbot.webhook.last_whatsapp_status',
                $status + ['at' => now()->toIso8601String()],
                now()->addDays(30)
            );

            Log::info('webhook.whatsapp.status', [
                'message_id' => $status['id'],
                'status' => $status['status'],
                'error' => $status['error'],
            ]);
        }

        foreach ($this->extractWhatsAppMessages($payload) as $message) {
            // Anti-duplikat: Meta dapat mengirim ulang webhook yang sama.
            if (! Cache::add('chatbot:wa:'.$message['id'], 1, now()->addDay())) {
                continue;
            }

            $identifier = WhatsAppNotificationService::normalizeInternational($message['from'])
                ?? $message['from'];

            $this->chat->handle(
                ChannelIdentity::CHANNEL_WHATSAPP,
                $identifier,
                $message['text'],
            );
        }

        Cache::put('chatbot.webhook.last_whatsapp', now()->toIso8601String(), now()->addDays(30));

        return response()->json(['ok' => true]);
    }

    /**
     * Pesan masuk dari Fonnte webhook (POST /webhooks/fonnte/{secret}).
     *
     * Fonnte TIDAK menandatangani payload — verifikasi memakai secret di
     * path URL dan bersifat GAGAL-TERTUTUP (403 bila secret belum diatur
     * atau tidak cocok). Alur pemrosesan memakai pipeline chatbot yang sama
     * dengan webhook WhatsApp/Telegram (identitas kanal + balasan via job).
     */
    public function fonnteReceive(Request $request, string $token): JsonResponse
    {
        if (! config('services.whatsapp.enabled')) {
            return response()->json(['ok' => true, 'ignored' => 'disabled']);
        }

        $secret = (string) config('services.fonnte.webhook_secret', '');

        // Gagal-tertutup: tanpa secret terkonfigurasi, tidak ada request yang
        // diproses — mencegah pihak lain menyamar sebagai Fonnte.
        if ($secret === '' || ! hash_equals($secret, $token)) {
            Log::warning('webhook.fonnte.secret_invalid');

            return response()->json(['ok' => false, 'error' => 'Secret webhook tidak valid.'], 403);
        }

        $payload = $request->json()->all();

        Cache::put('chatbot.webhook.last_fonnte', now()->toIso8601String(), now()->addDays(30));

        $sender = trim((string) ($payload['sender'] ?? ''));
        $text = trim((string) ($payload['message'] ?? ''));
        $member = trim((string) ($payload['member'] ?? ''));

        if ($sender === '' || $text === '') {
            return response()->json(['ok' => true, 'ignored' => 'no_text_message']);
        }

        // Pesan grup (ada pengirim anggota) tidak diproses — chatbot hanya
        // melayani pesan langsung agar konteks akun tetap per-pengguna.
        if ($member !== '') {
            return response()->json(['ok' => true, 'ignored' => 'group_message']);
        }

        // Anti-duplikat: Fonnte tidak menyertakan id pesan unik, sehingga
        // kunci dibuat dari pengirim + timestamp + isi (retry identik tertahan).
        $dedupeKey = 'chatbot:fn:'.sha1($sender.'|'.($payload['timestamp'] ?? '').'|'.$text);

        if (! Cache::add($dedupeKey, 1, now()->addDay())) {
            return response()->json(['ok' => true, 'ignored' => 'duplicate']);
        }

        $identifier = WhatsAppNotificationService::normalizeInternational($sender) ?? $sender;

        $this->chat->handle(
            ChannelIdentity::CHANNEL_WHATSAPP,
            $identifier,
            $text,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Pesan masuk dari Telegram Bot API (POST webhook).
     */
    public function telegramReceive(Request $request): JsonResponse
    {
        if (! config('services.telegram.enabled')) {
            return response()->json(['ok' => true, 'ignored' => 'disabled']);
        }

        $secret = (string) config('services.telegram.webhook_secret', '');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        // Gagal-tertutup: webhook hanya diproses bila secret sudah dikonfigurasi
        // dan cocok — mencegah pihak lain menyamar sebagai Telegram.
        if ($secret === '' || ! hash_equals($secret, $provided)) {
            Log::warning('webhook.telegram.secret_invalid');

            return response()->json(['ok' => false, 'error' => 'Secret webhook tidak valid.'], 403);
        }

        $payload = $request->json()->all();
        $updateId = $payload['update_id'] ?? null;
        $chatId = (string) ($payload['message']['chat']['id'] ?? '');
        $text = trim((string) ($payload['message']['text'] ?? ''));

        Cache::put('chatbot.webhook.last_telegram', now()->toIso8601String(), now()->addDays(30));

        if ($updateId === null || $chatId === '' || $text === '') {
            return response()->json(['ok' => true, 'ignored' => 'no_text_message']);
        }

        // Anti-duplikat: Telegram mengirim ulang update bila respons lambat/putus.
        if (! Cache::add('chatbot:tg:'.$updateId, 1, now()->addDay())) {
            return response()->json(['ok' => true, 'ignored' => 'duplicate']);
        }

        // Kode verifikasi tautan akun (enam digit) — hanya bila benar-benar
        // ada kode pending yang cocok; selain itu diteruskan ke chatbot.
        if (preg_match('/^\d{6}$/', $text) === 1 && $this->hasPendingTelegramCode($text)) {
            $result = $this->identities->verifyCode(ChannelIdentity::CHANNEL_TELEGRAM, $chatId, $text);

            $reply = $result['ok']
                ? 'Akun berhasil dihubungkan. Sekarang Anda dapat bertanya tentang data akun Anda melalui bot ini.'
                : 'Verifikasi gagal: '.$result['error'];

            SendChannelReplyJob::dispatch(ChannelIdentity::CHANNEL_TELEGRAM, $chatId, $reply);

            return response()->json(['ok' => true, 'linked' => $result['ok']]);
        }

        if ($text === '/start') {
            SendChannelReplyJob::dispatch(
                ChannelIdentity::CHANNEL_TELEGRAM,
                $chatId,
                'Halo! Saya chatbot '.config('app.name').'. Kirim pertanyaan Anda dalam bahasa sehari-hari '
                .'atau kirim kode verifikasi dari menu Profil → Integrasi Chatbot untuk menghubungkan akun.',
            );

            return response()->json(['ok' => true, 'greeting' => true]);
        }

        $this->chat->handle(ChannelIdentity::CHANNEL_TELEGRAM, $chatId, $text);

        return response()->json(['ok' => true]);
    }

    /**
     * Apakah ada kode verifikasi Telegram pending yang cocok dengan teks ini.
     */
    private function hasPendingTelegramCode(string $code): bool
    {
        return ChannelIdentity::query()
            ->where('channel', ChannelIdentity::CHANNEL_TELEGRAM)
            ->where('status', ChannelIdentity::STATUS_PENDING)
            ->where('verification_code', $code)
            ->whereNotNull('code_expires_at')
            ->where('code_expires_at', '>', now())
            ->exists();
    }

    /**
     * Ekstrak status pengiriman (sent/delivered/read/failed) dari payload.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{id: string, status: string, recipient: string, error: ?string, timestamp: string}>
     */
    private function extractWhatsAppStatuses(array $payload): array
    {
        $statuses = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $id = (string) ($status['id'] ?? '');
                    $state = (string) ($status['status'] ?? '');

                    if ($id === '' || $state === '') {
                        continue;
                    }

                    $errors = $status['errors'] ?? [];
                    $firstError = is_array($errors) && $errors !== [] ? ($errors[0] ?? null) : null;

                    $statuses[] = [
                        'id' => $id,
                        'status' => $state,
                        'recipient' => (string) ($status['recipient_id'] ?? ''),
                        'error' => is_array($firstError)
                            ? mb_substr((string) ($firstError['title'] ?? $firstError['message'] ?? ''), 0, 200)
                            : null,
                        'timestamp' => (string) ($status['timestamp'] ?? ''),
                    ];
                }
            }
        }

        return $statuses;
    }

    /**
     * Ekstrak pesan teks dari payload webhook WhatsApp.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{id: string, from: string, text: string}>
     */
    private function extractWhatsAppMessages(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    if (($message['type'] ?? null) !== 'text') {
                        continue;
                    }

                    $id = (string) ($message['id'] ?? '');
                    $from = (string) ($message['from'] ?? '');
                    $text = trim((string) ($message['text']['body'] ?? ''));

                    if ($id === '' || $from === '' || $text === '') {
                        continue;
                    }

                    $messages[] = ['id' => $id, 'from' => $from, 'text' => $text];
                }
            }
        }

        return $messages;
    }
}
