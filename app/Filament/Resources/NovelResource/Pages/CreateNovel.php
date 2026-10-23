<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Filament\Resources\NovelResource;
use App\Filament\Support\IntentUi;
use App\Models\Novel;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateNovel extends CreateRecord
{
    use HasWizard;

    protected static string $resource = NovelResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $commandKey;

    public function mount(): void
    {
        $this->commandKey = (string) Str::uuid();
        parent::mount();
    }

    protected function getSteps(): array
    {
        return [
            Step::make('输入')->schema(NovelResource::inputFields()),
            Step::make('提炼约束')->schema([Text::make(fn (Get $get) => '背景要求：'.$get('background')), Text::make(fn (Get $get) => '剧情要求：'.$get('premise_raw')), Text::make(fn (Get $get) => '禁用内容：'.$get('forbidden_content')), Text::make(fn (Get $get) => '结局待决定：'.$get('ending_preference')), Text::make('背景、剧情、禁用内容和结局原句将分别建立待确认要求。本阶段不调用模型；保存后可逐条拆分、修正与决定。')]),
            Step::make('确认歧义')->schema([Text::make('背景与结局列为关键待决定项。未决定或未覆盖的要求不会被视作已满足；保存草稿不会批准规划。'), Checkbox::make('acknowledged')->label('已了解待决定项会保留，稍后逐条确认')->accepted()->required()->dehydrated(false)]),
            Step::make('保存')->schema([Text::make('保存原始输入和第一版要求；后续修改保留旧版本。')]),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $result = IntentUi::run('create', null, $data, $this->commandKey);

        return Novel::findOrFail($result['novel']['id']);
    }

    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('intent', ['record' => $this->record]);
    }
}
