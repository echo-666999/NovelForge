<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityApproval extends Model
{
    protected $table = 'entity_approvals';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['id' => 'string', 'novel_id' => 'string', 'entity_id' => 'string', 'current_version_id' => 'string', 'approved_version_id' => 'string', 'source_version_id' => 'string', 'entity_definition_version_id' => 'string', 'target_definition_version_id' => 'string', 'policy_version_id' => 'string', 'actor_id' => 'string', 'definition' => 'array', 'profile' => 'array', 'retired' => 'boolean', 'enabled' => 'boolean', 'created_at' => 'datetime'];
    }
}
