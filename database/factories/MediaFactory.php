<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'workspace_id' => Workspace::factory(),
            'kind' => MediaKind::Image,
            'disk' => 'local',
            'path' => "media/test/{$name}.jpg",
            'original_name' => "{$name}.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
            'width' => 1200,
            'height' => 800,
            'duration_seconds' => null,
            'thumbnail_path' => null,
            'checksum' => hash('sha256', $name),
            'alt_text' => null,
        ];
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => MediaKind::Video,
            'path' => str_replace('.jpg', '.mp4', $attributes['path']),
            'original_name' => str_replace('.jpg', '.mp4', $attributes['original_name']),
            'mime_type' => 'video/mp4',
            'duration_seconds' => '42.50',
        ]);
    }

    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => MediaKind::Document,
            'path' => str_replace('.jpg', '.pdf', $attributes['path']),
            'original_name' => str_replace('.jpg', '.pdf', $attributes['original_name']),
            'mime_type' => 'application/pdf',
            'width' => null,
            'height' => null,
        ]);
    }
}
