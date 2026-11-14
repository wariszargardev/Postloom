<?php

namespace App\Models;

use App\Enums\PostStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $title
 * @property string $content
 * @property PostStatus $status
 * @property Carbon|null $scheduled_at
 * @property string $timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['title', 'content', 'status', 'scheduled_at', 'timezone'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'scheduled_at' => 'datetime',
        ];
    }

    /**
     * Always store scheduled_at in UTC. Eloquent saves a date's wall-clock time and ignores its timezone,
     * so a Karachi Carbon would otherwise be saved 5 hours off.
     *
     * @return Attribute<Carbon|null, string|null>
     */
    protected function scheduledAt(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => $value === null || $value === ''
                ? null
                : $this->fromDateTime(Carbon::parse($value)->utc()),
        );
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /**
     * @return BelongsToMany<Media, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
