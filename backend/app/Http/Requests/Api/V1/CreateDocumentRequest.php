<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDocumentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                'official-letter',
                'report',
                'proposal',
                'meeting-minutes',
                'general-document',
            ])],
            'language' => ['sometimes', Rule::in(['en', 'sw'])],
            'visibility' => ['sometimes', Rule::in(Document::VISIBILITIES)],
            'status' => ['sometimes', Rule::in(Document::STATUSES)],
            'content_format' => ['sometimes', Rule::in(['html', 'latex'])],
            'content_html' => ['sometimes', 'nullable', 'string'],
            'latex_source' => ['sometimes', 'nullable', 'string'],
            'template_id' => ['sometimes', 'nullable', 'uuid', 'exists:templates,id'],
            'workspace_id' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'permissions' => ['prohibited'],
            'access_grants' => ['prohibited'],
            'version' => ['prohibited'],
            'word_count' => ['prohibited'],
            'typeset' => ['prohibited'],
        ];
    }
}
