<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'parent_id',
        'author',
        'content',
        'is_anonymous',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'post_id' => 'integer',
            'parent_id' => 'integer',
            'author' => 'integer',
            'is_anonymous' => 'boolean',
            'status' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(CommentVote::class, 'reply_id');
    }

    public function scopeWithVoteCounts(Builder $query): Builder
    {
        return $query->withCount([
            'votes as upvotes_count' => fn (Builder $query) => $query->where('value', 1),
            'votes as downvotes_count' => fn (Builder $query) => $query->where('value', -1),
        ]);
    }
}
