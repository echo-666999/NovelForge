<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scene extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'string', 'novel_id' => 'string', 'parent_id' => 'string', 'source_version_id' => 'string', 'details' => 'array', 'content' => 'array', 'created_at' => 'datetime', 'base_version_id' => 'string', 'actor_id' => 'string', 'outline_version_id' => 'string', 'parent_plan_node_id' => 'string', 'volume_id' => 'string', 'story_arc_id' => 'string', 'chapter_id' => 'string', 'scene_id' => 'string'];
    }
}
