<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationDebugReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'app_version',
        'source',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
