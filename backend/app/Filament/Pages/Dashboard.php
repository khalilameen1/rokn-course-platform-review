<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\ContactResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PortfolioReviewResource;
use App\Filament\Resources\ProjectSubmissionResource;
use App\Filament\Resources\StudentResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;

final class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?string $title = 'لوحة رُكن';

    protected function getHeaderActions(): array
    {
        $destinations = [
            ['content', 'مساحة المحتوى', ContentWorkspace::class],
            ['projects', 'المشاريع', ProjectSubmissionResource::class],
            ['payments', 'المدفوعات', OrderResource::class],
            ['portfolio', 'البورتفوليو', PortfolioReviewResource::class],
            ['students', 'الطلبة', StudentResource::class],
            ['contacts', 'الرسائل', ContactResource::class],
            ['operations', 'تشغيل المنتج', ProductOperations::class],
            ['settings', 'إعدادات التطبيق', AppSettings::class],
        ];

        $actions = collect($destinations)->filter(fn (array $item): bool => $item[2]::canAccess())->take(4)
            ->map(fn (array $item): Action => Action::make($item[0])->label($item[1])->url($item[2]::getUrl()))->all();

        return $actions ?: [Action::make('profile')->label('حسابي')->url(Filament::getProfileUrl())];
    }
}
