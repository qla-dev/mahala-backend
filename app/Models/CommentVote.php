<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'reply_id',
        'user_id',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'reply_id' => 'integer',
            'user_id' => 'integer',
            'value' => 'integer',
        ];
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'reply_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
