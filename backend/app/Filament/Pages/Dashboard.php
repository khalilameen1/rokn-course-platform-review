<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use Filament\Actions\Action;

final class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?string $title = 'لوحة رُكن';

    protected function getHeaderActions(): array
    {
        return [Action::make('classic')->label('استوديو الكورسات')->url(route('admin.courses.index'))
            ->visible(fn (): bool => app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.courses.index', 'GET'))];
    }
}
