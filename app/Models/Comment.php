<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'author',
        'content',
        'is_anonymous',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'post_id' => 'integer',
            'author' => 'integer',
            'is_anonymous' => 'boolean',
            'status' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author');
    }
}
