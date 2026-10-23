<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlueprintEntityRef extends Model
{
    protected $table = 'blueprint_entity_refs';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['id' => 'string', 'novel_id' => 'string', 'source_version_id' => 'string', 'outline_version_id' => 'string', 'base_version_id' => 'string', 'blueprint_version_id' => 'string', 'entity_definition_version_id' => 'string', 'requirement_version_id' => 'string', 'thread_id' => 'string', 'validation_id' => 'string', 'actor_id' => 'string', 'content' => 'array', 'impact' => 'array', 'report' => 'array', 'created_at' => 'datetime'];
    }
}
