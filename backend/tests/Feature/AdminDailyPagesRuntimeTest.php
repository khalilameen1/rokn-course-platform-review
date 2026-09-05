<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Classification;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Order;
use App\Models\Package;
use App\Models\Path;
use App\Models\RewardRule;
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
        $this->assertStudioLessonRow($studio->getContent(), 'المقطع الأول', 'مقطع · 2 دقيقة · مجاني');

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

    public function test_paired_boolean_controls_render_unique_ids_and_labels_target_the_checkbox(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = $this->course('كورس الحقول المنطقية');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web');

        $studio = $this->get(route('admin.courses.show', $course))->assertOk();
        $studioDocument = new DOMDocument();
        @$studioDocument->loadHTML('<?xml encoding="utf-8" ?>'.$studio->getContent());
        $studioXPath = new DOMXPath($studioDocument);
        $this->assertUniqueDomIds($studioXPath);
        $this->assertPairedBooleanControl($studioXPath, 'is_main_course');
        $this->assertPairedBooleanControl($studioXPath, 'is_catalog_visible');

        $settings = $this->get(route('admin.settings'))->assertOk();
        $settingsDocument = new DOMDocument();
        @$settingsDocument->loadHTML('<?xml encoding="utf-8" ?>'.$settings->getContent());
        $settingsXPath = new DOMXPath($settingsDocument);
        $this->assertUniqueDomIds($settingsXPath);
        $this->assertPairedBooleanControl($settingsXPath, 'english_translation');
        $this->assertPairedBooleanControl($settingsXPath, 'enforce_course_section_order');
        $this->assertPairedBooleanControl($settingsXPath, 'bunny_enabled');
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
        $document = new DOMDocument();
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
        $edit = $this->actingAs($moderator, 'web')
            ->get(route('admin.teachers.edit', $teacher))
            ->assertOk();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$edit->getContent());
        $xpath = new DOMXPath($document);
        $editorVersion = trim((string) $xpath->evaluate(
            'string(//input[@name="editor_version"]/@value)'
        ));

        $response = $this->from(route('admin.teachers.edit', $teacher))
            ->put(route('admin.teachers.update', $teacher), [
                'editor_version' => $editorVersion,
                'name_ar' => '',
                'active' => '0',
            ]);

        $response->assertRedirect(route('admin.teachers.edit', $teacher));
        $page = $this->followRedirects($response)->assertOk();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new DOMXPath($document);
        self::assertSame(0, $xpath->query('//input[@name="active" and @type="checkbox" and @checked]')->length);
        self::assertSame('0', trim((string) $xpath->evaluate(
            'string(//input[@name="active" and @type="hidden"]/@value)'
        )));
    }

    public function test_failed_reward_rule_update_restores_only_its_own_card(): void
    {
        $admin = $this->dashboardUser('admin');
        $failedRule = RewardRule::query()->where('event_key', 'course_completed')->firstOrFail();
        $failedRule->update([
            'event_key' => 'course_completed',
            'title_ar' => 'إكمال الكورس',
            'coins_amount' => 100,
            'interval_count' => 1,
            'rolling_30_day_cap' => 300,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $otherRule = RewardRule::query()->where('event_key', 'welcome_bonus')->firstOrFail();
        $otherRule->update([
            'event_key' => 'welcome_bonus',
            'title_ar' => 'هدية التسجيل',
            'coins_amount' => 20,
            'interval_count' => 1,
            'is_active' => true,
            'sort_order' => 20,
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $index = $this->actingAs($admin, 'web')
            ->get(route('admin.coin-earning-methods.index'))
            ->assertOk();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$index->getContent());
        $xpath = new DOMXPath($document);
        $version = $this->inputValue($xpath, 'reward-rule-'.$failedRule->id, 'editor_version');

        $response = $this->from(route('admin.coin-earning-methods.index'))
            ->put(route('admin.reward-rules.update', $failedRule), [
                'editor_version' => $version,
                'event_key' => $failedRule->event_key,
                'title_ar' => 'اسم التعديل غير المحفوظ',
                'title_en' => '',
                'coins_amount' => -1,
                'interval_count' => 2,
                'daily_cap' => 10,
                'rolling_30_day_cap' => '',
                'sort_order' => 10,
                'is_active' => '0',
            ]);

        $response->assertRedirect(route('admin.coin-earning-methods.index'));
        $page = $this->followRedirects($response)->assertOk();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new DOMXPath($document);
        self::assertSame('اسم التعديل غير المحفوظ', $this->inputValue($xpath, 'reward-rule-'.$failedRule->id, 'title_ar'));
        self::assertSame('-1', $this->inputValue($xpath, 'reward-rule-'.$failedRule->id, 'coins_amount'));
        self::assertSame(0, $xpath->query('//form[@id="reward-rule-'.$failedRule->id.'"]//input[@name="is_active" and @type="checkbox" and @checked]')->length);
        self::assertSame('هدية التسجيل', $this->inputValue($xpath, 'reward-rule-'.$otherRule->id, 'title_ar'));
        self::assertSame(0, $xpath->query('//form[@id="reward-rule-create"]')->length);
    }

    public function test_reward_rule_form_only_offers_available_events_and_effective_fields(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->dashboardUser('admin'), 'web');
        $response = $this->withSession(['success' => 'تم حفظ قاعدة الاختبار'])
            ->get(route('admin.coin-earning-methods.index'))->assertOk();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertUniqueDomIds($xpath);
        self::assertSame(1, substr_count($response->getContent(), 'تم حفظ قاعدة الاختبار'));
        self::assertSame(0, $xpath->query('//form[@id="reward-rule-create"]')->length);

        foreach (RewardRule::query()->get() as $rule) {
            $form = '//form[@id="reward-rule-'.$rule->id.'"]';
            self::assertSame(
                in_array($rule->event_key, ['streak_milestone', 'study_session'], true) ? 1 : 0,
                $xpath->query($form.'//input[@name="interval_count"]')->length
            );
            self::assertSame(
                $rule->event_key === 'study_session' ? 1 : 0,
                $xpath->query($form.'//input[@name="daily_cap"]')->length
            );
            self::assertSame(
                $rule->event_key === 'welcome_bonus' ? 0 : 1,
                $xpath->query($form.'//input[@name="rolling_30_day_cap"]')->length
            );
            foreach ($xpath->query($form.'//label[@for]') as $label) {
                self::assertSame(1, $xpath->query($form.'//input[@id="'.$label->getAttribute('for').'"]')->length);
            }
        }

        RewardRule::query()->where('event_key', 'study_session')->delete();
        $response = $this->get(route('admin.coin-earning-methods.index'))->assertOk();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new DOMXPath($document);
        self::assertSame(1, $xpath->query('//form[@id="reward-rule-create"]')->length);
        self::assertSame(1, $xpath->query('//select[@name="event_key"]/option[@value!=""]')->length);
        self::assertSame('study_session', $xpath->evaluate('string(//select[@name="event_key"]/option[@value!=""]/@value)'));
    }

    public function test_failed_reward_rule_create_restores_only_the_create_form(): void
    {
        $admin = $this->dashboardUser('admin');
        $storedRule = RewardRule::query()->where('event_key', 'welcome_bonus')->firstOrFail();
        $storedRule->update([
            'event_key' => 'welcome_bonus',
            'title_ar' => 'هدية التسجيل',
            'coins_amount' => 20,
            'interval_count' => 1,
            'is_active' => true,
            'sort_order' => 20,
        ]);
        RewardRule::query()->where('event_key', 'daily_checkin')->delete();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $response = $this->actingAs($admin, 'web')
            ->from(route('admin.coin-earning-methods.index'))
            ->post(route('admin.reward-rules.store'), [
                'authoring_request_id' => (string) \Illuminate\Support\Str::uuid(),
                'event_key' => 'daily_checkin',
                'title_ar' => 'مكافأة الحضور الجديدة',
                'title_en' => 'Daily reward',
                'coins_amount' => 12,
                'interval_count' => 2,
                'daily_cap' => 24,
                'rolling_30_day_cap' => '',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('admin.coin-earning-methods.index'));
        $page = $this->followRedirects($response)->assertOk();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$page->getContent());
        $xpath = new DOMXPath($document);
        self::assertSame('daily_checkin', trim((string) $xpath->evaluate(
            'string(//form[@id="reward-rule-create"]//select[@name="event_key"]/option[@selected]/@value)'
        )));
        self::assertSame('مكافأة الحضور الجديدة', $this->inputValue($xpath, 'reward-rule-create', 'title_ar'));
        self::assertSame('12', $this->inputValue($xpath, 'reward-rule-create', 'coins_amount'));
        self::assertSame('هدية التسجيل', $this->inputValue($xpath, 'reward-rule-'.$storedRule->id, 'title_ar'));
    }

    private function dashboardUser(string $role): User
    {
        $user = new User();
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
        $document = new DOMDocument();
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
            if (!is_string($id) || $id === '') continue;
            self::assertArrayNotHasKey($id, $ids, "Duplicate DOM id {$id}");
            $ids[$id] = true;
        }
    }
}
