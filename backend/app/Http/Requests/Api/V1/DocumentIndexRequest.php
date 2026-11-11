<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentIndexRequest extends FormRequest
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
            'search' => ['sometimes', 'string', 'max:120'],
            'workspace_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', Rule::in([
                'official-letter',
                'report',
                'proposal',
                'meeting-minutes',
                'general-document',
            ])],
            'language' => ['sometimes', Rule::in(['en', 'sw'])],
            'status' => ['sometimes', Rule::in(['draft', 'completed'])],
            'visibility' => ['sometimes', Rule::in(['private', 'workspace'])],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'sort' => ['sometimes', Rule::in(['title', 'created_at', 'updated_at'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
