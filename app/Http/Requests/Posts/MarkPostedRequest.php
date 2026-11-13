<?php

namespace App\Http\Requests\Posts;

use App\Models\PostTarget;
use App\Services\Publishing\ManualHandoffService;
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
            'url' => ['nullable', 'url:https', 'max:2048', 'regex:'.$this->destination()['link_pattern']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $destination = $this->destination();
        $platform = $this->target()?->socialAccount->provider->label() ?? 'platform';

        return [
            'url.regex' => "Paste the {$platform} link ({$destination['link_example']}), or leave it empty.",
        ];
    }

    /**
     * @return array{label: string, url: string, link_pattern: string, link_example: string}
     */
    private function destination(): array
    {
        $target = $this->target();

        return $target === null
            ? ManualHandoffService::DESTINATIONS['tiktok']
            : app(ManualHandoffService::class)->destination($target->socialAccount->provider);
    }

    private function target(): ?PostTarget
    {
        $target = $this->route('postTarget');

        return $target instanceof PostTarget ? $target : null;
    }
}
