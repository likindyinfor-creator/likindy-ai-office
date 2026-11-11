<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentAccessRequest extends FormRequest
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
            'permission' => ['required', Rule::in(['read', 'edit'])],
            'user_id' => ['prohibited'],
            'workspace_id' => ['prohibited'],
            'document_id' => ['prohibited'],
            'granted_by_user_id' => ['prohibited'],
        ];
    }
}
