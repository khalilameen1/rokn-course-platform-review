<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;

final class AdminCourseEditorStatePresenter
{
    private const RESULTS = [
        'updated' => [true, 200, 'تم تحديث الكورس بنجاح'],
        'live_incomplete' => [false, 422, 'لم نحفظ التعديل لأن الكورس المنشور يجب أن يظل مكتملًا'],
        'save_failed' => [false, 500, 'تعذر حفظ تعديلات الكورس الآن'],
        'staged_publish_failed' => [true, 200, "تم حفظ المسودة\nلم يكتمل النشر\nالنسخة الحالية ما زالت متاحة للطلاب"],
        'publish_failed' => [true, 200, "تم حفظ تعديلات الكورس\nلم يكتمل النشر\nأعد تحميل الصفحة وراجع آخر حالة"],
        'not_ready' => [true, 200, "تم حفظ التعديلات\nالكورس ما زال مسودة حتى تكتمل عناصر النشر"],
        'catalog_publish_failed' => [true, 200, "تم حفظ تعديلات الكورس\nلم يكتمل إظهار بطاقة الكورس\nأعد تحميل الصفحة ثم حاول مرة أخرى"],
        'catalog_not_ready' => [true, 200, "تم حفظ التعديلات\nبطاقة قريبًا ما زالت مخفية حتى تكتمل بياناتها"],
        'hero_failed' => [true, 200, "تم حفظ تعديلات الكورس\nلم يتغير اختيار الواجهة الرئيسية\nأعد تحميل الصفحة ثم حاول مرة أخرى"],
    ];

    /**
     * @param array{status:string,course:Course,issues?:array<int,string>} $result
     * @return array{http_status:int,payload:array<string,mixed>}
     */
    public function result(array $result): array
    {
        $status = (string) $result['status'];
        [$saved, $httpStatus, $message] = self::RESULTS[$status]
            ?? [false, 500, 'تعذر تحديد نتيجة حفظ الكورس'];
        $course = $result['course'];
        $version = (int) $course->authoring_version;
        $publishingStatus = $this->publishingStatus($course);
        $payload = [
            'success' => $saved,
            'saved' => $saved,
            'published' => in_array($publishingStatus, ['published', 'unlisted'], true),
            'status' => $status,
            'message' => $message,
            'authoring_version' => $version,
            'course' => [
                'id' => (int) $course->id,
                'title' => trim((string) ($course->name_ar ?: $course->name_en)),
                'authoring_version' => $version,
                'publishing_status' => $publishingStatus,
                'studio_url' => route('admin.courses.show', $course),
                'image_url' => $course->image,
            ],
        ];
        if ($saved && $status !== 'updated') {
            $payload['warning'] = $message;
        }
        $issues = array_values(array_filter(
            (array) ($result['issues'] ?? []),
            static fn ($issue): bool => is_string($issue) && trim($issue) !== ''
        ));
        if ($issues !== []) {
            $payload['issues'] = $issues;
        }

        return ['http_status' => $httpStatus, 'payload' => $payload];
    }

    private function publishingStatus(Course $course): string
    {
        if ((bool) $course->is_coming_soon) {
            return (bool) $course->is_catalog_visible ? 'coming_soon' : 'draft';
        }

        return (bool) $course->is_catalog_visible ? 'published' : 'unlisted';
    }
}
