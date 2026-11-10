<?php

namespace App\Http\Requests\Posts;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePostRequest extends FormRequest
{
    public const INTENT_DRAFT = 'draft';

    public const INTENT_SCHEDULE = 'schedule';

    public function authorize(): bool
    {
        $post = $this->route('post');

        return ! $post instanceof Post || $this->user()->can('update', $post);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = $this->workspaceId();

        return [
            'intent' => ['required', Rule::in([self::INTENT_DRAFT, self::INTENT_SCHEDULE])],
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:10000'],
            'timezone' => ['required', 'timezone:all'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'targets' => ['array', 'max:50'],
            'targets.*.social_account_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'targets.*.content_override' => ['nullable', 'string', 'max:10000'],
            'targets.*.settings' => ['nullable', 'array'],
            'media_ids' => ['array', 'max:50'],
            'media_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('media', 'id')->where('workspace_id', $workspaceId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'targets.*.social_account_id.exists' => 'One of the chosen accounts is not in this workspace.',
            'media_ids.*.exists' => 'One of the chosen media files is not in this workspace.',
            'scheduled_at.date_format' => 'Pick a valid date and time.',
        ];
    }

    /**
     * @return array{
     *     title: string|null,
     *     content: string,
     *     timezone: string,
     *     scheduled_at: string|null,
     *     targets: array<int, array{social_account_id: int, content_override: string|null, settings: array<string, mixed>}>,
     *     media_ids: array<int, int>,
     * }
     */
    public function postData(): array
    {
        $validated = $this->validated();

        return [
            'title' => isset($validated['title']) ? (string) $validated['title'] : null,
            'content' => (string) ($validated['content'] ?? ''),
            'timezone' => (string) $validated['timezone'],
            'scheduled_at' => isset($validated['scheduled_at']) ? (string) $validated['scheduled_at'] : null,
            'targets' => array_values(array_map(fn (array $target): array => [
                'social_account_id' => (int) $target['social_account_id'],
                'content_override' => isset($target['content_override']) ? (string) $target['content_override'] : null,
                'settings' => (array) ($target['settings'] ?? []),
            ], (array) ($validated['targets'] ?? []))),
            'media_ids' => array_values(array_map(fn ($mediaId): int => (int) $mediaId, (array) ($validated['media_ids'] ?? []))),
        ];
    }

    public function wantsToSchedule(): bool
    {
        return $this->input('intent') === self::INTENT_SCHEDULE;
    }

    private function workspaceId(): ?int
    {
        $post = $this->route('post');

        return $post instanceof Post ? $post->workspace_id : $this->user()->current_workspace_id;
    }
}
