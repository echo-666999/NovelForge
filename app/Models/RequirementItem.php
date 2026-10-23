<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequirementItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'novel_id' => 'string'];
    }
}
