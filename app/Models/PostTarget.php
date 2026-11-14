<?php

namespace App\Models;

use App\Enums\PostTargetStatus;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int $social_account_id
 * @property string|null $content_override
 * @property array<string, mixed>|null $settings
 * @property PostTargetStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $external_id
 * @property string|null $external_url
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'social_account_id',
    'content_override',
    'settings',
    'status',
    'attempts',
    'last_error',
    'external_id',
    'external_url',
    'published_at',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => PostTargetStatus::class,
            'attempts' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * @return HasMany<PublishAttempt, $this>
     */
    public function publishAttempts(): HasMany
    {
        return $this->hasMany(PublishAttempt::class);
    }

    public function contentFor(Post $post): string
    {
        return filled($this->content_override) ? (string) $this->content_override : $post->content;
    }
}
