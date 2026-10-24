<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_denied(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_and_foundation_pages_render(): void
    {
        $this->get('/admin/login')->assertOk();
        $user = User::factory()->create();
        $user->is_admin = true;
        $user->save();
        $this->actingAs($user);
        $this->get('/admin')->assertOk();
        $this->get('/admin/project-status')->assertOk();
    }

    public function test_only_one_administrator_can_exist(): void
    {
        $first = User::factory()->create();
        $first->is_admin = true;
        $first->save();
        $second = User::factory()->create();
        $second->is_admin = true;
        $this->expectException(QueryException::class);
        $second->save();
    }

    public function test_database_version_and_extensions(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $version = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
        $this->assertGreaterThanOrEqual(180000, $version);
        $this->assertLessThan(190000, $version);
        $this->assertSame(0.0, (float) DB::selectOne("SELECT '[1,2,3]'::vector <-> '[1,2,3]'::vector AS distance")->distance);
        $this->assertSame(1.0, (float) DB::selectOne("SELECT similarity('NovelForge','NovelForge') AS score")->score);
    }

    public function test_chinese_fulltext_configuration_segments_and_matches(): void
    {
        if (! DB::selectOne("SELECT 1 FROM pg_ts_config WHERE cfgname = 'novelforge_zh'")) {
            $this->markTestSkipped('Chinese extension must be installed on the actual database server.');
        }
        $result = DB::selectOne("SELECT to_tsvector('public.novelforge_zh', ?) @@ plainto_tsquery('public.novelforge_zh', ?) AS matched", ['南京市长江大桥', '长江大桥']);
        $this->assertTrue((bool) $result->matched);
    }

    public function test_only_authorized_b01_b02_b03_b04_b05_c01_schema_is_installed(): void
    {
        $this->assertTrue(Schema::hasTable('novels'));
        $this->assertTrue(Schema::hasTable('chapters'));
        $this->assertTrue(Schema::hasTable('entities'));
        // C01 加入持久编排；正式状态与正文仍属于后续任务。
        $this->assertTrue(Schema::hasTable('blueprint_versions'));
        $this->assertTrue(Schema::hasTable('rolling_plans'));
        $this->assertTrue(Schema::hasTable('requirement_coverages'));
        $this->assertTrue(Schema::hasTable('plan_validations'));
        $this->assertTrue(Schema::hasTable('plan_freezes'));
        $this->assertFalse(Schema::hasTable('story_events'));
        $this->assertFalse(Schema::hasTable('chapter_versions'));
        foreach (['generation_runs', 'generation_steps', 'generation_artifacts', 'generation_dependencies'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        // C02 已授权投递意图、消费回执与每书串行槽。
        foreach (['outbox_events', 'consumer_receipts', 'generation_book_slots'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        // C03 已加入网关记录，但尚无价格和预算业务。
        foreach (['providers', 'provider_secrets', 'provider_versions', 'model_profiles', 'model_profile_versions', 'ai_requests', 'ai_request_evidence', 'provider_commands'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasTable('cost_ledger'));
        $this->get('/admin/novels')->assertRedirect('/admin/login');
    }
}
