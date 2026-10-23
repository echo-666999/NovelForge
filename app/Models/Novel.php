<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Novel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'owner_id' => 'string', 'settings' => 'array', 'chapter_length_policy' => 'array', 'archived_at' => 'datetime'];
    }
}
