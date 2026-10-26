@php
    $navigation = app(\App\Services\NovelNavigationService::class)->context(auth()->user(), $novelId, class_basename($this));
@endphp
@include('filament.book-navigation', ['navigation' => $navigation])
