<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
