<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspaceId' => $this->workspace_id,
            'ownerId' => $this->owner_user_id,
            'templateId' => $this->template_id,
            'title' => $this->title,
            'type' => $this->type,
            'language' => $this->language,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'contentFormat' => $this->content_format,
            'contentHtml' => $this->content_html,
            'latexSource' => $this->latex_source,
            'typeset' => $this->typeset,
            'wordCount' => $this->word_count,
            'version' => $this->version,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
