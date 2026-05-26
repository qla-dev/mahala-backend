<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mahala extends Model
{
    use HasFactory;

    protected $table = 'mahalas';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'slug',
        'status',
        'privacy',
        'owner_id',
        'level',
        'latitude',
        'longitude',
        'coordinates',
        'holes',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'privacy' => 'integer',
            'owner_id' => 'integer',
            'coordinates' => 'array',
            'holes' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
