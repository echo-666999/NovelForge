<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\NovelIntentService;
use App\Services\StoryConsistencyService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 时间线工作台只追加修正版本，历史事件和正式状态不可编辑。 */
class StoryTimeline extends Page
{
    protected static ?string $title = '时间线与角色知识';

    protected static string|\UnitEnum|null $navigationGroup = '创作管理';

    protected string $view = 'filament.story-timeline';

    #[Url]
    public string $novel = '';

    #[Url]
    public string $context = '';

    public array $eventIds = [];

    public string $event = '';

    public string $timeKind = 'unknown';

    public string $clock = 'relative_seconds';

    public string $start = '';

    public string $end = '';

    public bool $activity = false;

    public string $location = '';

    public string $ability = '';

    public string $units = '';

    public string $previousOwner = '';

    public string $revivalRule = '';

    public string $reason = '';

    public string $notice = '';

    public string $person = '';

    public string $place = '';

    public string $timeFilter = '';

    public string $clockFilter = '';

    public string $fromTime = '';

    public string $toTime = '';

    public bool $issuesOnly = false;

    public bool $secretsOnly = false;

    public string $knower = '';

    public int $chapter = 1;

    public string $predecessor = '';

    public string $successor = '';

    public string $dependencyType = 'before';

    public string $fact = '';

    public string $acquired = '';

    public string $method = 'witness';

    public string $informant = '';

    public string $relationFrom = '';

    public string $relationTo = '';

    public string $relationType = '';

    public string $relationState = '';

    public string $ruleVersion = '';

    public string $ruleKind = 'travel';

    public string $ruleSubject = '';

    public string $ruleTarget = '';

    public int $ruleLimit = 0;

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public ?array $knowledge = null;

    #[Locked]
    public string $commandKey = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function books(): array
    {
        return DB::table('novels')->where('owner_id', auth()->id())->get(['id', 'title'])->all();
    }

    public function versions(): array
    {
        if ($this->novel === '') {
            return [];
        }
        try {
            return app(StoryConsistencyService::class)->listing(auth()->user(), $this->novel);
        } catch (NovelProblem) {
            return [];
        }
    }

    public function candidates(): array
    {
        if ($this->novel === '') {
            return [];
        }
        try {
            app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
        } catch (NovelProblem) {
            return [];
        }

        return DB::table('proposed_events')->where('novel_id', $this->novel)->orderBy('chapter_sequence')->orderBy('narrative_order')->limit(1000)->get(['id', 'entity_id', 'chapter_sequence', 'narrative_order', 'attribute', 'fact_kind'])->all();
    }

    public function entities(?string $type = null): array
    {
        if (! $this->candidates()) {
            return [];
        }

        return DB::table('entities')->where('novel_id', $this->novel)->when($type, fn ($q) => $q->where('type', $type))->get(['id', 'name', 'type', 'approved_version_id'])->all();
    }

    public function inspect(): void
    {
        $this->detail = null;
        $this->knowledge = null;
        $this->attempt(function () {
            $this->detail = app(StoryConsistencyService::class)->read(auth()->user(), $this->novel, $this->context);
        });
    }

    public function createContext(): void
    {
        $this->attempt(function () {
            $versions = $this->versions();
            $this->persist(['entries' => array_map(fn ($id) => ['event_id' => (string) $id, 'time' => ['kind' => 'unknown']], $this->eventIds)], $versions[0]['version'] ?? 0);
        });
    }

    // 选中事件时回填当前条目，避免只改时间却意外清空地点、能力或交接信息。
    public function updatedEvent(): void
    {
        if (! $this->detail || $this->detail['novel_id'] !== $this->novel || $this->detail['id'] !== $this->context) {
            return;
        }
        foreach ($this->detail['payload']['entries'] as $e) {
            if ($e['event_id'] !== $this->event) {
                continue;
            }
            $this->timeKind = $e['time']['kind'];
            $this->clock = $e['time']['clock'] ?? 'relative_seconds';
            $this->start = (string) ($e['time']['start'] ?? '');
            $this->end = (string) ($e['time']['end'] ?? '');
            $this->activity = $e['activity'];
            $this->location = $e['location_id'] ?? '';
            $this->ability = $e['ability_id'] ?? '';
            $this->units = (string) ($e['ability_units'] ?? '');
            $this->previousOwner = $e['previous_owner_id'] ?? '';
            $this->revivalRule = $e['revival_rule_id'] ?? '';
        }
    }

    public function reviseTime(): void
    {
        $this->attempt(function () {
            $p = $this->current();
            $found = false;
            foreach ($p['entries'] as &$entry) {
                if ($entry['event_id'] !== $this->event) {
                    continue;
                }
                $found = true;
                $entry = ['event_id' => $this->event, 'time' => $this->timeKind === 'unknown' ? ['kind' => 'unknown'] : ['kind' => $this->timeKind, 'clock' => $this->clock, 'start' => $this->start, 'end' => $this->timeKind === 'exact' ? $this->start : $this->end], 'activity' => $this->activity, 'location_id' => $this->location ?: null, 'ability_id' => $this->ability ?: null, 'ability_units' => $this->units ?: null, 'previous_owner_id' => $this->previousOwner ?: null, 'revival_rule_id' => $this->revivalRule ?: null];
            }
            unset($entry);
            if (! $found) {
                throw new NovelProblem('MISSING_EVENT', '请选择当前版本中的事件。', 422);
            }
            $this->persist($p, $this->detail['version']);
        });
    }

    public function addLink(string $kind): void
    {
        $this->attempt(function () use ($kind) {
            $p = $this->current();
            $row = match ($kind) {
                'dependencies' => ['predecessor_id' => $this->predecessor, 'successor_id' => $this->successor, 'relation_type' => $this->dependencyType],
                'knowledge' => ['knower_id' => $this->knower, 'fact_event_id' => $this->fact, 'acquired_event_id' => $this->acquired, 'method' => $this->method, 'source_knower_id' => $this->informant ?: null],
                'relationships' => ['event_id' => $this->event, 'from_entity_id' => $this->relationFrom, 'to_entity_id' => $this->relationTo, 'relation_type' => $this->relationType, 'state' => $this->relationState],
                'rules' => ['rule_version_id' => $this->ruleVersion, 'kind' => $this->ruleKind, 'subject_id' => $this->ruleSubject, 'target_id' => $this->ruleTarget ?: null, 'limit_value' => $this->ruleLimit],
                default => throw new NovelProblem('INVALID_ACTION', '不支持的条目类型。', 422),
            };
            $p[$kind][] = $row;
            $this->persist($p, $this->detail['version']);
        });
    }

    public function removeLink(string $kind, int $index): void
    {
        $this->attempt(function () use ($kind, $index) {
            $p = $this->current();
            if (! in_array($kind, ['dependencies', 'knowledge', 'relationships', 'rules'], true) || ! isset($p[$kind][$index])) {
                throw new NovelProblem('INVALID_ACTION', '条目不存在。', 422);
            }
            array_splice($p[$kind], $index, 1);
            $this->persist($p, $this->detail['version']);
        });
    }

    public function inspectPov(): void
    {
        $this->knowledge = null;
        $this->attempt(function () {
            $this->current();
            $this->knowledge = app(StoryConsistencyService::class)->pov(auth()->user(), $this->novel, $this->context, $this->knower, $this->chapter);
        });
    }

    public function visibleEvents(): array
    {
        if (! $this->detail || $this->detail['novel_id'] !== $this->novel || $this->detail['id'] !== $this->context) {
            return [];
        }
        $ids = array_merge([], ...array_column($this->detail['report']['issues'], 'event_ids'));

        return array_values(array_filter($this->detail['events'], function ($e) use ($ids) {
            $t = $e['entry']['time'];

            return (! $this->person || $e['entity_id'] === $this->person) && (! $this->place || ($e['entry']['location_id'] ?? ($e['attribute'] === 'location_id' ? $e['value'] : null)) === $this->place) && (! $this->timeFilter || $t['kind'] === $this->timeFilter) && (! $this->clockFilter || $t['clock'] === $this->clockFilter) && (! $this->issuesOnly || in_array($e['id'], $ids, true)) && (! $this->secretsOnly || $e['fact_kind'] === 'hidden_truth') && ($this->fromTime === '' || ($this->clockFilter !== '' && $t['end'] !== null && $t['end'] >= (int) $this->fromTime)) && ($this->toTime === '' || ($this->clockFilter !== '' && $t['start'] !== null && $t['start'] <= (int) $this->toTime));
        }));
    }

    private function current(): array
    {
        if (! $this->detail || $this->detail['novel_id'] !== $this->novel || $this->detail['id'] !== $this->context) {
            throw new NovelProblem('READ_REQUIRED', '请先读取当前小说的时间线版本。', 422);
        }

        return $this->detail['payload'];
    }

    private function persist(array $p, int $version): void
    {
        // 每次人工保存形成新版本；失败重试保留命令键，成功后换键。
        $this->commandKey = $this->commandKey ?: (string) Str::uuid();
        $this->detail = app(StoryConsistencyService::class)->save(auth()->user(), $this->novel, $p + ['expected_version' => $version, 'reason' => $this->reason], $this->commandKey);
        $this->context = $this->detail['id'];
        $this->commandKey = (string) Str::uuid();
        $this->knowledge = null;
        $this->notice = '候选版本已保存并检测；正式故事状态未改变。';
    }

    private function attempt(callable $fn): void
    {
        $this->notice = '';
        try {
            $fn();
        } catch (NovelProblem|ValidationException $e) {
            $this->notice = $e instanceof NovelProblem ? $e->getMessage() : '请检查必填字段、类型与范围。';
        }
    }
}
