<?php

namespace App\Http\Requests\Posts;

use App\Models\PostTarget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MarkPostedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('postTarget');

        return $target instanceof PostTarget && $this->user()->can('update', $target->post);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['nullable', 'url:https', 'max:2048', 'regex:#^https://(www\.|m\.|vm\.|vt\.)?tiktok\.com/#i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.regex' => 'Paste a TikTok link (https://www.tiktok.com/...), or leave it empty.',
        ];
    }
}
