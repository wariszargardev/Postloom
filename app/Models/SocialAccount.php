<?php

namespace App\Models;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialAccountType;
use App\Enums\SocialProvider;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property SocialProvider $provider
 * @property SocialAccountType $account_type
 * @property string $provider_account_id
 * @property string $name
 * @property string|null $handle
 * @property string|null $avatar_url
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property array<int, string>|null $scopes
 * @property bool $needs_reconnect
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'provider',
    'account_type',
    'provider_account_id',
    'name',
    'handle',
    'avatar_url',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'scopes',
    'needs_reconnect',
    'connected_at',
    'last_used_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    public const EXPIRING_SOON_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'account_type' => SocialAccountType::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'needs_reconnect' => 'boolean',
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function expiresSoon(): bool
    {
        return $this->token_expires_at !== null
            && ! $this->isTokenExpired()
            && $this->token_expires_at->lte(now()->addDays(self::EXPIRING_SOON_DAYS));
    }

    public function status(): SocialAccountStatus
    {
        return match (true) {
            $this->needs_reconnect, $this->isTokenExpired() => SocialAccountStatus::ReconnectNeeded,
            $this->expiresSoon() => SocialAccountStatus::ExpiringSoon,
            default => SocialAccountStatus::Connected,
        };
    }
}
