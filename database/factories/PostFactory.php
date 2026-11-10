<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => fn (array $attributes) => Workspace::query()->whereKey($attributes['workspace_id'])->value('user_id') ?? User::factory(),
            'title' => null,
            'content' => fake()->paragraph(),
            'status' => PostStatus::Draft,
            'scheduled_at' => null,
            'timezone' => 'Asia/Karachi',
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    public function publishing(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Publishing,
            'scheduled_at' => now()->subMinute(),
        ]);
    }
}
