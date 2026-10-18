<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Classification;
use App\Models\CoinEarningMethod;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\FeedbackReport;
use App\Models\Level;
use App\Models\Order;
use App\Models\Package;
use App\Models\Path;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

/**
 * Dashboard presentation lives in versioned stylesheets. These checks crawl
 * the rendered dashboard: every parameterless admin page plus the detail and
 * edit pages of real records.
 */
final class AdminInlineStyleContractTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    /** Pages that print the course-code, course-card and order dynamic style partials. */
    private const STYLE_BLOCK_PAGES = [
        'admin.course-codes.create',
        'admin.course-codes.edit',
        'admin.course-codes.index',
        'admin.course-codes.show',
        'admin.courses.index',
        'admin.courses.show',
        'admin.orders.index',
        'admin.orders.show',
    ];

    private const RETIRED_PALETTE = '/#(?:667eea|764ba2|092783)\b/i';

    public function test_rendered_admin_pages_have_no_inline_styles(): void
    {
        $styleBlockPages = [];
        foreach ($this->renderedPages() as $page => $html) {
            $this->assertNoInlineStyleAttributes($html, $page);
            if ($this->styleBlockCount($html) > 0) {
                $styleBlockPages[] = $page;
            }
        }

        sort($styleBlockPages);
        self::assertSame(
            [],
            array_values(array_diff($styleBlockPages, self::STYLE_BLOCK_PAGES)),
            'Only the dynamic style partials may print a <style> block.'
        );
    }

    public function test_retired_dashboard_palette_is_not_served(): void
    {
        $assets = [];
        foreach ($this->renderedPages() as $page => $html) {
            self::assertDoesNotMatchRegularExpression(self::RETIRED_PALETTE, $html, $page);
            foreach ($this->linkedLocalAssets($html) as $asset) {
                if (str_starts_with($asset, 'admin/')) {
                    $assets[$asset] = true;
                }
            }
        }

        self::assertNotEmpty($assets);
        foreach (array_keys($assets) as $asset) {
            $served = public_path($asset);
            self::assertFileExists($served, $asset);
            self::assertDoesNotMatchRegularExpression(
                self::RETIRED_PALETTE,
                (string) file_get_contents($served),
                $asset.' still uses the retired dashboard palette.'
            );
        }
    }

    public function test_account_settings_screen_loads_its_stylesheet_and_form(): void
    {
        $admin = $this->dashboardUser('admin');
        $html = (string) $this->adminGet($admin, route('admin.admin_data'))->assertOk()->getContent();
        $xpath = $this->dom($html);

        $this->assertLinksStylesheet($html, 'settings-admin-data.css', 'account settings');
        self::assertGreaterThan(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " admin-page ")]')->length);
        $form = '//form[@action="'.route('admin.update_admin_data').'"]';
        self::assertSame(1, $xpath->query($form)->length);
        self::assertSame(1, $xpath->query($form.'//input[@name="email"]')->length);
        self::assertSame(1, $xpath->query($form.'//input[@name="password"]')->length);
    }

    public function test_course_attachments_are_edited_inside_the_course_studio(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = $this->draftCourse();
        $html = (string) $this->adminGet($admin, route('admin.courses.show', $course))->assertOk()->getContent();
        $xpath = $this->dom($html);

        self::assertSame(1, $xpath->query('//section[@id="studioCourseAttachments"]')->length);
        $graph = json_decode(
            (string) $xpath->evaluate('string(//script[@id="coursePdfAuthoringGraph"])'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertSame(route('admin.courses.pdfs.store', $course), $graph['store_url']);
        self::assertSame(route('admin.courses.pdfs.reorder', $course), $graph['reorder_url']);
        self::assertGreaterThan(0, $xpath->query('//section[@id="studioCourseAttachments"]//form')->length);
        // The retired standalone attachments page now lands on this panel.
        $this->get(route('admin.courses.pdfs.index', $course))
            ->assertRedirect(route('admin.courses.show', $course).'#studioCourseAttachments');
    }

    public function test_shared_shell_styles_load_after_page_styles(): void
    {
        $admin = $this->dashboardUser('admin');
        $html = (string) $this->adminGet($admin, route('admin.contacts.index'))->assertOk()->getContent();
        $assets = $this->linkedLocalAssets($html);

        $pageStyles = array_search('admin/assets/css/admin-learning-views.css', $assets, true);
        $shellStyles = array_search('admin/assets/css/admin-shell.css', $assets, true);
        self::assertNotFalse($pageStyles);
        self::assertNotFalse($shellStyles);
        self::assertGreaterThan($pageStyles, $shellStyles);

        $xpath = $this->dom($html);
        self::assertSame(1, $xpath->query('//form[@id="logoutForm" and contains(concat(" ", normalize-space(@class), " "), " header-logout-form ")]')->length);
        self::assertSame(0, $xpath->query('//form[@id="logoutForm" and contains(concat(" ", normalize-space(@class), " "), " d-none ")]')->length);
        self::assertGreaterThan(0, $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " modern-sidebar ")]')->length);
        self::assertSame(0, $xpath->query('//header//script')->length, 'The dashboard header carries no inline behaviour.');

        $alert = (string) $this->withSession(['success' => 'تم الحفظ'])
            ->get(route('admin.contacts.index'))->assertOk()->getContent();
        $alertXPath = $this->dom($alert);
        self::assertSame(1, $alertXPath->query('//*[@role="alert"]//*[@data-close-alert]')->length);
        self::assertSame(0, $alertXPath->query('//*[@role="alert"]//script')->length);
    }

    /** @return array<string, string> route name => rendered HTML */
    private function renderedPages(): array
    {
        $admin = $this->dashboardUser('admin');
        $pages = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (
                ! str_starts_with($name, 'admin.')
                || ! in_array('GET', $route->methods(), true)
                || str_contains($route->uri(), '{')
                || str_contains($name, 'export')
                || str_ends_with($route->uri(), '.csv')
            ) {
                continue;
            }
            $response = $this->adminGet($admin, '/'.$route->uri());
            if (str_contains((string) $response->headers->get('Content-Type'), 'text/html') && $response->isOk()) {
                $pages[$name] = (string) $response->getContent();
            }
        }
        foreach ($this->detailPages() as $name => $url) {
            $pages[$name] = (string) $this->adminGet($admin, $url)->assertOk()->getContent();
        }
        // The moderator workspace replaces the administrator home.
        $pages['admin.dashboard (moderator)'] = (string) $this->adminGet(
            $this->dashboardUser('moderator'),
            route('admin.dashboard')
        )->assertOk()->getContent();

        self::assertGreaterThan(60, count($pages));

        return $pages;
    }

    /** @return array<string, string> */
    private function detailPages(): array
    {
        $course = $this->draftCourse();
        $teacher = $this->dashboardUser('teacher');
        $student = $this->dashboardUser('client');
        $moderator = $this->dashboardUser('moderator');
        $package = Package::query()->create(['name_ar' => 'باقة', 'name_en' => 'Package', 'price' => 100, 'coins' => 400]);
        $order = Order::query()->create([
            'user_id' => $student->id, 'package_id' => $package->id, 'package_coins' => 400,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 100, 'final_amount' => 100,
            'total_coins' => 400, 'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
            'order_ref' => 'ORD-'.Str::upper(Str::random(8)),
        ]);
        $classification = Classification::query()->create(['name_ar' => 'تصنيف', 'name_en' => 'Row']);
        $level = Level::query()->create(['name_ar' => 'مستوى', 'name_en' => 'Level', 'order' => 1]);
        $path = Path::query()->create(['title_ar' => 'مسار', 'title_en' => 'Path']);
        $method = CoinEarningMethod::query()->create([
            'title_ar' => 'مهمة', 'title_en' => 'Task', 'coins_amount' => 5, 'action_key' => 'crawl_task',
            'requires_external_visit' => false, 'verification_delay_seconds' => 0, 'is_active' => true,
        ]);
        $coupon = Coupon::query()->create([
            'name_ar' => 'خصم', 'code' => 'CRAWL10', 'balance' => 10,
            'expiry_date' => now()->addMonth(), 'active' => true,
        ]);
        $code = CourseCode::query()->forceCreate([
            'tenant_id' => 1, 'code' => 'CRAWL-CODE', 'name' => 'دفعة', 'type' => 'course', 'course_id' => $course->id,
            'max_uses' => 10, 'is_active' => true,
        ]);
        $contact = Contact::query()->create([
            'name' => 'Learner', 'email' => 'crawl-contact@rokn.test', 'phone' => '201000000000',
            'message' => 'رسالة', 'read' => false,
        ]);
        $feedback = FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new',
            'priority' => 'normal', 'message' => 'بلاغ', 'version' => 1,
        ]);
        $pages = [
            'admin.courses.show' => route('admin.courses.show', $course),
            'admin.teachers.show' => route('admin.teachers.show', $teacher),
            'admin.teachers.edit' => route('admin.teachers.edit', $teacher),
            'admin.moderators.edit' => route('admin.moderators.edit', $moderator),
            'admin.users.show' => route('admin.users.show', $student),
            'admin.users.edit' => route('admin.users.edit', $student),
            'admin.student-progress.show' => route('admin.student-progress.show', $student),
            'admin.orders.show' => route('admin.orders.show', $order),
            'admin.packages.show' => route('admin.packages.show', $package),
            'admin.packages.edit' => route('admin.packages.edit', $package),
            'admin.classifications.edit' => route('admin.classifications.edit', $classification),
            'admin.levels.edit' => route('admin.levels.edit', $level),
            'admin.paths.edit' => route('admin.paths.edit', $path),
            'admin.coin-earning-methods.edit' => route('admin.coin-earning-methods.edit', $method),
            'admin.coupons.edit' => route('admin.coupons.edit', $coupon),
            'admin.course-codes.show' => route('admin.course-codes.show', $code),
            'admin.course-codes.edit' => route('admin.course-codes.edit', $code),
            'admin.contacts.show' => route('admin.contacts.show', $contact),
            'admin.feedback.show' => route('admin.feedback.show', $feedback),
        ];
        $template = AdminNotification::query()->first();
        if ($template !== null) {
            $pages['admin.admin_notifications.edit'] = route('admin.admin_notifications.edit', $template);
        }

        return $pages;
    }

    private function draftCourse(): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس الاستوديو', 'price' => 100,
            'is_coming_soon' => true, 'is_catalog_visible' => false, 'authoring_version' => 1,
        ]);
    }
}
