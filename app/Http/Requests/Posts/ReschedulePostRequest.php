<?php

namespace App\Http\Requests\Posts;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ReschedulePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');

        return $post instanceof Post && $this->user()->can('update', $post);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i:s'],
        ];
    }

    /**
     * The new moment. The calendar sends wall-clock time in the calendar timezone.
     */
    public function newTime(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d\TH:i:s', (string) $this->validated('scheduled_at'), config('postloom.timezone'));
    }
}
