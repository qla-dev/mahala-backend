<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    use HasFactory;

    public const PRO_INACTIVE = 0;
    public const PRO_MONTHLY = 1;
    public const PRO_YEARLY = 2;

    protected $fillable = [
        'user_id',
        'notifications_app',
        'notifications',
        'locale',
        'pro_status',
        'pro_started_at',
        'pro_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'notifications_app' => 'boolean',
            'notifications' => 'boolean',
            'pro_status' => 'integer',
            'pro_started_at' => 'datetime',
            'pro_ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
