<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers(),
            ],
            'password_confirmation' => ['required', 'string'],
            'workspace_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'personal_owner_user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
            'type' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
        ];
    }
}
