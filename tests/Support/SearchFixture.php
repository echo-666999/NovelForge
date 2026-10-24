<?php

namespace Tests\Support;

use App\Models\Novel;
use App\Services\HybridSearchService;
use Illuminate\Support\Str;

/** 小型验收使用已有离线证据，所有检索动作经过正式应用服务。 */
final class SearchFixture
{
    public static function command(array $f, string $action, array $data, ?string $key = null): array
    {
        return app(HybridSearchService::class)->execute($f['actor'], $f['novelId'], $action, $data + ['expected_version' => Novel::find($f['novelId'])->row_version, 'reason' => '离线检索验收'], $key ?? (string) Str::uuid());
    }

    public static function create(): array
    {
        $f = MemoryFixture::create();
        $f['profile'] = self::command($f, 'profile', ['name' => '离线档案', 'encoder' => 'offline_hash_v1', 'dimensions' => 128, 'distance_metric' => 'cosine'])['profile_id'];
        $f['build'] = self::command($f, 'build', ['branch_id' => $f['branch'], 'profile_id' => $f['profile']])['build_id'];
        $f['document'] = ['build_id' => $f['build'], 'source_version_id' => $f['sources'][0], 'evidence_id' => $f['evidence'][0], 'title' => '铜钥匙', 'aliases' => ['缺齿钥'], 'introduced_chapter' => 2];
        self::command($f, 'document', $f['document']);

        return $f;
    }

    public static function activate(array $f): void
    {
        self::command($f, 'validate', ['build_id' => $f['build']]);
        self::command($f, 'activate', ['build_id' => $f['build']]);
    }

    public static function query(array $f, array $extra = []): array
    {
        return app(HybridSearchService::class)->search($f['actor'], $f['novelId'], $extra + ['scope' => 'candidate', 'branch_id' => $f['branch'], 'build_id' => $f['build'], 'profile_id' => $f['profile'], 'query' => '铜钥匙', 'chapter' => 20, 'audience' => 'author', 'limit' => 20]);
    }
}
