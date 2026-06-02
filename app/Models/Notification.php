<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    public const TYPE_COMMENT = 1;
    public const TYPE_VOTE = 2;

    protected $fillable = [
        'user_id',
        'from_user_id',
        'type',
        'vote_value',
        'title',
        'body',
        'related_post_id',
        'related_comment_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'from_user_id' => 'integer',
            'type' => 'integer',
            'vote_value' => 'integer',
            'related_post_id' => 'integer',
            'related_comment_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function relatedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'related_post_id');
    }

    public function relatedComment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'related_comment_id');
    }
}
