<?php

namespace App\Services;

use App\Enums\LogFeature;
use App\Enums\MediaKind;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\MediaInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class MediaService
{
    public function __construct(private MediaInspector $mediaInspector) {}

    /**
     * @return array{media: Media, duplicate: bool}
     */
    public function upload(Workspace $workspace, UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $kind = MediaKind::fromExtension($extension)
            ?? throw new InvalidArgumentException("Unsupported file type: {$extension}");

        LogService::startFeatureLogging(LogFeature::Media, ['workspace_id' => $workspace->id]);
        LogService::info('Media upload received', [
            'kind' => $kind->value,
            'extension' => $extension,
            'size_bytes' => $file->getSize(),
        ]);

        $checksum = hash_file('sha256', $file->getRealPath());
        $existing = $workspace->media()->withTrashed()->where('checksum', $checksum)->first();

        if ($existing !== null && ! $existing->trashed()) {
            LogService::info('Media already in library, reused', ['media_id' => $existing->id]);

            return ['media' => $existing, 'duplicate' => true];
        }

        if ($existing !== null && Storage::disk($existing->disk)->exists($existing->path)) {
            $existing->restore();

            LogService::info('Deleted media uploaded again, restored', ['media_id' => $existing->id]);

            return ['media' => $existing, 'duplicate' => false];
        }

        $diskName = config('postloom.media.disk');
        $disk = Storage::disk($diskName);
        $directory = "media/{$workspace->id}/".now()->format('Y/m');
        $fileStem = (string) Str::uuid();
        $path = $disk->putFileAs($directory, $file, "{$fileStem}.{$extension}");

        if ($path === false) {
            LogService::error('Media file could not be saved to disk', ['disk' => $diskName, 'directory' => $directory]);

            throw new RuntimeException("Could not save {$file->getClientOriginalName()} to the {$diskName} disk.");
        }

        $details = $this->mediaInspector->inspect($kind, $disk->path($path));
        $thumbnailPath = $kind === MediaKind::Video ? $this->createVideoThumbnail($diskName, $path, $directory, $fileStem) : null;

        $media = $existing ?? $workspace->media()->make();
        $media->fill([
            'kind' => $kind,
            'disk' => $diskName,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'width' => $details['width'],
            'height' => $details['height'],
            'duration_seconds' => $details['duration_seconds'],
            'thumbnail_path' => $thumbnailPath,
            'checksum' => $checksum,
        ]);
        $media->deleted_at = null;
        $media->save();

        LogService::info('Media stored', [
            'media_id' => $media->id,
            'kind' => $kind->value,
            'path' => $path,
            'has_thumbnail' => $thumbnailPath !== null,
        ]);

        return ['media' => $media, 'duplicate' => false];
    }

    public function updateAltText(Media $media, ?string $altText): Media
    {
        $media->update(['alt_text' => $altText]);

        LogService::startFeatureLogging(LogFeature::Media, ['workspace_id' => $media->workspace_id]);
        LogService::info('Media alt text updated', ['media_id' => $media->id]);

        return $media;
    }

    public function deletionBlockReason(Media $media): ?string
    {
        $upcomingPosts = $media->posts()->whereIn('status', PostStatus::upcoming())->count();

        if ($upcomingPosts > 0) {
            return trans_choice('{1} This file is used by 1 scheduled post. Remove it from the post or move the post to drafts first.|[2,*] This file is used by :count scheduled posts. Remove it from them or move them to drafts first.', $upcomingPosts);
        }

        $retryablePosts = $media->posts()
            ->whereIn('status', [PostStatus::Failed, PostStatus::PartlyPublished])
            ->whereHas('targets', fn ($query) => $query->where('status', PostTargetStatus::Failed))
            ->count();

        return $retryablePosts === 0
            ? null
            : trans_choice('{1} This file is used by 1 failed post that can still be retried. Retry or delete that post first.|[2,*] This file is used by :count failed posts that can still be retried. Retry or delete them first.', $retryablePosts);
    }

    /**
     * Soft delete: the row gets deleted_at and drafts stop showing it; the file stays on disk so it can be restored.
     */
    public function delete(Media $media): void
    {
        LogService::startFeatureLogging(LogFeature::Media, ['workspace_id' => $media->workspace_id]);

        $media->delete();

        LogService::info('Media deleted', ['media_id' => $media->id]);
    }

    private function createVideoThumbnail(string $diskName, string $videoPath, string $directory, string $fileStem): ?string
    {
        $disk = Storage::disk($diskName);
        $thumbnailPath = "{$directory}/thumbs/{$fileStem}.jpg";
        $disk->makeDirectory("{$directory}/thumbs");

        $created = $this->mediaInspector->makeVideoThumbnail($disk->path($videoPath), $disk->path($thumbnailPath));

        return $created && $disk->exists($thumbnailPath) ? $thumbnailPath : null;
    }
}
