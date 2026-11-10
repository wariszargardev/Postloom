<?php

namespace Database\Factories;

use App\Enums\PublishAttemptStatus;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublishAttempt>
 */
class PublishAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_target_id' => PostTarget::factory(),
            'attempt' => 1,
            'status' => PublishAttemptStatus::Succeeded,
            'retryable' => false,
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
            'duration_ms' => 5000,
        ];
    }
}
