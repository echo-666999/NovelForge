<?php

namespace App\Generation;

use App\Drafting\DraftReplayAdapter;
use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionReplayAdapter;
use App\Planning\PlanningContract;
use App\Planning\PlanningReplayAdapter;
use App\ProvidersGateway\FrozenResponsesAdapterFactory;
use App\ProvidersGateway\ProviderAdapter;
use App\Review\ReviewReplayAdapter;
use App\Revision\RevisionReplayAdapter;
use Illuminate\Support\Facades\Validator;

/** 阶段模式属于冻结输入，真实模式不能借用作者填写的离线响应。 */
final class StageExecutionMode
{
    public static function mode(array $data): string
    {
        $mode = $data['execution_mode'] ?? 'offline_fake';
        if (! in_array($mode, ['offline_fake', 'production'], true)) {
            throw new NovelProblem('INVALID_EXECUTION_MODE', '请选择离线回放或真实模型模式。', 422);
        }

        return $mode;
    }

    public static function validate(array $data): void
    {
        if (self::mode($data) === 'production') {
            if (array_key_exists('offline_output', $data)) {
                throw new NovelProblem('OFFLINE_OUTPUT_FORBIDDEN', '真实模式不得携带离线响应。', 422);
            }
        } else {
            Validator::make($data, ['offline_output' => 'present|array'])->validate();
        }
    }

    public static function node(array $data): array
    {
        return self::mode($data) === 'production' ? [] : ['offline_output' => $data['offline_output']];
    }

    public static function assertRoute(array $data, array $configuration): void
    {
        if (self::mode($data) !== $configuration['route']['content']['mode']) {
            throw new NovelProblem('EXECUTION_MODE_MISMATCH', '所选执行模式与冻结路由不一致。');
        }
    }

    /** 旧运行没有模式字段时按离线处理；不能通过Worker临时输入改为真实请求。 */
    public static function adapter(array $frozen, string $stage, string $model): ProviderAdapter
    {
        if (! in_array($stage, [...array_map(fn ($phase) => 'planning.'.$phase, PlanningContract::PHASES), 'drafting.scene', 'extraction.events', 'review.chapter', 'revision.propose'], true)) {
            throw new NovelProblem('UNREGISTERED_STAGE', '未注册的模型阶段。');
        }
        $mode = self::mode($frozen);
        self::assertRoute($frozen, $frozen['configuration']);
        if ($mode === 'production') {
            if (array_key_exists('offline_output', $frozen['nodes'][0]['input'])) {
                throw new NovelProblem('OFFLINE_OUTPUT_FORBIDDEN', '真实运行快照不得含有离线响应。');
            }

            return app(FrozenResponsesAdapterFactory::class)->make($frozen, $model);
        }
        $output = $frozen['nodes'][0]['input']['offline_output'];

        return match (true) {
            str_starts_with($stage, 'planning.') => new PlanningReplayAdapter($output),
            $stage === 'drafting.scene' => new DraftReplayAdapter($output),
            $stage === 'extraction.events' => new ExtractionReplayAdapter($output),
            $stage === 'review.chapter' => new ReviewReplayAdapter($output),
            $stage === 'revision.propose' => new RevisionReplayAdapter($output),
            default => throw new NovelProblem('UNREGISTERED_STAGE', '未注册的模型阶段。'),
        };
    }
}
