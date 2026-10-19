<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class AdminOperatingCostReadService
{
    /** @return Builder<OperatingCostPool> */
    public function invoices(): Builder
    {
        return OperatingCostPool::query()->with(['course' => fn ($query) => $query->getQuery()->withoutGlobalScope(SoftDeletingScope::class)]);
    }

    /** @return Builder<Course> */
    public function courses(): Builder
    {
        return app(AdminContentInventoryReadService::class)->courses()->withTrashed();
    }

    public function courseOptions(string $search): array
    {
        return $this->courses()->where('name_ar', 'like', '%'.$search.'%')->orderBy('name_ar')->limit(50)
            ->get(['id', 'name_ar', 'deleted_at'])->mapWithKeys(fn (Course $course): array => [
                $course->id => $course->name_ar.($course->trashed() ? ' — مؤرشف' : ''),
            ])->all();
    }

    public function courseLabel(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $course = Course::withTrashed()->find($id);

        return $course ? $course->name_ar.($course->trashed() ? ' — مؤرشف' : '') : 'كورس سابق';
    }

    public function settings(): Setting
    {
        return Setting::query()->first() ?? new Setting;
    }

    /** @param Builder<OperatingCostPool> $query */
    public function summary(Builder $query): array
    {
        $rows = (clone $query)->reorder()->select(['service_key', 'is_final'])
            ->selectRaw("SUM(CASE WHEN currency = 'EGP' THEN amount WHEN currency = 'USD' AND COALESCE(fx_rate_to_egp, 0) > 0 THEN amount * fx_rate_to_egp ELSE 0 END) AS amount_egp")
            ->selectRaw("SUM(CASE WHEN currency = 'USD' AND COALESCE(fx_rate_to_egp, 0) <= 0 THEN 1 ELSE 0 END) AS missing_fx")
            ->groupBy('service_key', 'is_final')->get();

        return [
            'actual_egp' => round((float) $rows->filter(fn ($row): bool => (bool) $row->getAttribute('is_final'))->sum('amount_egp'), 2),
            'estimated_egp' => round((float) $rows->reject(fn ($row): bool => (bool) $row->getAttribute('is_final'))->sum('amount_egp'), 2),
            'missing_fx' => (int) $rows->sum('missing_fx'),
        ];
    }
}
