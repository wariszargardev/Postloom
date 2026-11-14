<?php

namespace App\Http\Requests\Workspaces;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->route('workspace');

        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('workspaces', 'name')
                    ->where('user_id', $this->user()->id)
                    ->withoutTrashed()
                    ->ignore($workspace instanceof Workspace ? $workspace->id : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a workspace with this name.',
        ];
    }
}
