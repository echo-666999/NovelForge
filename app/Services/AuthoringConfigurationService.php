<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\ProvidersGateway\StructuredOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** C04 配置版本与确定性离线回归；不调用任何供应商。 */
final class AuthoringConfigurationService
{
    private const TABLES = ['prompt' => 'prompt_templates', 'route' => 'model_routes', 'style' => 'style_profiles'];

    public function hash(array $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    private function kind(string $kind): string
    {
        if (! isset(self::TABLES[$kind])) {
            throw new NovelProblem('INVALID_CONFIGURATION_KIND', '配置类型不存在。', 422);
        }

        return self::TABLES[$kind];
    }

    /** 幂等键覆盖命令类型和全文；全局配置锁串行化版本号及默认指针。 */
    public function execute(User $actor, string $kind, string $action, array $p, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $table = $this->kind($kind);
        Validator::make(['key' => $key, 'action' => $action], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D'], 'action' => 'in:save,test,publish,retire'])->validate();
        if (strlen(NovelIntentService::canonical($p)) > 200000) {
            throw new NovelProblem('CONFIGURATION_TOO_LARGE', '配置超过大小限制。', 422);
        }

        return DB::transaction(function () use ($actor, $kind, $action, $p, $key, $table) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('c04-configuration'))");
            $hash = $this->hash([$kind, $action, $p]);
            $old = DB::table('configuration_commands')->where(['actor_id' => $actor->id, 'command_key' => $key])->first();
            if ($old) {
                if (! hash_equals($hash, $old->request_hash)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '幂等键已用于其他配置。');
                }

                return json_decode($old->response, true);
            }
            if ($action === 'save') {
                Validator::make($p, ['key' => 'required|string|regex:/^[a-z][a-z0-9_.-]{0,99}$/D', 'stage' => 'required|string|regex:/^[a-z][a-z0-9_.-]{0,79}$/D', 'language' => 'required|string|max:30', 'expected_version' => 'required|integer|min:0', 'reason' => 'required|string|max:2000', 'content' => 'required|array'])->validate();
                if (array_diff(array_keys($p), ['key', 'stage', 'language', 'expected_version', 'reason', 'content'])) {
                    throw new NovelProblem('INVALID_CONFIGURATION', '存在未知配置字段。', 422);
                }
                $parent = DB::table($table)->where('key', $p['key'])->first();
                $latest = $parent ? (int) DB::table($kind.'_versions')->where('parent_id', $parent->id)->max('version') : 0;
                if ($latest !== (int) $p['expected_version']) {
                    throw new NovelProblem('STALE_VERSION', '配置版本已变化，请重新加载。');
                }
                if ($parent && ($parent->stage !== $p['stage'] || $parent->language !== $p['language'])) {
                    throw new NovelProblem('IMMUTABLE_IDENTITY', '阶段和语言属于稳定身份，请新建配置键。');
                }
                $this->validateContent($kind, $p['content']);
                $parentId = $parent?->id ?? DB::table($table)->insertGetId(['key' => $p['key'], 'stage' => $p['stage'], 'language' => $p['language']]);
                $extra = $kind === 'route' ? ['prompt_version_id' => $p['content']['prompt_version_id'], 'style_version_id' => $p['content']['style_version_id']] : [];
                $id = DB::table($kind.'_versions')->insertGetId($extra + ['parent_id' => $parentId, 'version' => $latest + 1, 'content' => NovelIntentService::canonical($p['content']), 'content_hash' => $this->hash($p['content']), 'actor_id' => $actor->id, 'reason' => $p['reason']]);
            } else {
                Validator::make($p, ['version_id' => 'required|integer|min:1'])->validate();
                if (array_diff(array_keys($p), ['version_id'])) {
                    throw new NovelProblem('INVALID_CONFIGURATION', '生命周期命令只接受版本标识。', 422);
                }
                $id = $p['version_id'];
                $v = $this->version($kind, (string) $id);
                if ($action === 'test') {
                    if ($v['status'] !== 'draft') {
                        throw new NovelProblem('INVALID_TRANSITION', '仅草稿可以测试。');
                    }
                    $report = $this->regress($kind, $v['content']);
                    DB::table('configuration_regressions')->insert(['kind' => $kind, 'version_id' => $id, 'content_hash' => $v['content_hash'], 'passed' => $report['passed'], 'report' => NovelIntentService::canonical($report), 'actor_id' => $actor->id]);
                    if ($report['passed']) {
                        DB::table($kind.'_versions')->where('id', $id)->update(['status' => 'tested']);
                    }
                } elseif ($action === 'publish') {
                    if ($v['status'] !== 'tested') {
                        throw new NovelProblem('REGRESSION_REQUIRED', '必须先通过离线回归才能发布。');
                    }
                    // 发布前重新验证引用状态，避免回归后引用被退役。
                    if ($kind === 'route') {
                        $snapshot = $this->routeSnapshot($v['content']);
                        if ($snapshot['prompt']['stage'] !== $v['stage'] || $snapshot['prompt']['language'] !== $v['language']) {
                            throw new NovelProblem('CONTRACT_MISMATCH', '路由与模板阶段或语言不匹配。');
                        }
                    }
                    if ($kind === 'prompt' && $v['stage'] === 'test.echo') {
                        $schema = $v['content']['output_schema'];
                        if ($schema['type'] !== 'object' || array_keys($schema['properties']) !== ['text'] || $schema['required'] !== ['text'] || $schema['properties']['text']['type'] !== 'string') {
                            throw new NovelProblem('CONTRACT_MISMATCH', 'test.echo 输出必须为 text 字符串对象。');
                        }
                    }
                    DB::table($kind.'_versions')->where('id', $id)->update(['status' => 'published']);
                    DB::table($table)->where('id', $v['parent_id'])->update(['default_version_id' => $id]);
                } else {
                    if ($v['status'] !== 'published') {
                        throw new NovelProblem('INVALID_TRANSITION', '仅发布版本可以退役。');
                    }
                    DB::table($table)->where('id', $v['parent_id'])->where('default_version_id', $id)->update(['default_version_id' => null]);
                    DB::table($kind.'_versions')->where('id', $id)->update(['status' => 'retired']);
                }
            }
            $response = $this->version($kind, (string) $id);
            DB::table('configuration_commands')->insert(['actor_id' => $actor->id, 'command_key' => $key, 'request_hash' => $hash, 'response' => NovelIntentService::canonical($response)]);

            return $response;
        });
    }

    /** 严格字段白名单；评分阈值使用整数，避免浮点数破坏规范化摘要。 */
    private function validateContent(string $kind, array $c): void
    {
        $rules = match ($kind) {
            'prompt' => ['purpose' => 'required|string|max:1000', 'body' => 'required|string|max:30000', 'variable_schema' => 'required|array', 'output_schema' => 'required|array', 'capability_requirements' => 'required|array:structured_output', 'capability_requirements.structured_output' => 'required|boolean', 'examples' => 'required|array|min:1|max:30', 'examples.*' => 'required|array:variables,output,required_facts', 'examples.*.variables' => 'present|array', 'examples.*.output' => 'present', 'examples.*.required_facts' => 'present|array|max:30', 'examples.*.required_facts.*' => 'required|string|max:2000'],
            'style' => ['voice' => 'required|array:pov,tone,pace,description,forbidden_patterns,licensed_examples', 'voice.pov' => 'required|string|max:200', 'voice.tone' => 'required|string|max:500', 'voice.pace' => 'required|string|max:500', 'voice.description' => 'required|string|max:2000', 'voice.forbidden_patterns' => 'present|array|max:50', 'voice.forbidden_patterns.*' => 'string|max:200', 'voice.licensed_examples' => 'present|array|max:10', 'voice.licensed_examples.*' => 'string|max:5000', 'genre' => 'required|string|max:100', 'rubric' => 'required|array|min:1|max:20', 'rubric.*' => 'required|array:description,threshold', 'rubric.*.description' => 'required|string|max:1000', 'rubric.*.threshold' => 'required|integer|min:0|max:100', 'max_revision_rounds' => 'required|integer|min:0|max:10', 'examples' => 'required|array|min:1|max:30', 'examples.*' => 'required|array:text,scores,expected_pass', 'examples.*.text' => 'required|string|max:10000', 'examples.*.scores' => 'required|array', 'examples.*.scores.*' => 'required|integer|min:0|max:100', 'examples.*.expected_pass' => 'required|boolean'],
            'route' => ['prompt_version_id' => 'required|integer|min:1|exists:prompt_versions,id', 'style_version_id' => 'required|integer|min:1|exists:style_versions,id', 'primary_model_version_id' => 'required|integer|min:1|exists:model_profile_versions,id', 'fallbacks' => 'present|array|max:2', 'fallbacks.*' => 'required|array:model_version_id,approved,reason', 'fallbacks.*.model_version_id' => 'required|integer|min:1|distinct|exists:model_profile_versions,id', 'fallbacks.*.approved' => 'required|accepted', 'fallbacks.*.reason' => 'required|string|max:1000', 'parameters' => 'required|array:max_output_tokens', 'parameters.max_output_tokens' => 'required|integer|min:1|max:100000', 'mode' => 'required|in:offline_fake,production'],
        };
        Validator::make($c, $rules)->validate();
        $top = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($c), $top)) {
            throw new NovelProblem('INVALID_CONFIGURATION', '配置正文包含未知字段。', 422);
        }
        if ($kind === 'prompt') {
            app(StructuredOutput::class)->schema($c['variable_schema']);
            app(StructuredOutput::class)->schema($c['output_schema']);
            if ($c['variable_schema']['type'] !== 'object') {
                throw new NovelProblem('INVALID_SCHEMA', '变量结构必须为对象。', 422);
            }
        }
        if ($kind === 'route' && in_array((string) $c['primary_model_version_id'], array_map('strval', array_column($c['fallbacks'], 'model_version_id')), true)) {
            throw new NovelProblem('INVALID_CONFIGURATION', '主模型不能重复列为备用。', 422);
        }
        if ($kind === 'style') {
            foreach (array_keys($c['rubric']) as $name) {
                if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $name)) {
                    throw new NovelProblem('INVALID_CONFIGURATION', '评分维度须使用稳定英文键。', 422);
                }
            }
        }
    }

    /** 只允许 {{json:变量名}}；变量作为 JSON 数据转义，替换不递归解释模板。 */
    public function render(array $c, array $variables): string
    {
        $schema = $c['variable_schema'];
        $decoded = json_decode(json_encode((object) $variables, JSON_THROW_ON_ERROR));
        if (app(StructuredOutput::class)->issues($decoded, $schema, '$.variables')) {
            throw new NovelProblem('INVALID_VARIABLES', '变量缺失、类型或范围不符合契约。', 422);
        }
        preg_match_all('/\{\{json:([a-z][a-z0-9_]*)\}\}/', $c['body'], $m);
        $rest = preg_replace('/\{\{json:([a-z][a-z0-9_]*)\}\}/', '', $c['body']);
        if (str_contains($rest, '{{') || str_contains($rest, '}}') || array_diff($m[1], array_keys($schema['properties'])) || array_diff(array_keys($schema['properties']), $m[1])) {
            throw new NovelProblem('UNSAFE_TEMPLATE', '模板必须使用已声明的 JSON 转义变量，且覆盖全部变量。', 422);
        }

        return preg_replace_callback('/\{\{json:([a-z][a-z0-9_]*)\}\}/', function ($match) use ($variables) {
            if (! array_key_exists($match[1], $variables)) {
                throw new NovelProblem('INVALID_VARIABLES', '模板引用变量未提供。', 422);
            }

            return json_encode($variables[$match[1]], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        }, $c['body']);
    }

    /** 手工标注分数的确定性门禁，不冒充实际文学评审或模型质量结论。 */
    public function assessStyle(array $c, string $text, array $scores): array
    {
        if (array_diff(array_keys($scores), array_keys($c['rubric'])) || array_diff(array_keys($c['rubric']), array_keys($scores))) {
            throw new NovelProblem('INVALID_SCORES', '评分维度与冻结策略不一致。', 422);
        }
        $issues = [];
        foreach ($c['rubric'] as $name => $rule) {
            if (! is_int($scores[$name]) || $scores[$name] < 0 || $scores[$name] > 100 || $scores[$name] < $rule['threshold']) {
                $issues[] = 'score:'.$name;
            }
        }
        foreach ($c['voice']['forbidden_patterns'] as $pattern) {
            if (str_contains($text, $pattern)) {
                $issues[] = 'forbidden:'.$pattern;
            }
        }

        return ['passed' => ! $issues, 'issues' => $issues];
    }

    private function regress(string $kind, array $c): array
    {
        $cases = [];
        try {
            if ($kind === 'route') {
                $this->routeSnapshot($c);
                $cases[] = ['passed' => true, 'check' => 'frozen_references_and_declared_capacity'];
            }
            foreach ($c['examples'] ?? [] as $i => $e) {
                if ($kind === 'prompt') {
                    $render = $this->render($c, $e['variables']);
                    $issues = app(StructuredOutput::class)->issues(json_decode(json_encode($e['output'], JSON_THROW_ON_ERROR)), $c['output_schema']);
                    // 只在实际字符串值中找事实，不能把 JSON 字段名或转义表示当作正文。
                    $texts = [];
                    $collect = function (mixed $value) use (&$collect, &$texts): void {
                        if (is_string($value)) {
                            $texts[] = $value;
                        } elseif (is_array($value)) {
                            foreach ($value as $child) {
                                $collect($child);
                            }
                        }
                    };
                    $collect($e['output']);
                    foreach ($e['required_facts'] as $fact) {
                        if (! array_any($texts, fn (string $text): bool => str_contains($text, $fact))) {
                            $issues[] = ['code' => 'FACT_MISSING', 'fact' => $fact];
                        }
                    }
                    $cases[] = ['case' => $i, 'passed' => ! $issues, 'issues' => $issues, 'rendered_bytes' => strlen($render), 'actual_tokens' => null];
                } else {
                    $result = $this->assessStyle($c, $e['text'], $e['scores']);
                    $cases[] = ['case' => $i, 'passed' => $result['passed'] === $e['expected_pass'], 'result' => $result];
                }
            }
        } catch (NovelProblem $e) {
            $cases[] = ['passed' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()];
        }

        return ['passed' => count($cases) > 0 && ! in_array(false, array_column($cases, 'passed'), true), 'mode' => 'offline_fixture', 'real_model_verified' => false, 'actual_tokens' => null, 'cases' => $cases];
    }

    /** 解析路由时冻结真实标识与声明能力；未验证的生产能力不能通过发布门禁。 */
    private function routeSnapshot(array $c): array
    {
        $prompt = $this->version('prompt', (string) $c['prompt_version_id']);
        $style = $this->version('style', (string) $c['style_version_id']);
        if ($prompt['status'] !== 'published' || $style['status'] !== 'published') {
            throw new NovelProblem('UNPUBLISHED_REFERENCE', '路由必须引用已发布提示词和文风版本。');
        }
        // E04评审和E05修订精确共用正文文风版本；其他阶段仍要求原阶段匹配。
        $sharedReviewStyle = in_array($prompt['stage'], ['review.chapter', 'revision.propose'], true) && $style['stage'] === 'drafting.scene';
        if ((! $sharedReviewStyle && $prompt['stage'] !== $style['stage']) || $prompt['language'] !== $style['language']) {
            throw new NovelProblem('CONTRACT_MISMATCH', '提示词与文风的阶段及语言不匹配。');
        }
        $models = [];
        foreach ([(string) $c['primary_model_version_id'], ...array_map('strval', array_column($c['fallbacks'], 'model_version_id'))] as $id) {
            $m = DB::table('model_profile_versions')->find($id);
            $p = $m ? DB::table('provider_versions')->where('provider_id', $m->provider_id)->orderByDesc('version')->first() : null;
            if (! $p || ! $p->enabled) {
                throw new NovelProblem('PROVIDER_DISABLED', '路由模型所属供应商不可用。');
            }
            $cap = json_decode($m->declared_capabilities, true);
            if ($c['mode'] !== 'offline_fake' || $p->interface_type !== 'fake') {
                throw new NovelProblem('CAPABILITY_UNVERIFIED', '目前仅允许离线模拟发布，生产能力与价格尚未验证。');
            }
            if (! is_int($cap['context_tokens'] ?? null) || ! is_int($cap['max_output_tokens'] ?? null) || $cap['context_tokens'] <= $c['parameters']['max_output_tokens'] || $cap['max_output_tokens'] < $c['parameters']['max_output_tokens'] || ($prompt['content']['capability_requirements']['structured_output'] && ($cap['structured_output'] ?? null) !== 'supported')) {
                throw new NovelProblem('CAPABILITY_MISMATCH', '声明容量或结构化能力不满足路由。');
            }
            $models[] = ['model_version_id' => $id, 'provider_version_id' => (string) $p->id, 'provider_id' => (string) $p->provider_id, 'provider_model_id' => DB::table('model_profiles')->where('id', $m->model_profile_id)->value('provider_model_id'), 'declared_capabilities' => $cap, 'verification_status' => $m->verification_status, 'price_version_id' => null, 'price_status' => 'unknown'];
        }

        return ['prompt' => $prompt, 'style' => $style, 'models' => $models, 'parameters' => $c['parameters'], 'real_model_eligible' => false];
    }

    public function freeze(User $actor, array $input, string $stage): array
    {
        app(NovelIntentService::class)->authorize($actor);
        if (array_key_exists('route_version_id', $input) && array_key_exists('route_key', $input)) {
            throw new NovelProblem('INVALID_CONFIGURATION', '路由键和版本标识只能提供一种。', 422);
        }
        Validator::make($input, ['route_version_id' => 'required_without:route_key|integer|min:1', 'route_key' => 'required_without:route_version_id|string|max:100', 'variables' => 'present|array'])->validate();
        if (array_diff(array_keys($input), ['route_version_id', 'route_key', 'variables'])) {
            throw new NovelProblem('INVALID_CONFIGURATION', '运行配置含未知字段。', 422);
        }
        // 与发布/退役同锁，冻结读取到一致的默认指针和版本状态。
        DB::select("SELECT pg_advisory_xact_lock(hashtext('c04-configuration'))");
        // 键引用仅在启动时解析默认版本，历史运行不再读取这个可变指针。
        $routeId = $input['route_version_id'] ?? DB::table('model_routes')->where('key', $input['route_key'])->value('default_version_id');
        if (! $routeId) {
            throw new NovelProblem('UNPUBLISHED_REFERENCE', '路由没有可用的默认发布版本。');
        }
        $route = $this->version('route', (string) $routeId);
        if ($route['status'] !== 'published' || $route['stage'] !== $stage) {
            throw new NovelProblem('UNPUBLISHED_REFERENCE', '路由未发布或阶段不匹配。');
        }
        $snapshot = $this->routeSnapshot($route['content']);
        if ($snapshot['prompt']['stage'] !== $stage || $snapshot['prompt']['language'] !== $route['language'] || $snapshot['style']['language'] !== $route['language']) {
            throw new NovelProblem('CONTRACT_MISMATCH', '模板、文风与路由阶段或语言不匹配。');
        }
        $render = $this->render($snapshot['prompt']['content'], $input['variables']);
        // 当前 test.echo 支架只有 text 输出；不把其他 Schema 错接到该执行器。
        $schema = $snapshot['prompt']['content']['output_schema'];
        if ($stage === 'test.echo' && ($schema['type'] !== 'object' || array_keys($schema['properties']) !== ['text'] || $schema['required'] !== ['text'] || $schema['properties']['text']['type'] !== 'string')) {
            throw new NovelProblem('CONTRACT_MISMATCH', '测试阶段必须返回 text 字符串对象。');
        }

        return ['route' => $route, 'variables' => $input['variables'], 'rendered_prompt' => $render, 'price_version_id' => null, 'price_status' => 'unknown', 'token_measurement' => 'not_implemented_c05'] + $snapshot;
    }

    public function version(string $kind, string $id): array
    {
        $table = $this->kind($kind);
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new NovelProblem('NOT_FOUND', '版本不存在。', 404);
        }
        $v = DB::table($kind.'_versions')->find($id);
        if (! $v) {
            throw new NovelProblem('NOT_FOUND', '版本不存在。', 404);
        }
        $p = DB::table($table)->find($v->parent_id);
        $c = json_decode($v->content, true);
        if (! hash_equals($v->content_hash, $this->hash($c))) {
            throw new NovelProblem('CONFIGURATION_CORRUPT', '配置摘要不一致。');
        }
        $last = DB::table('configuration_regressions')->where(['kind' => $kind, 'version_id' => $id])->orderByDesc('id')->first();

        return ['id' => (string) $v->id, 'parent_id' => (string) $v->parent_id, 'key' => $p->key, 'stage' => $p->stage, 'language' => $p->language, 'version' => $v->version, 'status' => $v->status, 'content' => $c, 'content_hash' => $v->content_hash, 'reason' => $v->reason, 'default_version_id' => $p->default_version_id === null ? null : (string) $p->default_version_id, 'regression' => $last ? json_decode($last->report, true) : null];
    }

    public function listing(User $actor, string $kind, array $filters = []): array
    {
        app(NovelIntentService::class)->authorize($actor);
        $table = $this->kind($kind);
        Validator::make($filters, ['stage' => 'nullable|string|max:80', 'language' => 'nullable|string|max:30', 'status' => 'nullable|in:draft,tested,published,retired', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:100000'])->validate();
        $query = DB::table($kind.'_versions as v')->join($table.' as p', 'p.id', '=', 'v.parent_id');
        foreach (['stage', 'language', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where(($field === 'status' ? 'v.' : 'p.').$field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $query->where('p.key', 'ilike', '%'.$filters['q'].'%');
        }
        $total = (clone $query)->count();
        $page = (int) ($filters['page'] ?? 1);
        $items = $query->orderByDesc('v.id')->forPage($page, 20)->pluck('v.id')->map(fn ($id) => $this->version($kind, (string) $id))->all();
        foreach ($items as &$v) {
            $path = match ($kind) {
                'prompt' => 'prompt','route' => 'route','style' => 'style'
            };
            $v['run_count'] = DB::table('generation_runs')->whereRaw("frozen_config->'configuration'->?->>'id' = ?", [$path, $v['id']])->count();
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => 20];
    }
}
