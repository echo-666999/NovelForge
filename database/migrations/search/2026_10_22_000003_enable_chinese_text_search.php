<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = config('novelforge.search.driver');
        if (! in_array($driver, ['pg_jieba', 'zhparser'], true)) {
            throw new RuntimeException('Unsupported Chinese parser.');
        }
        if ($driver === 'pg_jieba') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_jieba');
            DB::statement('CREATE TEXT SEARCH CONFIGURATION public.novelforge_zh (COPY = public.jiebacfg)');
        } else {
            // 替换时要求目标服务器已提供 zhparser；不自动降级为 simple。
            DB::statement('CREATE EXTENSION IF NOT EXISTS zhparser');
            DB::statement('CREATE TEXT SEARCH CONFIGURATION public.novelforge_zh (PARSER = zhparser)');
            DB::statement('ALTER TEXT SEARCH CONFIGURATION public.novelforge_zh ADD MAPPING FOR n,v,a,i,e,l WITH simple');
        }
    }

    public function down(): void
    {
        DB::statement('DROP TEXT SEARCH CONFIGURATION IF EXISTS public.novelforge_zh');
    }
};
