<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Notifications\NotificationManager;

class UserObserver
{
    public function __construct(private readonly NotificationManager $notifications) {}

    /**
     * Akun baru terdaftar -> kirim konfirmasi ke email pemilik akun
     * (hanya data akun sendiri: nama, email; tanpa kata sandi).
     */
    public function created(User $user): void
    {
        $this->notifications->dispatch('account.registered', ['user' => $user]);
    }
}
