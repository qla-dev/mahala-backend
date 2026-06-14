<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blocked extends Model
{
    use HasFactory;

    protected $table = 'blocked';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'blocked_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'blocked_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blockedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
