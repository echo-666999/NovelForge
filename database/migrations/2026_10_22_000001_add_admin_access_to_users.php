<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_admin')->default(false));
        // 单人后台：数据库层阻止并发创建第二个管理员。
        $connection = Schema::getConnection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable('users');
        $index = $grammar->wrap($connection->getTablePrefix().'users_single_admin');
        $connection->statement("CREATE UNIQUE INDEX {$index} ON {$table} (is_admin) WHERE is_admin = true");
    }

    public function down(): void
    {
        $connection = Schema::getConnection();
        $index = $connection->getQueryGrammar()->wrap($connection->getTablePrefix().'users_single_admin');
        $connection->statement("DROP INDEX IF EXISTS {$index}");
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
