<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PremiseVersion extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'id' => 'string', 'novel_id' => 'string', 'input' => 'array'];
    }
}
