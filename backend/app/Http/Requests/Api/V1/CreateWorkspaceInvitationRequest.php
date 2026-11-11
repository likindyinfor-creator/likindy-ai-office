<?php

namespace App\Http\Requests\Api\V1;

use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWorkspaceInvitationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', Rule::in(array_diff(WorkspaceMembership::ROLES, ['owner']))],
            'workspace_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'invited_by_user_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'personal_owner_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'permissions' => ['prohibited'],
            'membership' => ['prohibited'],
        ];
    }
}
