<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    use HasFactory;

    protected $table = 'posts';

    protected $fillable = [
        'channel_id',
        'author_user_id',
        'mahala_id',
        'content',
        'color_hex',
        'image_uri',
        'is_anonymous',
        'status',
        'hidden',
    ];

    protected function casts(): array
    {
        return [
            'author_user_id' => 'integer',
            'channel_id' => 'integer',
            'is_anonymous' => 'boolean',
            'status' => 'integer',
            'hidden' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Topic::class, 'channel_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function mahala(): BelongsTo
    {
        return $this->belongsTo(Mahala::class, 'mahala_id');
    }
}
