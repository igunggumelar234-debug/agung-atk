<?php

namespace App\Services\Channels;

use App\Jobs\SendChannelReplyJob;
use App\Models\AiConversation;
use App\Models\ChannelIdentity;
use App\Models\User;
use App\Support\AIService;

/**
 * Alur pesan chatbot yang masuk dari WhatsApp/Telegram:
 * identitas -> percakapan -> jawaban AI -> antrean pengiriman balasan.
 */
class InboundChatService
{
    /**
     * Batas ukuran pesan masuk (karakter). Pesan lebih panjang dibalas
     * dengan penjelasan singkat — mencegah payload raksasa membebani
     * pemrosesan AI & penyimpanan riwayat.
     */
    public const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private readonly AIService $ai,
        private readonly ChannelIdentityService $identities,
    ) {}

    /**
     * Proses satu pesan masuk.
     *
     * - Pengguna terverifikasi memakai percakapannya sendiri (konteks role
     *   akun diproleh dari session backend, bukan dari platform).
     * - Pengguna tanpa tautan diperlakukan sebagai tamu (tanpa data pribadi).
     * - Balasan dikirim melalui antrian agar webhook dapat merespons cepat.
     */
    public function handle(string $channel, string $identifier, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        // Batas ukuran pesan (PROMPT bagian J) — tolak sopan sebelum AI dipanggil.
        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            SendChannelReplyJob::dispatch(
                $channel,
                $identifier,
                'Pesan Anda terlalu panjang (maksimal '.self::MAX_MESSAGE_LENGTH
                    .' karakter). Kirim ulang dalam versi lebih singkat agar saya dapat membantu.'
            );

            return;
        }

        $identity = $this->identities->findVerified($channel, $identifier);
        $user = $identity?->user;
        $linked = $user instanceof User && (bool) $user->is_active;

        $conversation = $linked
            ? $this->userConversation($user)
            : $this->guestConversation($channel, $identifier);

        $reply = $this->ai->send($conversation, $text)->content;

        $this->identities->touchLastMessage($identity);

        if (! $linked) {
            $reply .= "\n\n"
                .'Anda mengirim pesan sebagai tamu sehingga data akun tidak ditampilkan. '
                .'Untuk menghubungkan akun: buka menu Profil pada aplikasi, pilih "Integrasi Chatbot", '
                .'lalu kirim kode verifikasi yang muncul ke kanal ini.';
        }

        SendChannelReplyJob::dispatch($channel, $identifier, $reply);
    }

    /**
     * Percakapan pengguna login — menyatu dengan riwayat AI Assistant.
     */
    private function userConversation(User $user): AiConversation
    {
        return $user->aiConversations()->latest('id')->first()
            ?? AiConversation::create([
                'user_id' => $user->id,
                'title' => 'Percakapan Baru',
            ]);
    }

    /**
     * Percakapan tamu per kanal (session_id = "kanal:identifier").
     */
    private function guestConversation(string $channel, string $identifier): AiConversation
    {
        $sessionId = $channel.':'.$identifier;

        return AiConversation::query()
            ->whereNull('user_id')
            ->where('session_id', $sessionId)
            ->latest('id')
            ->first()
            ?? AiConversation::create([
                'user_id' => null,
                'session_id' => $sessionId,
                'title' => 'Percakapan '.($channel === ChannelIdentity::CHANNEL_TELEGRAM ? 'Telegram' : 'WhatsApp'),
            ]);
    }
}
