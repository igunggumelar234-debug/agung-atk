<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Support\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AIController extends Controller
{
    public function __construct(private readonly AIService $ai) {}

    /**
     * Halaman chat AI Assistant (riwayat percakapan pelanggan terakhir).
     */
    public function chat(Request $request): View
    {
        $conversation = $request->user()
            ->aiConversations()
            ->with('messages')
            ->latest('id')
            ->first();

        return view('ai.chat', [
            'conversation' => $conversation,
        ]);
    }

    /**
     * Kirim pesan pelanggan → balasan AI (JSON untuk UI chat).
     * Data produk diambil dari database oleh AIService (bukan dikarang).
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ], [
            'message.required' => 'Pesan tidak boleh kosong.',
            'message.max' => 'Pesan maksimal 2000 karakter.',
        ]);

        $user = $request->user();

        $conversation = $user->aiConversations()->latest('id')->first();

        if (! $conversation) {
            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'title' => 'Percakapan Baru',
            ]);
        }

        $assistant = $this->ai->send($conversation, $data['message']);

        return response()->json([
            'reply' => $assistant->content,
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
        ]);
    }

    /**
     * Chatbot publik pada halaman Beranda (tamu & semua role login).
     * Tamu memakai session_id browser; pengguna login memakai akunnya
     * sehingga riwayatnya menyatu dengan halaman AI Assistant (/ai).
     */
    public function homeChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ], [
            'message.required' => 'Pesan tidak boleh kosong.',
            'message.max' => 'Pesan maksimal 2000 karakter.',
        ]);

        $conversation = $this->resolveHomeConversation($request);

        $assistant = $this->ai->send($conversation, $data['message']);

        return response()->json([
            'reply' => $assistant->content,
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
        ]);
    }

    /**
     * Ambil (atau buat) percakapan chatbot Beranda untuk sesi/request ini.
     */
    private function resolveHomeConversation(Request $request): AiConversation
    {
        if ($user = $request->user()) {
            return $user->aiConversations()->latest('id')->first()
                ?? AiConversation::create([
                    'user_id' => $user->id,
                    'title' => 'Percakapan Baru',
                ]);
        }

        return AiConversation::query()
            ->whereNull('user_id')
            ->where('session_id', $request->session()->getId())
            ->latest('id')
            ->first()
            ?? AiConversation::create([
                'user_id' => null,
                'session_id' => $request->session()->getId(),
                'title' => 'Percakapan Baru',
            ]);
    }

    /**
     * Mulai percakapan baru (riwayat lama tetap tersimpan di database).
     */
    public function reset(Request $request): RedirectResponse
    {
        AiConversation::create([
            'user_id' => $request->user()->id,
            'title' => 'Percakapan Baru',
        ]);

        return redirect()
            ->route('ai.chat')
            ->with('success', 'Percakapan baru dimulai. Riwayat sebelumnya tetap tersimpan.');
    }
}
