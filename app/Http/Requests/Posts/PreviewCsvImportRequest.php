<?php

namespace App\Http\Requests\Posts;

use App\Http\Requests\Media\StoreMediaRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class PreviewCsvImportRequest extends StoreMediaRequest
{
    public const MAX_CSV_KILOBYTES = 1024;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $mediaRules = parent::rules();

        return [
            'csv' => ['required', 'file', 'extensions:csv,txt', 'max:'.self::MAX_CSV_KILOBYTES],
            'files' => ['nullable', 'array', 'max:'.config('postloom.media.max_files_per_upload')],
            'files.*' => $mediaRules['files.*'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'csv.required' => 'Choose a CSV file.',
            'csv.extensions' => 'The import file must be a .csv file.',
            'csv.max' => 'The CSV is larger than 1 MB. Split it into smaller files.',
        ];
    }
}
