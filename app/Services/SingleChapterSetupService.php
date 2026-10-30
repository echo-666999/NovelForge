<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** 单章验收准备的只读盘点；配置存在不能替代实测、预算批准或外发授权。 */
final class SingleChapterSetupService
{
    public function inspect(User $actor): array
    {
        app(NovelIntentService::class)->authorize($actor);

        // 配置完成只看最新非 Fake 版本是否已有密钥；停用不等于尚未录入。
        $providers = DB::table('provider_versions as v')
            ->whereRaw('v.id = (SELECT max(p.id) FROM provider_versions p WHERE p.provider_id = v.provider_id)')
            ->where('v.interface_type', '<>', 'fake')->whereNotNull('v.secret_id');
        $models = DB::table('model_profiles')->whereIn('provider_id', (clone $providers)->select('v.provider_id'));

        // 小说按作者隔离；全局配置仅向管理员展示数量，不查询密钥密文或端点。
        return [
            'novels' => DB::table('novels')->where('owner_id', $actor->id)->where('status', '<>', 'archived')->count(),
            'providers' => $providers->count(),
            // 停用状态独立展示，不阻断后续价格预算录入，也不授予真实运行资格。
            'disabled_providers' => (clone $providers)->where('v.enabled', false)->count(),
            'models' => $models->count(),
            'prices' => DB::table('price_versions')->count(),
            'budgets' => DB::table('budget_policies')->count(),
            'prompts' => DB::table('prompt_versions')->count(),
            'styles' => DB::table('style_versions')->count(),
            'routes' => DB::table('route_versions')->count(),
        ];
    }
}
