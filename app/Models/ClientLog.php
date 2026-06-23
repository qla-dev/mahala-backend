<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientLog extends Model
{
    use HasFactory;

    protected $table = 'logs';

    protected $fillable = [
        'user_id',
        'level',
        'source',
        'message',
        'context',
        'platform',
        'app_version',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
