<?php

namespace App\Http\Requests\Media;

use App\Enums\MediaKind;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreMediaRequest extends FormRequest
{
    /**
     * Expected MIME prefix for each kind, checked against the file's real content.
     *
     * @var array<string, string>
     */
    private const MIME_PREFIXES = [
        'image' => 'image/',
        'video' => 'video/',
        'audio' => 'audio/',
        'document' => 'application/pdf',
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.config('postloom.media.max_files_per_upload')],
            'files.*' => [
                'required',
                'file',
                'extensions:'.implode(',', MediaKind::allExtensions()),
                $this->matchesKindRules(...),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.max' => 'Upload at most :max files at a time.',
            'files.*.extensions' => 'Allowed types: '.implode(', ', MediaKind::allExtensions()).'.',
        ];
    }

    private function matchesKindRules(string $attribute, mixed $file, Closure $fail): void
    {
        if (! $file instanceof UploadedFile) {
            return;
        }

        $kind = MediaKind::fromExtension($file->getClientOriginalExtension());

        if ($kind === null) {
            return;
        }

        $mimeType = (string) $file->getMimeType();

        if (! str_starts_with($mimeType, self::MIME_PREFIXES[$kind->value])) {
            $fail("\"{$file->getClientOriginalName()}\" does not look like a real ".strtolower($kind->label()).' file.');

            return;
        }

        if ($file->getSize() > $kind->maxKilobytes() * 1024) {
            $limitInMegabytes = intdiv($kind->maxKilobytes(), 1024);
            $fail("\"{$file->getClientOriginalName()}\" is larger than the {$limitInMegabytes} MB limit for ".strtolower($kind->label()).' files.');
        }
    }
}
