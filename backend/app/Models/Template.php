<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'created_by_user_id',
        'slug',
        'name',
        'description',
        'category',
        'language',
        'document_type',
        'format',
        'structure',
        'content_html',
        'latex_source',
        'version',
        'status',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'tags' => 'array',
            'version' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
