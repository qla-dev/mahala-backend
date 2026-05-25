<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
            'coordinates' => 'array',
            'holes' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}
