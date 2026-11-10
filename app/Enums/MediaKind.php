<?php

namespace App\Enums;

enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Audio => 'Audio',
            self::Document => 'Document',
        };
    }

    /**
     * @return array<int, string>
     */
    public function extensions(): array
    {
        return config("postloom.media.kinds.{$this->value}.extensions");
    }

    public function maxKilobytes(): int
    {
        return (int) config("postloom.media.kinds.{$this->value}.max_kb");
    }

    public static function fromExtension(string $extension): ?self
    {
        $extension = strtolower($extension);

        foreach (self::cases() as $kind) {
            if (in_array($extension, $kind->extensions(), true)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function allExtensions(): array
    {
        return collect(self::cases())->flatMap(fn (self $kind): array => $kind->extensions())->all();
    }
}
