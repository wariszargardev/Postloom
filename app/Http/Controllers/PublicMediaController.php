<?php

namespace App\Http\Controllers;

use App\Enums\LogFeature;
use App\Enums\MediaKind;
use App\Models\Media;
use App\Services\LogService;
use App\Services\Media\JpegConverter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicMediaController extends Controller
{
    public function __construct(private JpegConverter $jpegConverter) {}

    public function __invoke(Media $media): Response
    {
        LogService::startFeatureLogging(LogFeature::Publishing, ['media_id' => $media->id]);

        abort_unless(in_array($media->kind, [MediaKind::Image, MediaKind::Video], true), 404);

        $path = Storage::disk($media->disk)->path($media->path);

        if (! is_file($path)) {
            LogService::warning('Public media link asked for a missing file', ['kind' => $media->kind->value]);
            abort(404);
        }

        if ($media->kind === MediaKind::Image && $media->mime_type !== 'image/jpeg') {
            $jpeg = $this->jpegConverter->toJpeg($path);
            LogService::info('Public media link served', ['kind' => $media->kind->value, 'converted_to_jpeg' => true, 'size_bytes' => strlen($jpeg)]);

            return response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg), 'Cache-Control' => 'no-store']);
        }

        LogService::info('Public media link served', ['kind' => $media->kind->value, 'converted_to_jpeg' => false, 'size_bytes' => $media->size_bytes]);

        return new BinaryFileResponse($path, 200, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'no-store']);
    }
}
