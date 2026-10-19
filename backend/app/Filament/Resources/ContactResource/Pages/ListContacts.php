<?php

namespace App\Filament\Resources\ContactResource\Pages;

use App\Filament\Resources\ContactResource;
use App\Filament\Resources\FeedbackResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('support')->label('رسائل التطبيق')->url(FeedbackResource::getUrl())->visible(FeedbackResource::canViewAny())];
    }
}
