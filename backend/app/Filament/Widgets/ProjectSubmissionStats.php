<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProjectSubmissionResource;
use App\Services\AdminProjectSubmissionReviewReadService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class ProjectSubmissionStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected int|array|null $columns = ['default' => 3];

    public static function canView(): bool
    {
        return ProjectSubmissionResource::canViewAny();
    }

    protected function getStats(): array
    {
        $counts = app(AdminProjectSubmissionReviewReadService::class)->statusCounts();
        $stats = [];
        foreach (AdminProjectSubmissionReviewReadService::STATUSES as $state => $label) {
            $stats[] = Stat::make($label, number_format((int) ($counts[$state] ?? 0)))
                ->url(ProjectSubmissionResource::listUrl(['status' => $state]));
        }

        return $stats;
    }
}
