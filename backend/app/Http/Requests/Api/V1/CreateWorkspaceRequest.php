<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(['institution', 'business'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'id' => ['prohibited'],
            'slug' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'personal_owner_user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
            'permissions' => ['prohibited'],
            'membership' => ['prohibited'],
            'memberships' => ['prohibited'],
        ];
    }
}
