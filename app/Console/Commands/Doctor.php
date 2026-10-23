<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class Doctor extends Command
{
    protected $signature = 'novelforge:doctor';

    protected $description = '检查 PostgreSQL、检索扩展、Redis 基础设施';

    public function handle(): int
    {
        $rows = [];
        $ok = true;
        try {
            if (DB::getDriverName() !== 'pgsql') {
                throw new \RuntimeException('数据库必须为 PostgreSQL');
            }
            $version = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
            $pass = $version >= 180000 && $version < 190000;
            $ok = $ok && $pass;
            $rows[] = ['PostgreSQL 18', $pass ? '就绪' : '版本不符'];
            $extensions = array_column(DB::select('SELECT extname FROM pg_extension'), 'extname');
            foreach (['vector', 'pg_trgm', config('novelforge.search.driver')] as $ext) {
                $pass = in_array($ext, $extensions, true);
                $ok = $ok && $pass;
                $rows[] = [$ext, $pass ? '已安装' : '未安装'];
            }
            $config = config('novelforge.search.configuration');
            $pass = DB::selectOne('SELECT 1 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE c.cfgname = ? AND n.nspname = ?', [$config, 'public']) !== null;
            $ok = $ok && $pass;
            $rows[] = ['中文分词配置', $pass ? '存在（语料效果仍需验收）' : '未就绪'];
        } catch (\Throwable $e) {
            $ok = false;
            $rows[] = ['数据库', '连接或检查失败；请检查独立数据库配置'];
        }
        try {
            Redis::connection()->ping();
            $rows[] = ['Redis', '就绪'];
        } catch (\Throwable $e) {
            $ok = false;
            $rows[] = ['Redis', '不可用'];
        }
        $this->table(['检查项', '结果'], $rows);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
