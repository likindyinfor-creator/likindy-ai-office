<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAttachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'document_id',
        'uploaded_by_user_id',
        'original_filename',
        'mime_type',
        'size',
        'storage_path',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
