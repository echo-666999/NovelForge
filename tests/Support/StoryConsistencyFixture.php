<?php

namespace Tests\Support;

use App\Generation\StageResult;
use App\Models\Novel;
use App\Services\GenerationService;
use App\Services\StoryStateService;
use App\Services\WorldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** D02 用完整离线产物与批准实体构建可定位证据，不依赖真实提供商。 */
final class StoryConsistencyFixture
{
    public static function create(): array
    {
        Http::preventStrayRequests();
        $f = QueuedRunFixture::create();
        $f['characters'] = [$f['person']['definition']];
        foreach (['character' => ['同事'], 'location' => ['档案馆', '山城'], 'ability' => ['辨认封记'], 'item' => ['唯一钥匙'], 'rule' => ['旅行与能力规则']] as $type => $names) {
            foreach ($names as $name) {
                $data = ['type' => $type, 'name' => $name, 'description' => '离线一致性测试实体', 'criticality' => 'core', 'source_version_id' => $f['book']['sources'][0]['id'], 'source_quote' => '许砚', 'introduced_chapter' => 1, 'affects_main_plot' => true, 'reason' => '测试设定', 'expected_version' => Novel::find($f['novelId'])->row_version];
                if ($type === 'character') {
                    $data['profile'] = ['role' => 'supporting', 'identity' => '同事', 'voice' => '谨慎', 'limitations' => '不能飞行'];
                }
                if ($type === 'rule') {
                    $data += ['rule' => '旅行至少600秒，能力上限3；复活需明确解释事件', 'scope' => '测试'];
                }
                $v = app(WorldService::class)->execute($f['actor'], $f['novelId'], 'definition', $data, (string) Str::uuid())['definition'];
                app(WorldService::class)->execute($f['actor'], $f['novelId'], 'approve', ['definition_version_id' => $v['id'], 'reason' => '批准测试', 'expected_version' => Novel::find($f['novelId'])->row_version], (string) Str::uuid());
                $f[$type === 'character' ? 'characters' : $type][] = $v;
            }
        }
        // 设置批准变更之后才认领运行，避免冻结版本失效；冻结原规划未发生变化。
        $g = app(GenerationService::class);
        $claim = $g->claim($f['actor'], $f['novelId'], $f['runId'], 'first');
        $text = '许砚目击封记。同事听到解释。唯一钥匙完成交接。';
        $g->complete($f['actor'], $f['novelId'], $f['runId'], $claim, new StageResult('succeeded', ['text' => $text]));
        $artifact = (string) DB::table('generation_artifacts')->where('step_id', $claim['id'])->value('id');
        $source = app(StoryStateService::class)->registerArtifact($f['actor'], $f['novelId'], $artifact);
        $f['evidence'] = app(StoryStateService::class)->evidence($f['actor'], $f['novelId'], $source, hash('sha256', $text), 0, 2, '许砚');

        return $f;
    }

    public static function event(array $f, int $order, array $changes = []): string
    {
        return app(StoryStateService::class)->propose($f['actor'], $f['novelId'], array_replace(['evidence_id' => $f['evidence'], 'definition_version_id' => $f['characters'][0]['id'], 'fact_kind' => 'fact', 'action' => 'set', 'chapter_sequence' => $order, 'narrative_order' => 1, 'attribute' => 'action', 'value' => '检查封记', 'confidence' => 'confirmed', 'story_time' => [], 'reason' => 'D02离线测试'], $changes));
    }

    public static function entry(string $event, ?int $start, ?int $end = null, array $extra = []): array
    {
        return ['event_id' => $event, 'time' => $start === null ? ['kind' => 'unknown'] : ['kind' => $end === null ? 'exact' : 'range', 'clock' => 'relative_seconds', 'start' => $start, 'end' => $end ?? $start]] + $extra;
    }
}
