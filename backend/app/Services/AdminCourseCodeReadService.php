<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseCode;
use App\Models\Lesson;
use App\Support\BusinessClock;
use App\Support\CsvCell;
use App\Support\UnicodeText;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Shared filtered selection and export rows; never authors or redeems a code. */
final class AdminCourseCodeReadService
{
    public const PDF_LIMIT = 500;

    public function __construct(private readonly AdminContentInventoryReadService $inventory)
    {
    }

    /** @return Collection<int, \App\Models\Course> Original courses, including not-yet-published ones. */
    public function courseOptions(): Collection
    {
        return $this->inventory->courses()->orderBy('name_ar')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $filters Validated scalar filters and local Y-m-d dates. */
    public function query(array $filters): Builder
    {
        $query = CourseCode::query()->with(['course', 'lesson']);
        if (filled($filters['code'] ?? null)) {
            $query->where('code', 'like', '%'.UnicodeText::identifier($filters['code']).'%');
        }
        if (filled($filters['name'] ?? null)) {
            $query->where('name', 'like', '%'.UnicodeText::clean($filters['name'], false).'%');
        }
        foreach (['type', 'course_id', 'lesson_id'] as $field) {
            if (filled($filters[$field] ?? null)) $query->where($field, $filters[$field]);
        }
        foreach (['start_date', 'expiry_date'] as $field) {
            if (!filled($filters[$field] ?? null)) continue;
            [$from, $to] = BusinessClock::localDayRangeUtc($filters[$field]);
            $query->where($field, '>=', $from)->where($field, '<', $to);
        }

        return match ((string) ($filters['status'] ?? '')) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'expired' => $query->where('expiry_date', '<', now()),
            'not_yet_active' => $query->where('start_date', '>', now()),
            default => $query,
        };
    }

    /**
     * Includes the header and streams bounded database chunks, not the entire campaign.
     * @param array<string, mixed> $filters
     * @return Generator<int, array<int, mixed>>
     */
    public function csvRows(array $filters): Generator
    {
        yield [
            'الكود', 'الاسم', 'النوع', 'الدورة/الدرس', 'تاريخ البداية', 'تاريخ الانتهاء',
            'الاستخدامات', 'الحد الأقصى', 'منحة مؤسسية', 'نطاقات البريد', 'الحالة', 'تاريخ الإنشاء',
        ];

        foreach ($this->query($filters)->lazyByIdDesc(500) as $code) {
            yield CsvCell::row([
                $code->code,
                $code->name,
                match ($code->type) {
                    'course' => 'دورة', 'lesson' => 'درس', 'multiple_lessons' => 'دروس متعددة',
                    default => $code->type,
                },
                $code->target_content_name,
                $code->start_date ? BusinessClock::format($code->start_date, 'Y-m-d') : '',
                $code->expiry_date ? BusinessClock::format($code->expiry_date, 'Y-m-d') : '',
                $code->used_count,
                $code->max_uses,
                $code->isInstitutionalGrant() ? 'نعم' : 'لا',
                implode(', ', $code->allowed_email_domains ?? []),
                $code->is_active ? 'مفعل' : 'معطل',
                BusinessClock::format($code->created_at, 'Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * One extra row lets the HTTP adapter reject oversized exports without loading them all.
     * @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    public function pdfRows(array $filters): Collection
    {
        return $this->query($filters)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::PDF_LIMIT + 1)->get()
            ->map(static fn (CourseCode $code): object => (object) [
                'name' => $code->name ?? 'غير محدد',
                'target_content_name' => $code->type === 'multiple_lessons'
                    ? 'دروس متعددة' : ($code->target_content_name ?? 'غير محدد'),
                'code' => $code->code ?? 'غير محدد',
                'type' => $code->type ?? 'course',
                'max_uses' => $code->max_uses ?? 0,
                'is_grant' => $code->isInstitutionalGrant(),
                'allowed_email_domains' => $code->allowed_email_domains ?? [],
            ]);
    }

    /** @return Collection<int, array{id: int, title: ?string}> */
    public function lessonOptions(int $courseId): Collection
    {
        return Lesson::query()->where('list_id', $courseId)->orderBy('priority')->orderBy('id')
            ->get(['id', 'title', 'title_ar', 'title_en'])
            ->map(static fn (Lesson $lesson): array => ['id' => (int) $lesson->id, 'title' => $lesson->title]);
    }
}
