<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAccessGrant extends Model
{
    use HasUuids;

    public const PERMISSIONS = ['read', 'edit', 'share'];

    protected $fillable = [
        'workspace_id',
        'document_id',
        'user_id',
        'granted_by_user_id',
        'permission',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
