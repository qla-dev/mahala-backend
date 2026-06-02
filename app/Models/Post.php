<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;

    protected $table = 'posts';

    protected $fillable = [
        'topic_id',
        'author_user_id',
        'mahala_id',
        'content',
        'image_uri',
        'is_anonymous',
        'status',
        'hidden',
    ];

    protected function casts(): array
    {
        return [
            'author_user_id' => 'integer',
            'is_anonymous' => 'boolean',
            'status' => 'integer',
            'hidden' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function mahala(): BelongsTo
    {
        return $this->belongsTo(Mahala::class, 'mahala_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PostVote::class);
    }

    public function scopeWithVoteCounts(Builder $query): Builder
    {
        return $query->withCount([
            'votes as upvotes_count' => fn (Builder $query) => $query->where('value', 1),
            'votes as downvotes_count' => fn (Builder $query) => $query->where('value', -1),
        ]);
    }
}
