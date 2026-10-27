<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** UI17补核match各只读分支；不启动生成、不请求真实模型。 */
class RunCenterFormatRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_dispatch_preserves_json_envelopes_and_audit_export(): void
    {
        $this->getJson('/api/v1/operations/health')->assertUnauthorized();
        $f = QueuedRunFixture::create();
        $this->actingAs($f['actor']);
        foreach (['/api/v1/runs', '/api/v1/audit', '/api/v1/operations/health', '/api/v1/novels/'.$f['novelId'].'/runs/'.$f['runId']] as $url) {
            $response = $this->getJson($url)->assertOk()->assertJsonStructure(['data', 'trace_id', 'request_id', 'version'])->assertJsonPath('version', 'v1');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $export = $this->get('/api/v1/audit/export')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=novelforge-audit-page.json');
        $data = json_decode($export->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertDatabaseCount('generation_steps', 0);
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }
}
