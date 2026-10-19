<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AppSettings;
use App\Filament\Pages\ContentWorkspace;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\TeacherResource\Pages\EditTeacher;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\CoinEarningMethod;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Order;
use App\Models\Package;
use App\Models\Path;
use App\Models\RewardRule;
use App\Models\User;
use App\Services\AdminPaymentOperationsReadService;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminDailyPagesRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unloaded_relations_on_a_list_fail_the_test_instead_of_issuing_queries_per_row(): void
    {
        $first = $this->dashboardUser('client');
        $second = $this->dashboardUser('admin');
        $users = User::query()->whereIn('id', [$first->id, $second->id])->get();

        $this->expectException(LazyLoadingViolationException::class);
        $users->first()->photo;
    }

    public function test_administrator_daily_index_pages_render_against_an_empty_catalogue(): void
    {
        $admin = $this->dashboardUser('admin');
        $this->withoutMiddleware(RequireAdminMfa::class);

        foreach ([
            'filament.operations.pages.dashboard',
            'admin.courses.index',
            'admin.courses.create',
            'filament.operations.resources.students.index',
            'filament.operations.resources.orders.index',
            'filament.operations.resources.orders.report',
        ] as $routeName) {
            $this->actingAs($admin, 'web')
                ->get(route($routeName))
                ->assertOk();
        }
        $this->get(route('admin.dashboard'))->assertRedirect(Dashboard::getUrl());
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
            'clone_key' => (string) Str::uuid(),
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);

        foreach ([
            'filament.operations.pages.content-workspace',
            'admin.courses.index',
            'admin.courses.create',
        ] as $routeName) {
            $this->actingAs($moderator, 'web')
                ->get(route($routeName))
                ->assertOk();
        }
        $this->get(route('admin.dashboard'))->assertRedirect(ContentWorkspace::getUrl(['page' => 1]));
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
        $this->assertStudioLessonRow($studio->getContent(), 'المقطع الأول', 'مقطع · 2 دقيقة · مجاني');

        foreach ([
            StudentResource::getUrl('view', ['record' => $student->id]),
            StudentResource::getUrl('edit', ['record' => $student->id]),
            OrderResource::getUrl('view', ['record' => $order->id]),
            route('filament.operations.resources.orders.index'),
            route('filament.operations.resources.orders.report'),
            Dashboard::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('admin.courses.edit', $course))
            ->assertRedirect(route('admin.courses.show', $course));
        $this->get(route('admin.courses.pdfs.index', $course))
            ->assertRedirect(route('admin.courses.show', $course).'#studioCourseAttachments');

        foreach (array_keys(AdminPaymentOperationsReadService::stateLabels()) as $state) {
            $this->get(route('admin.orders.index', ['state' => $state]))->assertRedirect(OrderResource::listUrl(['state' => $state]));
        }
    }

    public function test_paired_boolean_controls_render_unique_ids_and_labels_target_the_checkbox(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = $this->course('كورس الحقول المنطقية');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web');

        $studio = $this->get(route('admin.courses.show', $course))->assertOk();
        $studioDocument = new DOMDocument;
        @$studioDocument->loadHTML('<?xml encoding="utf-8" ?>'.$studio->getContent());
        $studioXPath = new DOMXPath($studioDocument);
        $this->assertUniqueDomIds($studioXPath);
        $this->assertPairedBooleanControl($studioXPath, 'is_main_course');
        $this->assertPairedBooleanControl($studioXPath, 'is_catalog_visible');

        $this->get(route('admin.settings'))->assertRedirect(AppSettings::getUrl());
        $settings = $this->get(AppSettings::getUrl())->assertOk();
        $settingsDocument = new DOMDocument;
        @$settingsDocument->loadHTML('<?xml encoding="utf-8" ?>'.$settings->getContent());
        $settingsXPath = new DOMXPath($settingsDocument);
        $this->assertUniqueDomIds($settingsXPath);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $native = Livewire::test(AppSettings::class);
        foreach (['english_translation', 'bunny_enabled'] as $name) {
            $field = $native->instance()->getSchema('form')->getFlatFields(withHidden: true)[$name] ?? null;
            self::assertNotNull($field);
            $id = $field->getId();
            self::assertSame(1, $settingsXPath->query('//*[@id="'.$id.'" and @role="switch"]')->length);
            self::assertSame(1, $settingsXPath->query('//label[@for="'.$id.'"]')->length);
        }
        $native->fillForm(['english_translation' => false, 'bunny_enabled' => false])
            ->assertSet('data.english_translation', false)->assertSet('data.bunny_enabled', false);
    }

    public function test_course_studio_restores_submitted_relationship_fields_after_validation_failure(): void
    {
        $moderator = $this->dashboardUser('moderator');
        $course = $this->course('كورس التحرير');
        $storedClassification = Classification::query()->create([
            'name_ar' => 'التصنيف القديم',
            'name_en' => 'Stored classification',
        ]);
        $submittedClassification = Classification::query()->create([
            'name_ar' => 'التصنيف الجديد',
            'name_en' => 'Submitted classification',
        ]);
        $submittedLevel = Level::query()->create([
            'name_ar' => 'المستوى الجديد',
            'name_en' => 'Submitted level',
            'order' => 1,
        ]);
        $submittedPath = Path::query()->create([
            'title_ar' => 'المسار الجديد',
            'title_en' => 'Submitted path',
        ]);
        $storedTeacher = $this->dashboardUser('teacher');
        $course->classifications()->attach($storedClassification);
        $course->teachers()->attach($storedTeacher);
        $this->withoutMiddleware(RequireAdminMfa::class);

        $response = $this->actingAs($moderator, 'web')
            ->from(route('admin.courses.show', $course))
            ->post(route('admin.courses.update', $course), [
                '_method' => 'PATCH',
                'authoring_version' => 1,
                'name_ar' => 'x',
                'classification_ids_present' => 1,
                'classification_ids' => [$submittedClassification->id],
                'teacher_ids_present' => 1,
                'level_id' => $submittedLevel->id,
                'path_id' => $submittedPath->id,
            ]);

        $response->assertRedirect(route('admin.courses.show', $course));
        $page = $this->followRedirects($response)->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new DOMXPath($document);

        self::assertSame(
            (string) $submittedClassification->id,
            trim((string) $xpath->evaluate('string(//select[@name="classification_ids[]"]/option[@selected]/@value)'))
        );
        self::assertSame(
            (string) $submittedLevel->id,
            trim((string) $xpath->evaluate('string(//select[@name="level_id"]/option[@selected]/@value)'))
        );
        self::assertSame(
            (string) $submittedPath->id,
            trim((string) $xpath->evaluate('string(//select[@name="path_id"]/option[@selected]/@value)'))
        );
        self::assertSame('', trim((string) $xpath->evaluate(
            'string(//select[@name="teacher_ids[]"]/option[@selected]/@value)'
        )));
    }

    public function test_teacher_form_keeps_an_explicit_inactive_choice_after_validation_failure(): void
    {
        $moderator = $this->dashboardUser('moderator');
        $teacher = $this->dashboardUser('teacher');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        Livewire::test(EditTeacher::class, ['record' => $teacher->id])
            ->fillForm(['name_ar' => '', 'active' => false])->call('save')
            ->assertHasFormErrors(['name_ar' => 'required'])->assertSet('data.active', false);
    }

    public function test_reward_task_create_receipt_replays_without_creating_another_task(): void
    {
        $this->assertRewardCreateReplay('admin.coin-earning-methods.store', CoinEarningMethod::class, [
            'title_ar' => 'مهمة اختبار', 'title_en' => 'Test task', 'coins_amount' => 10,
            'action_key' => 'internal_receipt_task', 'requires_external_visit' => '0', 'is_active' => '1',
        ]);
    }

    public function test_reward_rule_create_receipt_replays_before_duplicate_event_validation(): void
    {
        RewardRule::query()->where('event_key', 'course_completed')->delete();
        $this->assertRewardCreateReplay('admin.reward-rules.store', RewardRule::class, [
            'event_key' => 'course_completed', 'title_ar' => 'إنهاء كورس', 'title_en' => 'Course completion',
            'coins_amount' => 10, 'rolling_30_day_cap' => 100, 'is_active' => '1',
        ]);
    }

    /** @param class-string<Model> $modelClass */
    private function assertRewardCreateReplay(string $routeName, string $modelClass, array $payload): void
    {
        $admin = $this->dashboardUser('admin');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $intentId = (string) Str::uuid();
        $payload['authoring_request_id'] = $intentId;
        $before = $modelClass::query()->count();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($admin, 'web')->post(route($routeName), $payload)
                ->assertRedirect(route('admin.coin-earning-methods.index'))
                ->assertSessionHasNoErrors();
            self::assertSame($before + 1, $modelClass::query()->count());
        }
        $receipt = DB::table('admin_authoring_create_intents')
            ->where('actor_id', $admin->id)->where('route_name', $routeName)->where('intent_id', $intentId)->sole();
        self::assertSame('completed', $receipt->status);
        self::assertSame($modelClass, $receipt->resource_type);
        self::assertTrue($modelClass::query()->whereKey($receipt->resource_id)->exists());
    }

    private function dashboardUser(string $role): User
    {
        $user = new User;
        $user->forceFill([
            'name_ar' => match ($role) {
                'admin' => 'مالك ركن',
                'moderator' => 'محرر المحتوى',
                'teacher' => 'محاضر ركن',
                default => 'طالب ركن',
            },
            'email' => $role.'-daily-pages@example.test',
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }

    private function assertStudioLessonRow(string $html, string $title, string $label): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($document);
        $rows = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' outline-item ')]");

        self::assertNotFalse($rows);
        foreach ($rows as $row) {
            $rowTitle = trim((string) $xpath->evaluate('string(.//strong)', $row));
            if ($rowTitle !== $title) {
                continue;
            }

            self::assertSame($label, trim((string) $xpath->evaluate('string(.//small)', $row)));

            return;
        }

        self::fail('Expected Studio lesson row was not rendered.');
    }

    private function course(string $name): Course
    {
        $course = new Course;
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
        $document = new DOMDocument;
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

    private function inputValue(DOMXPath $xpath, string $formId, string $name): string
    {
        return trim((string) $xpath->evaluate(
            'string(//form[@id="'.$formId.'"]//input[@name="'.$name.'"]/@value)'
        ));
    }

    private function assertPairedBooleanControl(DOMXPath $xpath, string $name): void
    {
        $checkbox = $xpath->query('//input[@id="'.$name.'" and @name="'.$name.'" and @type="checkbox"]');
        self::assertNotFalse($checkbox);
        self::assertSame(1, $checkbox->length, "Expected one checkbox for {$name}");

        $fallback = $xpath->query('//input[@id="'.$name.'_fallback" and @name="'.$name.'" and @type="hidden" and @value="0"]');
        self::assertNotFalse($fallback);
        self::assertSame(1, $fallback->length, "Expected one false fallback for {$name}");

        $labels = $xpath->query('//label[@for="'.$name.'"]');
        self::assertNotFalse($labels);
        self::assertSame(1, $labels->length, "Expected the label to target {$name} checkbox only");
    }

    private function assertUniqueDomIds(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//*[@id]');
        self::assertNotFalse($nodes);
        $ids = [];
        foreach ($nodes as $node) {
            $id = $node->attributes?->getNamedItem('id')?->nodeValue;
            if (! is_string($id) || $id === '') {
                continue;
            }
            self::assertArrayNotHasKey($id, $ids, "Duplicate DOM id {$id}");
            $ids[$id] = true;
        }
    }
}
