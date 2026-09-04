<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminDailyPagesRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_daily_index_pages_render_against_an_empty_catalogue(): void
    {
        $admin = $this->dashboardUser('admin');
        $this->withoutMiddleware(RequireAdminMfa::class);

        foreach ([
            'admin.dashboard',
            'admin.courses.index',
            'admin.courses.create',
            'admin.users.index',
            'admin.orders.index',
        ] as $routeName) {
            $this->actingAs($admin, 'web')
                ->get(route($routeName))
                ->assertOk();
        }
    }

    public function test_moderator_daily_course_pages_render_without_financial_data(): void
    {
        $moderator = $this->dashboardUser('moderator');
        $canonical = $this->course('النسخة المنشورة');
        $draft = $this->course('النسخة الجارية');
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id,
            'revision_course_id' => $draft->id,
            'base_authoring_version' => 1,
            'status' => CourseAuthoringRevision::DRAFT,
            'active_slot' => 'course:'.$canonical->id,
            'clone_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);

        foreach ([
            'admin.dashboard',
            'admin.courses.index',
            'admin.courses.create',
        ] as $routeName) {
            $this->actingAs($moderator, 'web')
                ->get(route($routeName))
                ->assertOk();
        }
    }

    public function test_administrator_daily_detail_pages_render_existing_records(): void
    {
        $admin = $this->dashboardUser('admin');
        $student = $this->dashboardUser('client');
        $course = $this->course('كورس يومي');
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'المقطع الأول',
            'is_opened' => true,
            'duration_minutes' => 2,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'title_ar' => 'المقطع الأول',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
        ]);
        $order = Order::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 500,
            'final_amount' => 500,
            'total_coins' => 500,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
        ]);
        $package = Package::query()->create([
            'name_ar' => 'باقة يومية',
            'name_en' => 'Daily package',
            'price' => 100,
            'coins' => 400,
        ]);
        Order::query()->create([
            'user_id' => $student->id,
            'course_id' => null,
            'package_id' => $package->id,
            'package_coins' => 400,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'order_ref' => 'daily-runtime-kashier',
            'amount' => 100,
            'final_amount' => 100,
            'gateway_gross_amount' => 100,
            'gateway_currency' => 'EGP',
            'gateway_settlement_status' => 'captured',
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web');

        $studio = $this->get(route('admin.courses.show', $course))->assertOk();
        $this->assertStudioCourseForm($studio->getContent(), $course);

        foreach ([
            route('admin.users.show', $student),
            route('admin.users.edit', $student),
            route('admin.orders.show', $order),
            route('admin.orders.index'),
            route('admin.dashboard'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('admin.courses.edit', $course))
            ->assertRedirect(route('admin.courses.show', $course));
        $this->get(route('admin.courses.pdfs.index', $course))
            ->assertRedirect(route('admin.courses.show', $course).'#studioCourseAttachments');

        foreach (array_keys(\App\Services\AdminPaymentOperationsReadService::stateLabels()) as $state) {
            $this->get(route('admin.orders.index', ['state' => $state]))->assertOk();
        }
    }

    private function dashboardUser(string $role): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => match ($role) {
                'admin' => 'مالك ركن',
                'moderator' => 'محرر المحتوى',
                default => 'طالب ركن',
            },
            'email' => $role.'-daily-pages@example.test',
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }

    private function course(string $name): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'price' => 500,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'authoring_version' => 1,
        ])->save();

        return $course;
    }

    private function assertStudioCourseForm(string $html, Course $course): void
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//*[@id="studioCourseForm"]');

        self::assertNotFalse($forms);
        self::assertSame(1, $forms->length);
        $form = $forms->item(0);
        self::assertNotNull($form);
        self::assertSame('POST', strtoupper($form->getAttribute('method')));
        self::assertSame(route('admin.courses.update', $course), $form->getAttribute('action'));
        self::assertSame('PATCH', $this->formValue($xpath, $form, '_method'));
        self::assertNotSame('', $this->formValue($xpath, $form, '_token'));
        self::assertSame('1', $this->formValue($xpath, $form, 'authoring_version'));
        self::assertSame('studio', $this->formValue($xpath, $form, 'return_to'));

        $feedback = $xpath->query('.//*[@data-course-feedback and @role="alert"]', $form);
        self::assertNotFalse($feedback);
        self::assertSame(1, $feedback->length);
    }

    private function formValue(DOMXPath $xpath, \DOMNode $form, string $name): string
    {
        $fields = $xpath->query('.//input[@name="'.$name.'"]', $form);
        self::assertNotFalse($fields);
        self::assertSame(1, $fields->length, "Unexpected {$name} field count");

        return $fields->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '';
    }
}
