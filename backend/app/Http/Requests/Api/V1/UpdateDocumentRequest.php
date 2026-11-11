<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in([
                'official-letter',
                'report',
                'proposal',
                'meeting-minutes',
                'general-document',
            ])],
            'language' => ['sometimes', 'required', Rule::in(['en', 'sw'])],
            'visibility' => ['sometimes', 'required', Rule::in(Document::VISIBILITIES)],
            'status' => ['sometimes', 'required', Rule::in(Document::STATUSES)],
            'content_format' => ['sometimes', 'required', Rule::in(['html', 'latex'])],
            'content_html' => ['sometimes', 'nullable', 'string'],
            'latex_source' => ['sometimes', 'nullable', 'string'],
            'template_id' => ['sometimes', 'nullable', 'uuid', 'exists:templates,id'],
            'change_summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
