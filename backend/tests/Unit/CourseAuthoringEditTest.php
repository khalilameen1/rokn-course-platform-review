<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\CourseAuthoringEdit;
use PHPUnit\Framework\TestCase;

final class CourseAuthoringEditTest extends TestCase
{
    public function test_absence_does_not_become_an_explicit_clear_or_unpublish(): void
    {
        $edit = CourseAuthoringEdit::fromValidated([]);
        self::assertSame([], $edit->attributes);
        self::assertNull($edit->classificationIds);
        self::assertNull($edit->teacherIds);
        self::assertNull($edit->planOffers);
        self::assertNull($edit->catalogVisible);
        self::assertNull($edit->mainCourse);
        self::assertFalse($edit->publishingRequested);
        self::assertFalse($edit->grantChatAttachments);
        self::assertFalse($edit->grantProjectAttachments);
    }

    public function test_explicit_empty_selections_null_and_false_remain_submitted_intent(): void
    {
        $edit = CourseAuthoringEdit::fromValidated([
            'classification_ids_present' => '1', 'teacher_ids' => null,
            'access_plans' => null, 'is_catalog_visible' => null,
            'is_main_course' => '0', 'description_en' => null,
        ]);
        self::assertSame([], $edit->classificationIds);
        self::assertSame([], $edit->teacherIds);
        self::assertSame([], $edit->planOffers);
        self::assertFalse($edit->catalogVisible);
        self::assertFalse($edit->mainCourse);
        self::assertSame(['description_en' => null], $edit->attributes);
    }

    public function test_write_controls_are_not_general_course_attributes(): void
    {
        $edit = CourseAuthoringEdit::fromValidated([
            'name_ar' => 'اسم الكورس', 'authoring_version' => '4',
            'authoring_request_id' => '11111111-1111-4111-8111-111111111111',
            'is_coming_soon' => false, 'is_catalog_visible' => true, 'is_main_course' => true,
            'price' => 10, 'publishing_intent' => 'publish',
            'classification_ids' => ['2'], 'teacher_ids' => ['3'],
            'access_plans' => [], 'grant_chat_attachments_to_current_enrollments' => '1',
            'grant_project_followup_attachments_to_current_enrollments' => '0',
        ]);
        self::assertSame(['name_ar' => 'اسم الكورس'], $edit->attributes);
        self::assertSame(4, $edit->expectedVersion);
        self::assertSame([2], $edit->classificationIds);
        self::assertSame([3], $edit->teacherIds);
        self::assertTrue($edit->publishingRequested);
        self::assertTrue($edit->grantChatAttachments);
        self::assertFalse($edit->grantProjectAttachments);
    }

    public function test_later_input_mutation_cannot_change_the_validated_snapshot(): void
    {
        $fields = ['name_ar' => 'الأصلي', 'access_plans' => ['basic' => ['price_coins' => 400]]];
        $edit = CourseAuthoringEdit::fromValidated($fields);
        $fields['name_ar'] = 'تغيير لاحق';
        $fields['access_plans']['basic']['price_coins'] = 0;
        self::assertSame('الأصلي', $edit->attributes['name_ar']);
        self::assertSame(400, $edit->planOffers['basic']['price_coins']);
        self::assertFalse(CourseAuthoringEdit::fromValidated(['publishing_intent' => 'save'])->publishingRequested);
    }
}
