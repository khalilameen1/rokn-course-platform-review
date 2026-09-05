<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class ModeratorCourseCommercialPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const AI_POLICY_FIELDS = [
        'chat_enabled',
        'chat_message_limit',
        'chat_token_budget',
        'chat_attachments_enabled',
        'chat_attachment_max_files',
        'ai_budget_usd',
        'request_reserve_usd',
        'max_output_tokens',
        'model_override',
        'project_feedback_level',
        'project_feedback_token_budget',
        'project_feedback_budget_usd',
        'project_feedback_reserve_usd',
        'project_followup_message_limit',
        'project_followup_token_budget',
        'project_followup_budget_usd',
        'project_followup_reserve_usd',
        'project_followup_attachments_enabled',
        'project_followup_attachment_max_files',
        'project_output_enabled',
    ];

    public function test_course_editor_never_exposes_global_ai_policy_controls(): void
    {
        [$course, $moderator, $administrator] = $this->records();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->get(route('admin.courses.show', $course))
            ->assertOk()
            ->assertSee('name="is_main_course"', false)
            ->assertDontSee('تكلفة OpenRouter')
            ->assertDontSee('عمليات الشراء')
            ->assertDontSee('ai_budget_usd')
            ->assertDontSee('project_feedback_budget_usd')
            ->assertDontSee('project_followup_budget_usd')
            ->assertDontSee('حد تكلفة الخطة بالدولار')
            ->assertDontSee('احتياطي AI المقترح')
            ->assertDontSee('chat_message_limit')
            ->assertDontSee('project_feedback_level');

        // A dashboard session is deliberately pinned to one identity. Start a
        // fresh browser session before checking the administrator view.
        $this->flushSession();
        $this->actingAs($administrator)
            ->get(route('admin.courses.show', $course))
            ->assertOk()
            ->assertDontSee('ai_budget_usd')
            ->assertDontSee('project_feedback_budget_usd')
            ->assertDontSee('project_followup_budget_usd')
            ->assertDontSee('chat_message_limit')
            ->assertDontSee('project_feedback_level');
    }

    public function test_moderator_can_choose_the_home_hero_without_financial_privileges(): void
    {
        $moderator = $this->dashboardUser('moderator', 'hero-editor@example.test');
        $oldHero = $this->publishedCourse('الكورس الرئيسي', true, 3);
        $newHero = $this->publishedCourse('الكورس الجديد', false, 5);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($newHero);
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->patch(route('admin.courses.update', $draft), [
                'authoring_version' => (int) $draft->authoring_version,
                'is_main_course' => '1',
            ])
            ->assertRedirect(route('admin.courses.show', $draft));

        // The public hero is immutable until this draft passes publication;
        // the selected intent travels with the draft and is copied atomically
        // during the existing publish swap.
        self::assertTrue((bool) $oldHero->fresh()->is_main_course);
        self::assertTrue((bool) $draft->fresh()->is_main_course);

        $this->patch(route('admin.courses.update', $draft), [
            'authoring_version' => (int) $draft->fresh()->authoring_version,
            'description_ar' => 'تعديل مستقل بعد اختيار الكورس الرئيسي',
        ])->assertRedirect(route('admin.courses.show', $draft));

        self::assertTrue((bool) $draft->fresh()->is_main_course);
        $studio = $this->get(route('admin.courses.show', $draft))
            ->assertOk()
            ->assertSee('name="is_main_course"', false);
        self::assertTrue((bool) $studio->original->getData()['mainCourseDefault']);
    }

    public function test_an_explicit_hero_uncheck_survives_later_partial_draft_saves(): void
    {
        $moderator = $this->dashboardUser('moderator', 'hero-uncheck@example.test');
        $currentHero = $this->publishedCourse('الرئيسي الحالي', true, 8);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($currentHero);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator);

        $this->patch(route('admin.courses.update', $draft), [
            'authoring_version' => (int) $draft->authoring_version,
            'is_main_course' => '0',
        ])->assertRedirect(route('admin.courses.show', $draft));
        self::assertFalse((bool) $draft->fresh()->is_main_course);

        $this->patch(route('admin.courses.update', $draft), [
            'authoring_version' => (int) $draft->fresh()->authoring_version,
            'description_ar' => 'حفظ جزئي بعد إلغاء الاختيار',
        ])->assertRedirect(route('admin.courses.show', $draft));

        self::assertFalse((bool) $draft->fresh()->is_main_course);
        $studio = $this->get(route('admin.courses.show', $draft))->assertOk();
        self::assertFalse((bool) $studio->original->getData()['mainCourseDefault']);
    }

    public function test_crafted_course_request_cannot_replace_global_ai_policy(): void
    {
        [$course, $moderator, $administrator] = $this->records();
        $persisted = $course->accessPlans()->get()->keyBy('code');
        $submittedPlans = [];
        foreach (CourseAccessPlan::CODES as $code) {
            $plan = $persisted->get($code);
            $submittedPlans[$code] = [
                'name_ar' => 'فئة '.$code,
                'name_en' => $plan->name_en,
                'price_coins' => $plan->price_coins,
                'minimum_paid_coins' => $plan->minimum_paid_coins,
                'is_active' => $plan->is_active,
                'certificate_enabled' => $plan->certificate_enabled,
            ];
            foreach (self::AI_POLICY_FIELDS as $field) {
                $submittedPlans[$code][$field] = in_array($field, ['model_override', 'project_feedback_level'], true)
                    ? 'forged-value'
                    : '9999';
            }
        }

        foreach ([$moderator, $administrator] as $actor) {
            $request = CourseRequest::create('/dashboard/courses/'.$course->id, 'PATCH', [
                'authoring_version' => 3,
                'access_plans' => $submittedPlans,
            ]);
            $request->setContainer($this->app);
            $request->setUserResolver(fn (): User => $actor);
            $request->setRouteResolver(fn () => new class($course) {
                public function __construct(private readonly Course $course) {}
                public function parameter(string $key, mixed $default = null): mixed
                {
                    return $key === 'course' ? $this->course : $default;
                }
            });

            $prepare = new \ReflectionMethod(CourseRequest::class, 'prepareForValidation');
            $prepare->invoke($request);

            foreach (CourseAccessPlan::CODES as $code) {
                foreach (self::AI_POLICY_FIELDS as $field) {
                    self::assertFalse(
                        data_get($request->input('access_plans'), "{$code}.{$field}", false),
                        "{$actor->role} retained protected {$code}.{$field}"
                    );
                }
            }
            self::assertFalse(
                Validator::make($request->all(), $request->rules())->fails(),
                "{$actor->role} could not save editable plan fields"
            );
        }
    }

    /** @return array{Course, User, User} */
    private function records(): array
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس تجاري',
            'price' => 1200,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'authoring_version' => 3,
        ])->save();
        app(CourseAccessPlanService::class)->createDefaults($course);
        $guided = $course->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
        $guided->forceFill([
            'ai_budget_usd' => '7.123456',
            'request_reserve_usd' => '0.123456',
            'project_feedback_budget_usd' => '6.123456',
            'project_feedback_reserve_usd' => '0.223456',
            'project_followup_budget_usd' => '5.123456',
            'project_followup_reserve_usd' => '0.323456',
        ])->save();

        return [
            $course,
            $this->dashboardUser('moderator', 'course-editor@example.test'),
            $this->dashboardUser('admin', 'course-owner@example.test'),
        ];
    }

    private function dashboardUser(string $role, string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => $role === 'admin' ? 'مالك ركن' : 'محرر المحتوى',
            'email' => $email,
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }

    private function publishedCourse(string $name, bool $isMain, int $version): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'is_main_course' => $isMain,
            'authoring_version' => $version,
        ])->save();

        return $course;
    }
}
