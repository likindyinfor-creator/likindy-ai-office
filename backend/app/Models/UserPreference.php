<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'locale', 'timezone', 'date_format', 'appearance'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
