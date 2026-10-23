<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('NovelForge requires PostgreSQL 18.');
        }
        $version = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
        if ($version < 180000 || $version >= 190000) {
            throw new RuntimeException('NovelForge requires PostgreSQL 18.');
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // 数据库扩展可由其他迁移复用，不在回滚时删除。
    }
};
