<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentAccessGrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'userId' => $this->user_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'permission' => $this->permission,
            'grantedAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
