<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Filament\Resources\StudentResource\RelationManagers\OrdersRelationManager;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Order;
use App\Models\User;
use App\Services\CoursePlanAuthoringService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

final class ModeratorCourseWorkspaceContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_course_workflow_uses_one_shared_context_header(): void
    {
        $course = $this->draft();
        $xpath = $this->dom($this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.show', $course))->assertOk());

        self::assertSame(1, $xpath->query('//header[contains(@class, "course-workspace")]')->length);
        $links = $this->hrefs($xpath, '//header[contains(@class, "course-workspace")]//nav//a');
        self::assertSame([
            route('admin.courses.show', $course),
            route('admin.courses.student-preview', $course),
        ], $links);
        self::assertSame(1, $xpath->query('//*[@id="courseStudio"]//*[@id="studioCourseAttachments"]')->length);
        // The retired edit page redirects into this studio:
        // Tests\Feature\AdminDailyPagesRuntimeTest::test_administrator_daily_detail_pages_render_existing_records.
        self::assertFalse(View::exists('admin.courses.edit'));
    }

    public function test_course_authoring_returns_to_the_same_workspace(): void
    {
        $course = $this->draft();
        $module = $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'المقطع', 'video_source_type' => 'bunny']);
        $section = $course->sections()->create([
            'title_ar' => 'المقطع', 'module_id' => $module->id, 'order' => 1,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
        ]);
        $this->actingAs($this->user('moderator'), 'web');
        $studio = route('admin.courses.show', $course);

        $xpath = $this->dom($this->get($studio)->assertOk());
        self::assertSame(1, $xpath->query('//*[@id="courseStudio"]//*[@id="studioInlineAuthoring"]//form[@id="sectionForm"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="courseStudio"]//*[@id="studioInlineAuthoring"]//form[@id="studioModuleForm"]')->length);

        foreach ([
            route('admin.courses.modules.create', $course),
            route('admin.courses.modules.edit', [$course, $module]),
            route('admin.courses.sections.create', $course),
            route('admin.courses.sections.edit', [$course, $section]),
        ] as $legacyEditor) {
            $this->get($legacyEditor)->assertRedirect($studio);
        }
        $this->post(route('admin.courses.modules.store', $course), [
            'authoring_request_id' => (string) Str::uuid(),
            'authoring_version' => (int) $course->fresh()->authoring_version,
            'title_ar' => 'وحدة من نموذج HTML',
        ])->assertRedirect($studio);
        self::assertSame(2, $course->modules()->count());
    }

    public function test_new_course_form_creates_only_the_studio_shell(): void
    {
        $xpath = $this->dom($this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.create'))->assertOk());

        $form = '//form[@id="courseForm"]';
        self::assertSame(route('admin.courses.store'), $xpath->evaluate("string({$form}/@action)"));
        $fields = array_map(
            static fn (\DOMElement $field): string => $field->getAttribute('name'),
            iterator_to_array($xpath->query("{$form}//*[@name]"))
        );
        sort($fields);
        // Only the shell: no image, access plans, basic-information editor or
        // its unsaved-change scripts; everything else is authored in the studio.
        self::assertSame(['_token', 'authoring_request_id', 'certificate_text_template_key', 'name_ar'], $fields);
        self::assertTrue(Str::isUuid($xpath->evaluate("string({$form}//input[@name='authoring_request_id']/@value)")));
        self::assertSame('إنشاء المسودة والمتابعة', trim($xpath->evaluate("string({$form}//button[@type='submit'])")));
        self::assertSame(0, $xpath->query('//input[@type="file"]')->length);
        // The created shell is a hidden, idempotent, attachment-prompt-free draft that
        // redirects into the studio: Tests\Feature\CourseAuthoringApplicationOwnershipTest::
        // test_request_free_creation_and_replay_complete_inside_the_owned_transaction and
        // test_real_http_creation_receipt_failure_rolls_back_and_same_intent_retries_once.
    }

    public function test_course_list_has_one_authoring_entry_point_per_live_course(): void
    {
        $canonical = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'الكورس المنشور', 'price' => 500,
            'is_coming_soon' => false, 'is_catalog_visible' => true, 'published_at' => now(),
            'authoring_version' => 2, 'last_published_authoring_version' => 2,
        ]);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($canonical);
        $plain = $this->draft('مسودة جديدة');

        $xpath = $this->dom($this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.index'))->assertOk());

        $cards = $xpath->query('//*[@id="coursesGrid"]/*[contains(@class, "course-card")]');
        self::assertSame(2, $cards->length, 'The working draft is resumed from its live card, not listed twice.');
        foreach ([[$canonical, $draft], [$plain, $plain]] as [$listed, $workspace]) {
            $card = $xpath->query(sprintf(
                '//*[contains(@class, "course-card")][.//h3[normalize-space()="%s"]]',
                $listed->name_ar
            ))->item(0);
            self::assertInstanceOf(\DOMElement::class, $card);
            $studio = route('admin.courses.show', $workspace);
            self::assertSame($studio, $card->getAttribute('data-url'));
            self::assertSame([$studio], $this->hrefs($xpath, './/a', $card));
        }
        self::assertStringContainsString(
            'نسخة منشورة · تعديلات محفوظة كمسودة',
            $xpath->evaluate('string(//*[contains(@class, "course-card")][.//h3[normalize-space()="الكورس المنشور"]])')
        );
    }

    public function test_legacy_parallel_section_index_is_not_exposed(): void
    {
        $matrix = app(AdminPermissionMatrix::class);
        foreach (['index' => 'GET', 'show' => 'GET'] as $action => $method) {
            self::assertFalse(Route::has('admin.courses.sections.'.$action));
            self::assertFalse($matrix->allows('moderator', 'admin.courses.sections.'.$action, $method));
        }
        foreach (['index', 'show', 'create', 'edit'] as $page) {
            self::assertFalse(View::exists('admin.course-sections.'.$page));
        }

        $course = $this->draft();
        $this->actingAs($this->user('moderator'), 'web')
            ->get('/dashboard/courses/'.$course->id.'/sections')
            ->assertStatus(405);
    }

    public function test_moderator_home_prioritizes_incomplete_work_without_a_second_editor_entry(): void
    {
        [$ready] = $this->readyDraft();
        $ready->forceFill(['is_coming_soon' => false, 'published_at' => now(), 'updated_at' => now()])->saveQuietly();
        $incomplete = $this->draft('مسودة ناقصة');
        $incomplete->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

        $xpath = $this->dom($this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.dashboard'))->assertOk()->assertViewIs('admin.home.moderator'));

        $rows = array_map(
            static fn (\DOMElement $row): string => trim($row->textContent),
            iterator_to_array($xpath->query('//*[contains(@class, "moderator-course-row")]//h3'))
        );
        self::assertSame(['مسودة ناقصة', 'كورس جاهز'], $rows);
        self::assertSame('يحتاج تدخلًا الآن', trim($xpath->evaluate('string(//*[contains(@class, "moderator-workspace__section-heading")]//h2)')));
        foreach ($this->hrefs($xpath, '//*[contains(@class, "moderator-course-row")]//a') as $href) {
            self::assertContains($href, [route('admin.courses.show', $incomplete), route('admin.courses.show', $ready)]);
        }
        // The flat, gradient-free workspace styling is presentation only and has no
        // rendered-behaviour contract to assert here.
    }

    public function test_shared_layout_does_not_render_an_empty_breadcrumb_row(): void
    {
        $course = $this->draft();
        $studio = $this->dom($this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.show', $course))->assertOk());
        self::assertSame(0, $studio->query('//*[contains(concat(" ", normalize-space(@class), " "), " breadcrumbs ")]')->length);

        $this->flushSession();
        $this->actingAs($this->user('admin'), 'web')->get(route('admin.notifications.create'))
            ->assertRedirect(NotificationCampaignResource::getUrl('create'));
    }

    public function test_administrator_home_leads_with_operational_follow_up(): void
    {
        $xpath = $this->dom($this->actingAs($this->user('admin'), 'web')
            ->get(route('admin.dashboard'))->assertOk());

        self::assertSame([
            route('admin.product-operations.index'),
            route('admin.playback-operations.index'),
            route('admin.project-submissions.index'),
            route('admin.orders.index'),
        ], $this->hrefs($xpath, '//nav[contains(@class, "dashboard-priority-nav")]/a'));
    }

    public function test_administrator_student_view_leads_with_what_the_learner_paid_from_the_ledger(): void
    {
        $student = $this->user('client');
        $course = $this->draft('كورس مدفوع');
        $transactionId = DB::table('wallet_transactions')->insertGetId([
            'public_id' => (string) Str::uuid(), 'user_id' => $student->id, 'direction' => 'debit',
            'category' => 'course_purchase', 'bucket' => 'mixed', 'amount' => 500,
            'paid_amount' => 350, 'reward_amount' => 150, 'balance_after' => 0,
            'paid_balance_after' => 0, 'reward_balance_after' => 0,
            'source_type' => Course::class, 'source_id' => $course->id,
            'idempotency_key' => 'student-view:'.$student->id, 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Order::query()->create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 500, 'final_amount' => 500, 'total_coins' => 500,
            // The order's own split disagrees; the page must show the ledger.
            'paid_coins' => 500, 'reward_coins' => 0,
            'wallet_transaction_id' => $transactionId,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);

        $this->actingAs($this->user('admin'), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $student, 'pageClass' => ViewStudent::class,
        ])->assertSee('350 مدفوعة')->assertSee('150 مكافآت')->assertDontSee('ربط الدفتر غير مكتمل');
    }

    public function test_course_commercial_report_presents_per_learner_ledger_evidence(): void
    {
        $course = $this->draft('كورس التقرير');
        $learner = $this->user('client');
        CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $learner->id, 'course_id' => $course->id,
            'enrolled_at' => now()->subHour(), 'is_active' => true,
        ]);

        $response = $this->actingAs($this->user('admin'), 'web')->get(route('admin.courses.show', [
            $course, 'tab' => 'commercial-report', 'period' => '30d',
        ]))->assertOk();
        $report = $response->viewData('commercialReport');
        // Rows are paginated for the page only; the summary and CSV keep every row:
        // Tests\Feature\CourseReportPeriodPageTest::test_pagination_keeps_period_and_export_includes_all_rows_not_just_the_visible_page,
        // and finances load only for the requested tab:
        // Tests\Feature\CourseReportPeriodPageTest::test_lazy_report_link_retains_period_without_loading_finances_before_the_tab_is_requested.
        self::assertArrayNotHasKey('rows', $report);
        self::assertSame(1, $report['student_rows']->total());

        $xpath = $this->dom($response);
        $panel = '//section[@id="commercial-report"]';
        self::assertStringContainsString('كشف الطلاب', $xpath->evaluate("string({$panel})"));
        $studentHeaders = array_map(
            static fn (\DOMElement $cell): string => trim($cell->textContent),
            iterator_to_array($xpath->query("{$panel}//table[.//td[contains(., '{$learner->email}')]]//th"))
        );
        foreach (['المدفوع فعليًا', 'الصافي', 'تكلفة الخدمات'] as $column) {
            self::assertContains($column, $studentHeaders);
        }
        self::assertStringNotContainsString('شاملة التقديرات', $xpath->evaluate("string({$panel})"));
        self::assertSame(0, $xpath->query("{$panel}//th[normalize-space()='OpenRouter']")->length);
        self::assertStringContainsString('الفئات خلال الفترة', $xpath->evaluate("string({$panel})"));
    }

    public function test_publish_readiness_does_not_block_moderators_on_hidden_ai_controls(): void
    {
        [$course] = $this->readyDraft();
        CourseAccessPlan::query()->where('course_id', $course->id)->update([
            'chat_token_budget' => 0, 'ai_budget_usd' => 0, 'request_reserve_usd' => 0,
            'project_feedback_token_budget' => 0, 'project_feedback_budget_usd' => 0,
            'project_followup_token_budget' => 0, 'project_followup_budget_usd' => 0,
        ]);

        $audit = app(CoursePublishingService::class)->audit(Course::query()->findOrFail($course->id));

        self::assertTrue($audit['ready'], implode("\n", $audit['issues']));
        foreach (array_merge($audit['issues'], $audit['warnings']) as $message) {
            self::assertStringNotContainsString('ميزانية', $message);
        }
    }

    public function test_readiness_rail_lists_each_publishing_issue_once(): void
    {
        $course = $this->draft();
        $audit = app(CoursePublishingService::class)->audit($course->fresh());
        self::assertFalse($audit['ready']);

        $xpath = $this->dom($this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.show', $course))->assertOk());

        $listed = array_map(
            static fn (\DOMElement $item): string => trim($item->textContent),
            iterator_to_array($xpath->query('//*[@data-studio-summary="readiness"]//li'))
        );
        self::assertSame(array_slice($audit['issues'], 0, 4), $listed);
        self::assertSame(1, $xpath->query('//*[@data-studio-summary="readiness"]//ul')->length);
    }

    public function test_course_publish_is_one_explicit_save_or_publish_operation(): void
    {
        $course = $this->draft();
        $this->actingAs($this->user('moderator'), 'web');
        $xpath = $this->dom($this->get(route('admin.courses.show', $course))->assertOk());

        $intents = array_map(
            static fn (\DOMElement $button): string => $button->getAttribute('value'),
            iterator_to_array($xpath->query('//*[@id="studioCoursePanel"]//button[@type="submit" and @name="publishing_intent"]'))
        );
        self::assertSame(['save', 'publish'], $intents);
        self::assertSame(0, $xpath->query('//*[@name="is_coming_soon"]')->length);

        $this->patchJson(route('admin.courses.update', $course), [
            'authoring_version' => (int) $course->authoring_version,
            'publishing_intent' => 'unpublish',
        ])->assertUnprocessable()->assertJsonValidationErrors('publishing_intent');
        self::assertTrue((bool) $course->fresh()->is_coming_soon);
        // A save stays a draft (Tests\Feature\AdminCourseUpdateJsonContractTest::test_studio_form_data_save_returns_the_canonical_editor_state),
        // a publish publishes (Tests\Feature\CourseStudioPublishReadinessRecoveryTest::test_correcting_the_rejected_publish_uses_the_acknowledged_version_without_a_false_conflict),
        // and a crafted is_coming_soon is ignored (Tests\Feature\AdminCourseUpdateJsonContractTest::test_partial_patch_preserves_omitted_relations_publication_state_and_plan_price).
    }

    public function test_next_revision_editor_preserves_live_catalogue_and_hero_choices(): void
    {
        $canonical = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'الكورس الرئيسي', 'price' => 500,
            'is_coming_soon' => false, 'is_catalog_visible' => true, 'is_main_course' => true,
            'published_at' => now(), 'authoring_version' => 3, 'last_published_authoring_version' => 3,
        ]);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($canonical);
        // The working copy's own flags are not the learner-facing choices.
        $draft->forceFill(['is_catalog_visible' => false, 'is_main_course' => false])->saveQuietly();

        $response = $this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.show', $canonical))->assertOk()
            ->assertViewHas('course', fn (Course $course): bool => $course->is($draft))
            ->assertViewHas('catalogVisibilityDefault', true)
            ->assertViewHas('mainCourseDefault', true);
        $xpath = $this->dom($response);

        self::assertSame(1, $xpath->query('//input[@type="checkbox" and @name="is_catalog_visible" and @checked]')->length);
        self::assertSame(1, $xpath->query('//input[@type="checkbox" and @name="is_main_course" and @checked]')->length);
    }

    public function test_draft_preview_is_a_read_that_keeps_the_device_link_on_the_published_course(): void
    {
        // Draft serialization and the canonical rokn://course link are covered by
        // Tests\Feature\AdminCoursePreviewOwnershipTest::test_http_preview_serializes_the_selected_working_draft_and_keeps_device_link_canonical.
        self::assertNotContains(
            'course.draft',
            Route::getRoutes()->getByName('admin.courses.student-preview')->gatherMiddleware()
        );
        $published = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'منشور بلا مسودة', 'price' => 400,
            'is_coming_soon' => false, 'is_catalog_visible' => true, 'published_at' => now(),
            'authoring_version' => 2, 'last_published_authoring_version' => 2,
            'certificate_text_template_key' => 'completion',
        ]);
        app(CoursePlanAuthoringService::class)->createDefaults($published);
        $module = $published->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $published->id, 'title_ar' => 'المقطع', 'video_source_type' => 'bunny']);
        $published->sections()->create([
            'title_ar' => 'المقطع', 'module_id' => $module->id, 'order' => 1,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
        ]);

        $this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.student-preview', $published))
            ->assertOk()
            ->assertViewHas('publishedDeviceCourseId', $published->id)
            ->assertSee('rokn://course/'.$published->id, false);

        self::assertSame(1, Course::query()->count());
        self::assertSame(0, CourseAuthoringRevision::query()->count());
    }

    public function test_publish_readiness_points_to_the_owning_editor_area(): void
    {
        $course = $this->draft();
        $details = collect(app(CoursePublishingService::class)->audit($course->fresh())['issue_details'])
            ->pluck('area', 'message');
        self::assertSame('image', $details['أضف غلافًا للكورس.']);
        self::assertSame('details', $details['أضف وصفًا مختصرًا يوضح نتيجة الكورس.']);

        $xpath = $this->dom($this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.show', $course))->assertOk());

        $areaIssues = fn (string $id): array => array_map(
            static fn (\DOMElement $item): string => trim($item->textContent),
            iterator_to_array($xpath->query("//*[@id='{$id}']/*[@role='alert']//li"))
        );
        self::assertContains('أضف غلافًا للكورس.', $areaIssues('course-editor-image'));
        self::assertNotContains('أضف وصفًا مختصرًا يوضح نتيجة الكورس.', $areaIssues('course-editor-image'));
        self::assertContains('أضف وصفًا مختصرًا يوضح نتيجة الكورس.', $areaIssues('course-editor-details'));
        self::assertNotContains('أضف غلافًا للكورس.', $areaIssues('course-editor-details'));
    }

    public function test_course_studio_does_not_load_or_render_student_operations_for_moderators(): void
    {
        $course = $this->draft();
        CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $this->user('client')->id, 'course_id' => $course->id,
            'enrolled_at' => now(), 'is_active' => true,
        ]);
        $students = '//*[@data-studio-summary="course"]//span[i[contains(@class, "fa-users")]]';

        $moderator = $this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.show', $course))->assertOk()
            ->assertViewHas('activeStudentsCount', null)
            ->assertViewHas('learningHealthSummary', null)
            ->assertViewHas('ratingSummary', null)
            ->assertViewHas('commercialReport', null);
        self::assertSame(0, $this->dom($moderator)->query($students)->length);

        $this->flushSession();
        $administrator = $this->actingAs($this->user('admin'), 'web')
            ->get(route('admin.courses.show', $course))->assertOk()
            ->assertViewHas('activeStudentsCount', 1);
        self::assertSame('1 طالب', trim($this->dom($administrator)->evaluate("string({$students})")));
    }

    public function test_course_studio_panels_have_one_owner_for_each_dom_id(): void
    {
        $course = $this->draft();
        $xpath = $this->dom($this->actingAs($this->user('admin'), 'web')
            ->get(route('admin.courses.show', [$course, 'tab' => 'commercial-report']))->assertOk());

        self::assertSame(1, $xpath->query('//*[@id="statistics"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="commercial-report"]')->length);
        self::assertSame('section', $xpath->query('//*[@id="statistics"]')->item(0)?->nodeName);
        self::assertSame('section', $xpath->query('//*[@id="commercial-report"]')->item(0)?->nodeName);
        self::assertSame(0, $xpath->query('//link[contains(@href, "courses-show.css")]')->length);
    }

    private function user(string $role): User
    {
        return User::query()->forceCreate([
            'name_ar' => match ($role) {
                'admin' => 'مالك ركن',
                'moderator' => 'محرر المحتوى',
                'teacher' => 'المحاضر',
                default => 'طالب ركن',
            },
            'email' => $role.'-'.Str::uuid().'@example.test',
            'role' => $role,
            'active' => true,
        ]);
    }

    private function draft(string $name = 'مسودة الاستوديو'): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => $name, 'price' => 500,
            'is_coming_soon' => true, 'is_catalog_visible' => false,
            'authoring_version' => 1, 'last_published_authoring_version' => 0,
            'certificate_text_template_key' => 'completion',
        ]);
    }

    /** @return array{Course, CourseModule} */
    private function readyDraft(): array
    {
        Storage::fake('public');
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس جاهز', 'description_ar' => 'وصف الكورس الجاهز',
            'price' => 800, 'is_coming_soon' => true, 'is_catalog_visible' => false,
            'is_main_course' => false, 'attachment_prompt_enabled' => false,
            'authoring_version' => 1, 'last_published_authoring_version' => 0,
            'certificate_text_template_key' => 'completion',
        ]);
        $course->teachers()->attach($this->user('teacher')->id);
        $course->classifications()->attach(
            Classification::query()->create(['name_ar' => 'التصنيف', 'name_en' => 'Category'])->id
        );
        Storage::disk('public')->put('courses/ready-cover.jpg', 'cover bytes');
        $course->allPhotos()->create(['path' => 'courses/ready-cover.jpg', 'type' => 'featured']);
        app(CoursePlanAuthoringService::class)->createDefaults($course);
        $module = $course->modules()->create(['title_ar' => 'الوحدة الأولى', 'order' => 1]);
        $guid = '33333333-3333-4333-8333-333333333333';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'المقطع', 'video_source_type' => 'bunny', 'bunny_video_id' => $guid,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id, 'provider' => 'bunny', 'provider_media_id' => $guid,
            'status' => 'ready', 'duration_seconds' => 60, 'integrity_status' => 'healthy', 'last_reconciled_at' => now(),
        ]);
        $course->sections()->create([
            'title_ar' => 'المقطع', 'module_id' => $module->id, 'order' => 1,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
        ]);
        $audit = app(CoursePublishingService::class)->audit($course->fresh());
        self::assertTrue($audit['ready'], implode("\n", $audit['issues']));

        return [$course, $module];
    }

    private function dom(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());

        return new DOMXPath($document);
    }

    /** @return list<string> */
    private function hrefs(DOMXPath $xpath, string $query, ?\DOMNode $context = null): array
    {
        return array_values(array_map(
            static fn (\DOMElement $link): string => $link->getAttribute('href'),
            iterator_to_array($xpath->query($query, $context))
        ));
    }
}
