<?php

namespace App\Services\Posts\Rules;

use App\Enums\SocialProvider;
use App\Models\Media;
use Illuminate\Support\Collection;

interface PlatformPostRules
{
    public function provider(): SocialProvider;

    public function maxTextLength(): int;

    /**
     * @return array<string, mixed>
     */
    public function defaultSettings(): array;

    /**
     * Laravel validation rules for this platform's per-target settings, keyed by setting name.
     *
     * @return array<string, array<int, mixed>>
     */
    public function settingsRules(): array;

    /**
     * @param  Collection<int, Media>  $media
     * @return array<int, string>
     */
    public function mediaErrors(Collection $media): array;

    /**
     * Checks that need the text, media and settings together (run after the settings rules pass).
     *
     * @param  Collection<int, Media>  $media
     * @param  array<string, mixed>  $settings
     * @return array<int, string>
     */
    public function targetErrors(string $text, Collection $media, array $settings): array;

    /**
     * What the editor shows about this platform.
     *
     * @return array{max_text_length: int, media_rules: string, settings: array<string, mixed>}
     */
    public function describe(): array;
}
