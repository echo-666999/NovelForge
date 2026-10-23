<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Filament\Resources\NovelResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListNovels extends ListRecords
{
    protected static string $resource = NovelResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('创建小说')->url(NovelResource::getUrl('create'))];
    }
}
