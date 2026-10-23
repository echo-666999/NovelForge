<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// 计划及审计只追加；实际不可变约束由 PostgreSQL 触发器执行。
class RollingPlan extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['id' => 'string', 'novel_id' => 'string', 'outline_version_id' => 'string', 'blueprint_version_id' => 'string', 'rolling_plan_id' => 'string', 'plan_node_id' => 'string', 'requirement_version_id' => 'string', 'validation_id' => 'string', 'actor_id' => 'string', 'content' => 'array', 'report' => 'array', 'created_at' => 'datetime'];
    }
}
