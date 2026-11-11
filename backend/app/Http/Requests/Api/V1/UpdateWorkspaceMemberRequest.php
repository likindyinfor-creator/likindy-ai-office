<?php

namespace App\Http\Requests\Api\V1;

use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'required', 'string', Rule::in(WorkspaceMembership::ROLES)],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'suspended'])],
            'user_id' => ['prohibited'],
            'workspace_id' => ['prohibited'],
            'permissions' => ['prohibited'],
            'email' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->exists('role') && ! $this->exists('status')) {
                $validator->errors()->add('role', 'At least one membership field must be provided.');
            }
        });
    }
}
