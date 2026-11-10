<?php

namespace App\Models;

use App\Enums\PublishAttemptStatus;
use Database\Factories\PublishAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_target_id
 * @property int $attempt
 * @property PublishAttemptStatus $status
 * @property bool $retryable
 * @property string|null $error_message
 * @property string|null $error_class
 * @property string|null $external_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'attempt',
    'status',
    'retryable',
    'error_message',
    'error_class',
    'external_id',
    'started_at',
    'finished_at',
    'duration_ms',
])]
class PublishAttempt extends Model
{
    /** @use HasFactory<PublishAttemptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublishAttemptStatus::class,
            'retryable' => 'boolean',
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PostTarget, $this>
     */
    public function postTarget(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class);
    }
}
