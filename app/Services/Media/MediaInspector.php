<?php

namespace App\Services\Media;

use App\Enums\MediaKind;
use App\Services\LogService;
use Illuminate\Support\Facades\Process;

class MediaInspector
{
    /**
     * @return array{width: int|null, height: int|null, duration_seconds: float|null}
     */
    public function inspect(MediaKind $kind, string $absolutePath): array
    {
        return match ($kind) {
            MediaKind::Image => $this->inspectImage($absolutePath),
            MediaKind::Video, MediaKind::Audio => $this->inspectWithFfprobe($absolutePath),
            MediaKind::Document => ['width' => null, 'height' => null, 'duration_seconds' => null],
        };
    }

    public function makeVideoThumbnail(string $absoluteVideoPath, string $absoluteThumbnailPath): bool
    {
        $result = Process::timeout(120)->run([
            config('postloom.media.ffmpeg_path'),
            '-y',
            '-ss', '1',
            '-i', $absoluteVideoPath,
            '-frames:v', '1',
            '-vf', 'scale=480:-2',
            $absoluteThumbnailPath,
        ]);

        if ($result->failed()) {
            LogService::warning('Video thumbnail could not be created', [
                'exit_code' => $result->exitCode(),
                'error' => mb_substr($result->errorOutput(), -500),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array{width: int|null, height: int|null, duration_seconds: float|null}
     */
    private function inspectImage(string $absolutePath): array
    {
        $size = @getimagesize($absolutePath);

        return [
            'width' => $size === false ? null : $size[0],
            'height' => $size === false ? null : $size[1],
            'duration_seconds' => null,
        ];
    }

    /**
     * @return array{width: int|null, height: int|null, duration_seconds: float|null}
     */
    private function inspectWithFfprobe(string $absolutePath): array
    {
        $result = Process::timeout(60)->run([
            config('postloom.media.ffprobe_path'),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $absolutePath,
        ]);

        if ($result->failed()) {
            LogService::warning('Media could not be inspected with ffprobe', [
                'exit_code' => $result->exitCode(),
                'error' => mb_substr($result->errorOutput(), -500),
            ]);

            return ['width' => null, 'height' => null, 'duration_seconds' => null];
        }

        /** @var array{format?: array{duration?: string}, streams?: array<int, array{codec_type?: string, width?: int, height?: int}>} $probe */
        $probe = json_decode($result->output(), true) ?: [];
        $videoStream = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'video');
        $duration = $probe['format']['duration'] ?? null;

        return [
            'width' => $videoStream['width'] ?? null,
            'height' => $videoStream['height'] ?? null,
            'duration_seconds' => is_numeric($duration) ? round((float) $duration, 2) : null,
        ];
    }
}
