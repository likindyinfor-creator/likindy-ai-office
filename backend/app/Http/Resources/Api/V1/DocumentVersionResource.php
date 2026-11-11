<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'versionNumber' => $this->version_number,
            'title' => $this->title,
            'status' => $this->status,
            'contentHtml' => $this->content_html,
            'latexSource' => $this->latex_source,
            'changeSummary' => $this->change_summary,
            'changedByUserId' => $this->changed_by_user_id,
            'createdAt' => $this->created_at,
        ];
    }
}
