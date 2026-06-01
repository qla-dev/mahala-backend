<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    use HasFactory;

    protected $table = 'topics';

    protected $fillable = [
        'mahala_id',
        'created_by_user_id',
        'name',
        'slug',
        'description',
        'icon',
        'is_premium',
        'is_system',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'created_by_user_id' => 'integer',
            'is_premium' => 'boolean',
            'is_system' => 'boolean',
            'status' => 'integer',
        ];
    }

    public function mahala(): BelongsTo
    {
        return $this->belongsTo(Mahala::class, 'mahala_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function posts(): HasMany
    {
        return $this
            ->hasMany(Post::class, 'mahala_id', 'mahala_id')
            ->where('topic_id', $this->slug);
    }
}
