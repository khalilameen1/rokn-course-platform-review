<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\ProjectSubmissionResource;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\FeedbackReport;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentReconciliationFinding;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

/**
 * Formerly a source reader of the dashboard Blade files. The same contracts
 * are checked on the pages the dashboard serves.
 */
final class AdminDashboardViewTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    public function test_dashboard_pages_render_without_inline_styles_inside_admin_markup(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = $this->draftCourse();
        $student = $this->dashboardUser('client');
        $order = $this->packageOrder($student);
        $code = CourseCode::query()->forceCreate([
            'tenant_id' => 1, 'code' => 'VIEW-CODE', 'name' => 'دفعة', 'type' => 'course',
            'course_id' => $course->id, 'max_uses' => 5, 'is_active' => true,
        ]);
        $feedback = FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new',
            'priority' => 'normal', 'message' => 'بلاغ', 'version' => 1,
        ]);
        $submission = $this->projectSubmission($student);
        PaymentReconciliationFinding::query()->create([
            'provider' => 'kashier', 'order_ref' => 'ORDER-VIEW-1',
            'fingerprint' => hash('sha256', 'ORDER-VIEW-1'), 'kind' => 'provider_unavailable',
            'local_status' => 'approved', 'local_financial_status' => 'review_required',
            'provider_status' => 'reversed', 'provider_transaction_id' => 'TXN-VIEW-1',
            'state' => PaymentReconciliationFinding::STATE_OPEN, 'attempts' => 1,
            'first_seen_at' => now()->subHour(), 'last_seen_at' => now(),
            'evidence' => ['provider_status' => 'reversed'],
        ]);

        $pages = [
            'product operations' => route('admin.product-operations.index'),
            'playback operations' => route('admin.playback-operations.index'),
            'project review' => route('admin.project-submissions.show', $submission),
            'settings dashboard' => route('admin.settings'),
            'home dashboard' => route('admin.dashboard'),
            'course codes list' => route('admin.course-codes.index'),
            'course code details' => route('admin.course-codes.show', $code),
            'course code create' => route('admin.course-codes.create'),
            'course code edit' => route('admin.course-codes.edit', $code),
            'courses list' => route('admin.courses.index'),
            'course details' => route('admin.courses.show', $course),
            'course create' => route('admin.courses.create'),
        ];
        foreach ($pages as $page => $url) {
            $this->assertAdminMarkupWithoutInlineStyles($this->adminGet($admin, $url)->assertOk()->getContent(), $page);
        }
        $this->adminGet($admin, route('admin.project-submissions.index'))->assertRedirect(ProjectSubmissionResource::listUrl());
        $this->adminGet($admin, ProjectSubmissionResource::getUrl())->assertOk()->assertSee($submission->public_id);

        $this->assertAdminMarkupWithoutInlineStyles(
            $this->adminGet($this->dashboardUser('moderator'), route('admin.dashboard'))->assertOk()->getContent(),
            'moderator workspace'
        );
    }

    public function test_mfa_pages_use_the_admin_auth_layout(): void
    {
        $admin = $this->dashboardUser('admin');
        $setup = $this->adminGet($admin, route('admin.mfa.setup'))->assertOk()->getContent();

        $admin->forceFill(['admin_totp_secret' => 'JBSWY3DPEHPK3PXP', 'admin_totp_confirmed_at' => now()])->save();
        $challenge = $this->adminGet($admin, route('admin.mfa.challenge'))->assertOk()->getContent();

        $this->flushSession();
        $codes = $this->actingAs($admin, 'web')
            ->withSession(['admin_mfa_new_recovery_codes_ciphertext' => Crypt::encryptString(json_encode(['AAAA-BBBB', 'CCCC-DDDD']))])
            ->get(route('admin.mfa.backup-codes'))
            ->assertOk()
            ->assertSee('AAAA-BBBB')
            ->getContent();

        foreach (['setup' => $setup, 'challenge' => $challenge, 'recovery codes' => $codes] as $page => $html) {
            $xpath = $this->dom((string) $html);
            self::assertSame(1, $xpath->query('//body[@class="admin-auth-shell"]//main[@class="admin-auth-card"]')->length, $page);
            self::assertSame(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " modern-sidebar ")]')->length, $page);
            self::assertSame(0, $this->styleBlockCount((string) $html), $page);
        }
    }

    public function test_course_studio_editor_renders_its_settings_form_and_behaviour(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = $this->draftCourse();
        $html = (string) $this->adminGet($admin, route('admin.courses.show', $course))->assertOk()->getContent();
        $xpath = $this->dom($html);

        $this->assertLinksStylesheet($html, 'course-editor.css', 'course studio');
        self::assertSame(1, $xpath->query('//section[@id="studioCoursePanel" and contains(concat(" ", normalize-space(@class), " "), " course-editor ")]')->length);
        $form = '//form[@id="studioCourseForm"]';
        foreach (['classification_ids[]', 'teacher_ids[]', 'access_plans[basic][price_coins]'] as $field) {
            self::assertGreaterThan(0, $xpath->query($form.'//*[@name="'.$field.'"]')->length, $field);
        }
        self::assertSame(1, $xpath->query($form.'//label[contains(concat(" ", normalize-space(@class), " "), " file-upload-area ") and @for="image"]')->length);
        self::assertSame(1, $xpath->query($form.'//input[@id="image" and @type="file"]')->length);
        self::assertSame(0, $xpath->query('//*[contains(@onclick, "getElementById(\'image\')")]')->length);
        // The image preview and readiness refresh behaviour ships with the page.
        $scripts = (string) $xpath->evaluate('string(//body)');
        self::assertStringContainsString('const showImage = file =>', $scripts);
        self::assertStringContainsString('const refreshState = () =>', $scripts);
    }

    public function test_course_sections_use_the_single_inline_studio_editor(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = $this->draftCourse();
        $html = (string) $this->adminGet($admin, route('admin.courses.show', $course))->assertOk()->getContent();
        $xpath = $this->dom($html);

        foreach ([
            'section_type', 'title_ar', 'lesson_duration_minutes', 'video_source_type',
            'bunny_video_claim', 'project_requirements_ar', 'project_submission_types[]',
        ] as $field) {
            self::assertGreaterThan(0, $xpath->query('//form//*[@name="'.$field.'"]')->length, $field);
        }
        self::assertSame(1, $xpath->query('//form//input[@id="bunny_video" and @type="file"]')->length);
        // The outline, coordinator, section and module editors are exercised in
        // scripts/tests/course-studio-authoring.browser.mjs; the studio loads them.
        $assets = $this->linkedLocalAssets($html);
        foreach (['outline', 'editor-coordinator', 'section-editor', 'module-editor'] as $module) {
            self::assertContains('admin/assets/js/course-studio-'.$module.'.js', $assets);
        }
        self::assertNull(Route::getRoutes()->getByName('admin.course-sections.create'));
    }

    public function test_admin_runtime_assets_are_cache_busted_by_the_deployed_file(): void
    {
        $html = (string) $this->adminGet($this->dashboardUser('admin'), route('admin.dashboard'))->assertOk()->getContent();
        $sources = [];
        foreach ($this->dom($html)->query('//script/@src') as $src) {
            $sources[] = (string) $src->nodeValue;
        }

        foreach (['js/app.js', 'admin/assets/js/main.js', 'admin/assets/js/request.js'] as $path) {
            self::assertContains(asset($path).'?v='.filemtime(public_path($path)), $sources, $path);
        }
    }

    public function test_order_screens_keep_their_filters_actions_and_evidence_viewers(): void
    {
        $admin = $this->dashboardUser('admin');
        $order = $this->packageOrder($this->dashboardUser('client'), [
            'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
            'status' => Order::STATUS_APPROVED,
            'payment_screenshot' => 'payment-evidence/receipt.jpg',
        ]);

        $this->adminGet($admin, route('admin.orders.index'))->assertRedirect(OrderResource::listUrl());
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        Livewire::test(ListOrders::class)
            ->callTableAction('receipt', $order)->assertSee('إيصال الدفع');

        $this->adminGet($admin, route('admin.orders.show', $order))->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id]));
        Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('resolve')
            ->assertFormFieldExists('resolution')->assertFormFieldExists('note')->assertSee('إيصال الدفع');
    }

    public function test_course_create_is_a_thin_shell_that_enters_the_studio(): void
    {
        $html = (string) $this->adminGet($this->dashboardUser('admin'), route('admin.courses.create'))->assertOk()->getContent();
        $xpath = $this->dom($html);
        $form = '//form[@id="courseForm" and @action="'.route('admin.courses.store').'"]';

        self::assertSame(1, $xpath->query($form)->length);
        foreach (['name_ar', 'authoring_request_id', 'certificate_text_template_key'] as $field) {
            self::assertGreaterThan(0, $xpath->query($form.'//*[@name="'.$field.'"]')->length, $field);
        }
        self::assertSame(0, $xpath->query('//*[@name="image" or starts-with(@name, "access_plans")]')->length);
        self::assertSame(0, $xpath->query('//*[@id="studioCoursePanel"]')->length);
        // Refresh recovery is exercised in the real browser, not inferred from script bytes.
        self::assertSame(1, $xpath->query($form.'//*[@name="authoring_request_id"]')->length);
        self::assertNotContains('admin/assets/css/course-create.css', $this->linkedLocalAssets($html));
    }

    public function test_ai_policy_is_owned_by_admin_settings_not_course_authoring(): void
    {
        $admin = $this->dashboardUser('admin');
        $settings = $this->dom((string) $this->adminGet($admin, route('admin.settings'))->assertOk()->getContent());
        foreach (['chat_enabled', 'project_feedback_level'] as $policy) {
            self::assertGreaterThan(0, $settings->query('//form//*[@name="ai_plan_policy[basic]['.$policy.']"]')->length, $policy);
        }

        $course = $this->draftCourse();
        foreach ([route('admin.courses.create'), route('admin.courses.show', $course)] as $url) {
            $xpath = $this->dom((string) $this->adminGet($admin, $url)->assertOk()->getContent());
            self::assertSame(0, $xpath->query('//*[contains(@class, "ai-settings") or contains(@id, "ai-settings")]')->length, $url);
            self::assertSame(0, $xpath->query('//*[contains(@name, "[chat_enabled]") or contains(@name, "[project_feedback_level]")]')->length, $url);
        }
        // Further AI budget fields: ModeratorCourseCommercialPrivacyTest::test_course_editor_never_exposes_global_ai_policy_controls.
    }

    public function test_project_authoring_contains_content_not_ai_policy(): void
    {
        $course = $this->draftCourse();
        $xpath = $this->dom((string) $this->adminGet($this->dashboardUser('admin'), route('admin.courses.show', $course))->assertOk()->getContent());

        self::assertGreaterThan(0, $xpath->query('//*[@name="project_requirements_ar"]')->length);
        foreach (['submission_max_files', 'ai_prompt', 'ai_model_type', 'temperature', 'tokens_number', 'passing_score', 'fallback_review_delay_seconds'] as $field) {
            self::assertSame(0, $xpath->query('//*[@name="'.$field.'"]')->length, $field);
        }
    }

    public function test_course_screens_keep_their_list_actions_and_studio_regions(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = $this->draftCourse();

        $html = (string) $this->adminGet($admin, route('admin.courses.index'))->assertOk()->getContent();
        $index = $this->dom($html);
        self::assertSame(1, $index->query('//select[@id="classificationFilter" and @name="classification_id"]')->length);
        self::assertSame(1, $index->query('//form[@action="'.route('admin.courses.destroy', $course->id).'"]')->length);
        self::assertGreaterThan(0, $index->query('//button[starts-with(@onclick, "deleteCourse('.$course->id.',")]')->length);
        self::assertStringContainsString('function deleteCourse(', (string) $index->evaluate('string(//body)'));

        $studio = $this->dom((string) $this->adminGet($admin, route('admin.courses.show', $course))->assertOk()->getContent());
        self::assertGreaterThan(0, $studio->query('//*[@id="courseStudio"]')->length);
        self::assertSame(1, $studio->query('//header[contains(concat(" ", normalize-space(@class), " "), " course-workspace ")]')->length);
        self::assertSame(1, $studio->query('//script[@id="courseAuthoringGraph"]')->length);
        // The studio is the only editor: no embedded or retired editor pages.
        self::assertSame(0, $studio->query('//iframe')->length);
        foreach ([route('admin.courses.edit', $course), route('admin.courses.pdfs.index', $course)] as $retired) {
            self::assertSame(0, $studio->query('//a[@href="'.$retired.'"]')->length, $retired);
        }
    }

    private function assertAdminMarkupWithoutInlineStyles(string $html, string $page): void
    {
        $this->assertNoInlineStyleAttributes($html, $page);
        self::assertGreaterThan(
            0,
            $this->dom($html)->query('//main//*[contains(@class, "admin-")]')->length,
            "{$page} does not use the shared admin- classes"
        );
    }

    private function draftCourse(): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس الاستوديو', 'price' => 100,
            'is_coming_soon' => true, 'is_catalog_visible' => false, 'authoring_version' => 1,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function packageOrder(User $student, array $overrides = []): Order
    {
        $package = Package::query()->create(['name_ar' => 'باقة', 'name_en' => 'Package', 'price' => 100, 'coins' => 400]);

        return Order::query()->forceCreate([
            'user_id' => $student->id, 'package_id' => $package->id, 'package_coins' => 400,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 100, 'final_amount' => 100,
            'total_coins' => 400, 'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
            'order_ref' => 'ORD-'.Str::upper(Str::random(8)),
            ...$overrides,
        ]);
    }

    private function projectSubmission(User $student): ProjectSubmission
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس المشروع', 'is_coming_soon' => false,
            'is_catalog_visible' => true, 'authoring_version' => 1,
        ]);
        $project = Project::query()->create(['requirements_text' => 'نفذ المشروع', 'ai_prompt' => 'قيّم', 'passing_score' => 50]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title' => 'المشروع',
            'section_type' => 'project', 'sectionable_type' => Project::class,
            'sectionable_id' => $project->id, 'order' => 1,
        ]);

        return ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $student->id, 'project_id' => $project->id,
            'idempotency_key' => 'dashboard-view', 'submission_text' => 'ناتج المشروع',
            'effort_status' => ProjectSubmission::EFFORT_VALID,
            'review_status' => ProjectSubmission::STATUS_PENDING, 'submitted_at' => now(),
        ]);
    }
}
