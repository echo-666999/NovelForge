<?php

namespace Tests\Support;

use App\Models\Novel;
use App\Models\User;
use App\Services\BlueprintService;
use App\Services\ForeshadowLedgerService;
use Illuminate\Support\Str;

/** 只有规划骨架和前十章场景，绝不伪装为百章正式正文。 */
final class ForeshadowFixture
{
    public static function create(bool $approve = true, ?User $actor = null): array
    {
        $f = BlueprintFixture::create(actor: $actor, chapterCount: 10, targetChapters: 100);
        $id = $f['book']['novel']['id'];
        $cmd = fn ($command, $data) => app(BlueprintService::class)->execute($f['actor'], $id, $command, $data + ['expected_version' => Novel::find($id)->row_version, 'reason' => '冻结伏笔前的测试蓝图'], (string) Str::uuid());
        $b = $cmd('save', $f['payload'])['blueprint'];
        if ($approve) {
            $v = $cmd('validate', ['blueprint_version_id' => $b['id']])['validation'];
            $cmd('approve', ['blueprint_version_id' => $b['id'], 'validation_id' => $v['id'], 'report_hash' => $v['report_hash'], 'acknowledge_impact' => true]);
        }
        $f['novelId'] = $id;
        $f['plan'] = ['name' => '铜钥匙缺口', 'surface_clue' => '钥匙齿口有不规则缺损', 'hidden_meaning' => '与旧案封记吻合', 'misdirection' => '读者先认为只是旧物磨损', 'no_early_reveal' => '第100章前不能揭露钥匙来源', 'causal_role' => '证明调包并迫使主角公开证据', 'prerequisite_description' => '核对封记、取得合法知情证据后回收', 'blueprint_version_id' => $b['id'], 'thread_id' => $f['thread']['id'], 'plant_start' => 1, 'plant_end' => 3, 'payoff_target' => 100, 'payoff_start' => 100, 'payoff_end' => 100, 'required' => true, 'importance' => 'major', 'secrecy' => 'author_only', 'reinforcements' => [['start' => 4, 'end' => 5, 'new_information' => '与旧案档案首次关联', 'choice_impact' => '保留钥匙而不出售']], 'entity_version_ids' => [$f['person']['definition']['id']], 'knower_version_ids' => [$f['person']['definition']['id']], 'prerequisite_event_ids' => []];

        return $f;
    }

    public static function command(array $f, string $command, array $data, ?string $key = null): array
    {
        return app(ForeshadowLedgerService::class)->execute($f['actor'], $f['novelId'], $command, $data + ['expected_version' => Novel::find($f['novelId'])->row_version, 'reason' => '作者明确确认测试规划变更'], $key ?? (string) Str::uuid());
    }
}
