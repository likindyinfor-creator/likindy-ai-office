<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantDocumentAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'permission' => ['required', Rule::in(['read', 'edit'])],
            'workspace_id' => ['prohibited'],
            'document_id' => ['prohibited'],
            'granted_by_user_id' => ['prohibited'],
        ];
    }
}
