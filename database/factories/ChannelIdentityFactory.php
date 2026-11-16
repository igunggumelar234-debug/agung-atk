<?php

namespace Database\Factories;

use App\Models\ChannelIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelIdentity>
 */
class ChannelIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
            'identifier' => null,
            'status' => ChannelIdentity::STATUS_PENDING,
            'verification_code' => $this->faker->numerify('######'),
            'code_expires_at' => now()->addMinutes(ChannelIdentity::CODE_TTL_MINUTES),
            'verified_at' => null,
            'last_message_at' => null,
        ];
    }

    /**
     * Identitas yang sudah diverifikasi dengan identifier tertentu.
     */
    public function verified(string $identifier): static
    {
        return $this->state(fn (array $attributes): array => [
            'identifier' => $identifier,
            'status' => ChannelIdentity::STATUS_VERIFIED,
            'verification_code' => null,
            'code_expires_at' => null,
            'verified_at' => now(),
        ]);
    }
}
