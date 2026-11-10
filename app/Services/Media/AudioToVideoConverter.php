<?php

namespace App\Services\Media;

use App\Services\LogService;
use App\Services\Publishing\PublishException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class AudioToVideoConverter
{
    /**
     * Turn an audio file into an MP4 with a still black frame, for platforms that can't post audio.
     * The caller deletes the returned file when done.
     *
     * @throws PublishException
     */
    public function convert(string $absoluteAudioPath): string
    {
        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory.DIRECTORY_SEPARATOR.'audio-video-'.Str::uuid().'.mp4';

        $result = Process::timeout(300)->run([
            config('postloom.media.ffmpeg_path'),
            '-y',
            '-f', 'lavfi',
            '-i', 'color=c=black:s=1280x720:r=1',
            '-i', $absoluteAudioPath,
            '-shortest',
            '-c:v', 'libx264',
            '-tune', 'stillimage',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-movflags', '+faststart',
            $outputPath,
        ]);

        if ($result->failed() || ! is_file($outputPath)) {
            @unlink($outputPath);

            LogService::warning('Audio could not be turned into a video', [
                'exit_code' => $result->exitCode(),
                'error' => mb_substr($result->errorOutput(), -500),
            ]);

            throw PublishException::permanent('The audio file could not be turned into a video (ffmpeg failed).');
        }

        LogService::info('Audio turned into a video', ['size_bytes' => filesize($outputPath)]);

        return $outputPath;
    }
}
