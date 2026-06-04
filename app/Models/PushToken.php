<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'provider',
        'platform',
        'notification_channel_id',
        'sound',
        'preferences',
        'last_used_at',
        'disabled_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'preferences' => 'array',
            'last_used_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
