<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Database\Eloquent\Collection;

final class CourseModuleOrderingService
{
    /** The caller owns the course lock and surrounding transaction. */
    public function place(Course $course, CourseModule $module, int $requestedOrder): void
    {
        $modules = $this->modules($course)
            ->reject(fn (CourseModule $candidate): bool => $candidate->is($module))
            ->values();
        $index = min($modules->count(), max(0, $requestedOrder - 1));
        $modules->splice($index, 0, [$module]);

        $this->writeSequentialOrders($modules);
    }

    public function normalize(Course $course): void
    {
        $this->writeSequentialOrders($this->modules($course));
    }

    /** @return Collection<int, CourseModule> */
    private function modules(Course $course): Collection
    {
        return CourseModule::query()
            ->where('course_id', $course->id)
            ->orderBy('order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param iterable<int, CourseModule> $modules */
    private function writeSequentialOrders(iterable $modules): void
    {
        foreach ($modules as $index => $module) {
            $order = $index + 1;
            if ((int) $module->order !== $order) {
                $module->updateQuietly(['order' => $order]);
            }
        }
    }
}
