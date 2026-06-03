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
        'notifications_app_location',
        'notifications_app_comments',
        'notifications_app_votes',
        'notifications_location',
        'notifications_comments',
        'notifications_votes',
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
            'notifications_app_location' => 'boolean',
            'notifications_app_comments' => 'boolean',
            'notifications_app_votes' => 'boolean',
            'notifications_location' => 'boolean',
            'notifications_comments' => 'boolean',
            'notifications_votes' => 'boolean',
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
