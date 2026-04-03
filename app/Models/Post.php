<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'author_user_id',
        'author_username',
        'mahala_id',
        'channel_id',
        'content',
        'votes_count',
        'replies_count',
        'color_class',
        'is_anonymous',
        'is_image',
        'image_url',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'is_image' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
