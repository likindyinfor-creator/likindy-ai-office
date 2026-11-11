<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasUuids, SoftDeletes;

    public const VISIBILITIES = ['private', 'workspace'];

    public const STATUSES = ['draft', 'completed'];

    protected $fillable = [
        'workspace_id',
        'owner_user_id',
        'created_by_user_id',
        'template_id',
        'title',
        'type',
        'language',
        'visibility',
        'status',
        'content_format',
        'content_html',
        'latex_source',
        'typeset',
        'word_count',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'typeset' => 'boolean',
            'word_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(DocumentAccessGrant::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(DocumentAuditEvent::class);
    }
}
