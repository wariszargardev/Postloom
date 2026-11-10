<?php

namespace Database\Factories;

use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider' => SocialProvider::LinkedIn,
            'account_type' => SocialAccountType::Profile,
            'provider_account_id' => fake()->unique()->bothify('????????##'),
            'name' => fake()->name(),
            'handle' => fake()->userName(),
            'avatar_url' => fake()->imageUrl(),
            'access_token' => fake()->sha256(),
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(60),
            'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
            'needs_reconnect' => false,
            'connected_at' => now(),
            'last_used_at' => null,
        ];
    }

    public function expiringSoon(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->addDays(3)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subDay()]);
    }

    public function needsReconnect(): static
    {
        return $this->state(fn () => ['needs_reconnect' => true]);
    }

    public function youTube(): static
    {
        return $this->state(fn () => [
            'provider' => SocialProvider::YouTube,
            'account_type' => SocialAccountType::Channel,
            'provider_account_id' => 'UC'.fake()->unique()->regexify('[A-Za-z0-9_-]{22}'),
            'handle' => fake()->userName(),
            'refresh_token' => fake()->sha256(),
            'token_expires_at' => now()->addHour(),
            'scopes' => ['openid', 'profile', 'email', 'https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'],
        ]);
    }
}
