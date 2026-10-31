<?php

namespace Tests\Unit;

use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Planning\PlanningContract;
use App\ProvidersGateway\OpenAiResponsesProtocol;
use App\ProvidersGateway\OpenAiTransportSchema;
use App\ProvidersGateway\ProviderReply;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** 使用实际阶段契约与合成值验证可逆转换，不涉及小说写入或真实供应商。 */
class OpenAiTransportSchemaTest extends TestCase
{
    public static function stages(): array
    {
        return array_map(fn ($s) => [$s], ['blueprint', 'outline', 'chapter_plan', 'scene_plan', 'extraction.events']);
    }

    /** 数组至少一个元素，以实际覆盖嵌套details、事件time与knowledge属性。 */
    private function sample(array $schema, bool $includeOptional): mixed
    {
        return match ($schema['type']) {
            'string' => '样例', 'integer' => 0, 'boolean' => false, 'null' => null,
            'array' => [$this->sample($schema['items'], $includeOptional)],
            'object' => (object) array_map(fn ($s) => $this->sample($s, $includeOptional), array_filter($schema['properties'], fn ($key) => $includeOptional || in_array($key, $schema['required'], true), ARRAY_FILTER_USE_KEY)),
        };
    }

    /** 独立遍历传输Schema，确认每个对象分支封闭、全部必填、限制未被移除。 */
    private function assertStrict(array $schema): void
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                $this->assertStrict($branch);
            }
        } elseif ($schema['type'] === 'object') {
            $this->assertFalse($schema['additionalProperties']);
            $this->assertEqualsCanonicalizing(array_keys((array) $schema['properties']), $schema['required']);
            foreach ((array) $schema['properties'] as $child) {
                $this->assertStrict($child);
            }
        } elseif ($schema['type'] === 'array') {
            $this->assertStrict($schema['items']);
        }
    }

    #[DataProvider('stages')]
    public function test_five_actual_stage_contracts_round_trip_and_preserve_raw_evidence(string $stage): void
    {
        $schema = $stage === 'extraction.events' ? ExtractionContract::schema() : PlanningContract::schema($stage);
        $original = $schema;
        $codec = new OpenAiTransportSchema;
        $protocol = new OpenAiResponsesProtocol;
        $request = $protocol->request(['model' => 'fixture', 'prompt' => '离线输入', 'output_schema' => $schema, 'parameters' => ['max_output_tokens' => 100]]);
        $this->assertStrict($request['text']['format']['schema']);
        foreach ([false, true] as $all) {
            $business = $this->sample($schema, $all);
            $businessBefore = serialize($business);
            $transport = $codec->encode($business, $schema);
            $transportBefore = serialize($transport);
            $this->assertEquals($business, $codec->decode($transport, $schema));
            $this->assertSame($businessBefore, serialize($business));
            $this->assertSame($transportBefore, serialize($transport));
            $raw = json_encode(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($transport, JSON_UNESCAPED_UNICODE)]]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $result = $protocol->response(new ProviderReply(200, $raw), $schema);
            $this->assertSame('succeeded', $result['status']);
            $this->assertEquals($business, $result['output']);
            $this->assertSame($raw, $result['raw_body']);
            $this->assertNull($result['usage']);
            $this->assertSame(OpenAiTransportSchema::VERSION, $result['transport_schema_version']);
        }
        $this->assertSame($original, $schema);
    }

    public function test_absent_null_false_zero_empty_string_and_empty_containers_remain_distinct(): void
    {
        $schema = PlanningContract::object(['nil' => ['type' => 'null'], 'flag' => ['type' => 'boolean'], 'number' => ['type' => 'integer'], 'text' => ['type' => 'string'], 'list' => PlanningContract::list(['type' => 'integer']), 'object' => PlanningContract::object([])], []);
        $codec = new OpenAiTransportSchema;
        $empty = new stdClass;
        $this->assertEquals($empty, $codec->decode($codec->encode($empty, $schema), $schema));
        $full = (object) ['nil' => null, 'flag' => false, 'number' => 0, 'text' => '', 'list' => [], 'object' => new stdClass];
        $encoded = $codec->encode($full, $schema);
        $this->assertTrue(property_exists($encoded->nil, 'value'));
        $this->assertSame('present', $encoded->nil->state);
        $this->assertEquals($full, $codec->decode($encoded, $schema));
        $decoded = $codec->decode($encoded, $schema);
        $this->assertTrue(property_exists($decoded, 'nil'));
        $this->assertNull($decoded->nil);
        $this->assertFalse($decoded->flag);
        $this->assertSame(0, $decoded->number);
        $this->assertSame('', $decoded->text);
        $this->assertSame([], $decoded->list);
        $this->assertInstanceOf(stdClass::class, $decoded->object);
    }

    public function test_field_names_matching_envelope_keys_do_not_collide_and_lists_keep_order(): void
    {
        $schema = PlanningContract::object(['state' => ['type' => 'string'], 'value' => PlanningContract::list(PlanningContract::object(['state' => ['type' => 'string']], []))], []);
        $value = (object) ['state' => 'absent', 'value' => [(object) ['state' => 'present'], new stdClass, (object) ['state' => '最后']]];
        $codec = new OpenAiTransportSchema;
        $this->assertEquals($value, $codec->decode($codec->encode($value, $schema), $schema));
    }

    public function test_malformed_envelopes_missing_required_and_extra_fields_fail_closed(): void
    {
        $schema = PlanningContract::object(['required' => ['type' => 'string'], 'optional' => ['type' => 'integer']], ['required']);
        $good = (object) ['required' => '值', 'optional' => (object) ['state' => 'absent']];
        $bad = [(object) ['required' => '值'], (object) ['optional' => (object) ['state' => 'absent']], (object) ((array) $good + ['extra' => 1])];
        foreach ([null, 0, (object) ['state' => 'other'], (object) ['state' => 'absent', 'value' => 0], (object) ['state' => 'present'], (object) ['state' => 'present', 'value' => null], (object) ['state' => 'present', 'value' => '0'], (object) ['state' => 'present', 'value' => 0, 'extra' => 1]] as $item) {
            $bad[] = (object) ['required' => '值', 'optional' => $item];
        }
        foreach ($bad as $wire) {
            try {
                (new OpenAiTransportSchema)->decode($wire, $schema);
                $this->fail('非法封装不能被修复或忽略');
            } catch (NovelProblem $e) {
                $this->assertSame('INVALID_STRUCTURED_OUTPUT', $e->errorCode);
            }
        }
    }

    public function test_bad_envelope_keeps_raw_reply_and_billable_usage(): void
    {
        $schema = PlanningContract::object(['optional' => ['type' => 'string']], []);
        $raw = json_encode(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => '{"optional":{"state":"absent","value":"不可隐藏"}}']]]], 'usage' => ['input_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 0], 'output_tokens' => 3, 'output_tokens_details' => ['reasoning_tokens' => 0]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $r = (new OpenAiResponsesProtocol)->response(new ProviderReply(200, $raw), $schema);
        $this->assertSame('failed', $r['status']);
        $this->assertSame('INVALID_STRUCTURED_OUTPUT', $r['error_code']);
        $this->assertNull($r['output']);
        $this->assertSame($raw, $r['raw_body']);
        $this->assertSame(3, $r['usage']['output_tokens']);
    }

    /** 业务允许的深度也可能因封装超出供应商上限，必须在发送前阻断。 */
    public function test_transport_nesting_limit_is_enforced_after_expansion(): void
    {
        $schema = ['type' => 'string'];
        for ($i = 0; $i < 7; $i++) {
            $schema = PlanningContract::object(['child' => $schema], []);
        }
        try {
            (new OpenAiTransportSchema)->compile($schema);
            $this->fail('传输对象深度超限不能发送');
        } catch (NovelProblem $e) {
            $this->assertSame('UNSUPPORTED_PROVIDER_SCHEMA', $e->errorCode);
        }
    }

    public function test_limits_and_required_null_are_not_weakened(): void
    {
        $schema = PlanningContract::object(['nil' => ['type' => 'null'], 'text' => ['type' => 'string', 'maxLength' => 2], 'list' => PlanningContract::list(['type' => 'integer'], 1)], ['nil']);
        foreach ([(object) ['nil' => null, 'text' => (object) ['state' => 'present', 'value' => '超出限长'], 'list' => (object) ['state' => 'absent']], (object) ['nil' => null, 'text' => (object) ['state' => 'absent'], 'list' => (object) ['state' => 'present', 'value' => [1, 2]]]] as $wire) {
            try {
                (new OpenAiTransportSchema)->decode($wire, $schema);
                $this->fail('长度限制不能被传输转换移除');
            } catch (NovelProblem $e) {
                $this->assertSame('INVALID_STRUCTURED_OUTPUT', $e->errorCode);
            }
        }
    }
}
