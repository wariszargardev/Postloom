<?php

namespace Database\Factories;

use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTarget>
 */
class PostTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'content_override' => null,
            'settings' => ['visibility' => 'PUBLIC'],
            'status' => PostTargetStatus::Pending,
            'attempts' => 0,
        ];
    }
}
