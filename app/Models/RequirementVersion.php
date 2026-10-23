<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequirementVersion extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'id' => 'string', 'novel_id' => 'string', 'requirement_id' => 'string', 'source_version_id' => 'string', 'actor_id' => 'string', 'critical' => 'boolean'];
    }
}
