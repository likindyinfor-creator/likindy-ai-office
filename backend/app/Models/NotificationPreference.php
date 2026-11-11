<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'document_activity', 'product_updates', 'usage_alerts'];

    protected function casts(): array
    {
        return [
            'document_activity' => 'boolean',
            'product_updates' => 'boolean',
            'usage_alerts' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
