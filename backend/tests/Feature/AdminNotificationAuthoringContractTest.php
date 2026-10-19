<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\NotificationCampaignResource\Pages\ListNotificationCampaigns;
use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Filament\Resources\StudentResource;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AdminNotification;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\AdminNotificationTemplateAuthoringService;
use App\Services\CourseContentNotificationService;
use App\Services\CoursePublishingService;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class AdminNotificationAuthoringContractTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Template images are written only after their ledger commits, so this
        // class migrates its own in-memory schema instead of RefreshDatabase.
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->admin = User::query()->forceCreate([
            'name' => 'Notifications Admin', 'email' => 'notifications-admin@rokn.test', 'role' => 'admin', 'active' => true,
        ]);
    }

    public function test_broadcast_form_exposes_the_complete_campaign_contract(): void
    {
        $student = $this->student();
        $this->actingAs($this->admin, 'web')
            ->get(route('admin.notifications.create', ['user_id' => $student->id]))
            ->assertRedirect(NotificationCampaignResource::getUrl('create-for-student', ['student' => $student->id]));
    }

    public function test_campaign_list_does_not_expose_internal_codes_or_raw_deep_links(): void
    {
        $this->campaign([
            'status' => NotificationCampaign::STATUS_FAILED,
            'failure_code' => 'chunk_fcm_internal_trace_42',
            'link' => 'rokn://course/9876/lesson/5432',
        ]);

        $this->actingAs($this->admin, 'web')
            ->get(route('admin.notifications.index'))
            ->assertRedirect(NotificationCampaignResource::getUrl());
        $this->get(NotificationCampaignResource::getUrl())
            ->assertOk()
            ->assertSee('توقف توزيع الحملة قبل اكتماله')
            ->assertSee('الكورس')
            ->assertDontSee('chunk_fcm_internal_trace_42')
            ->assertDontSee('rokn://course/9876', false);
    }

    public function test_template_authoring_cannot_create_unwired_event_keys(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get(route('admin.admin_notifications.create'))
            ->assertRedirect(NotificationTemplateResource::getUrl('create'));

        foreach (['made_up_event', 'new_quiz'] as $key) {
            $this->postTemplate(['system_key' => $key, 'surface' => 'transactional'])
                ->assertSessionHasErrors('system_key');
        }
        self::assertFalse(AdminNotification::query()->whereIn('system_key', ['made_up_event', 'new_quiz'])->exists());
    }

    public function test_quiz_is_not_an_available_notification_family(): void
    {
        self::assertNotContains('new_quiz', AdminNotification::SYSTEM_KEYS);
        self::assertFalse(AdminNotification::query()->where('system_key', 'like', '%quiz%')->exists());
        self::assertFalse(method_exists(CourseContentNotificationService::class, 'notifyNewQuiz'));
    }

    public function test_individual_and_group_authoring_share_one_campaign_controller(): void
    {
        self::assertFalse(Route::has('admin.users.send_notification'));
        $actions = collect(Route::getRoutes()->getRoutes())->map->getActionName()->all();
        self::assertNotContains(UsersController::class.'@sendNotification', $actions);

        $student = $this->student();
        $html = $this->actingAs($this->admin, 'web')
            ->get(StudentResource::getUrl('view', ['record' => $student->id]))
            ->assertOk()
            ->getContent();
        $href = route('admin.notifications.create', ['user_id' => $student->id]);
        self::assertGreaterThan(0, $this->xpath($html)->query('//a[@href="'.$href.'"]')->length);
    }

    public function test_campaign_and_template_lists_are_bounded(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->campaign([]);
            AdminNotification::query()->forceCreate([
                'surface' => 'announcement', 'title_ar' => 'إعلان '.$i, 'description_ar' => 'نص الإعلان',
                'priority' => 10, 'cooldown_hours' => 0, 'is_active' => false,
            ]);
        }

        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $campaigns = Livewire::test(ListNotificationCampaigns::class)->instance()->getTableRecords();
        self::assertSame(30, $campaigns->perPage());
        self::assertCount(30, $campaigns->items());

        $this->get(route('admin.admin_notifications.index'))->assertRedirect(NotificationTemplateResource::getUrl());
        $templates = Livewire::test(ListNotificationTemplates::class)->instance()->getTableRecords();
        self::assertSame(30, $templates->perPage());
        self::assertCount(30, $templates->items());
    }

    public function test_template_and_course_links_are_canonical_app_links(): void
    {
        // Service-level normalisation is also proven in
        // AdminNotificationTemplateOwnershipTest::test_template_copy_schedule_link_and_photo_commit_with_the_receipt.
        $this->postTemplate(['link' => 'https://rokn.app/courses/12/lesson/34', 'action_label_ar' => 'افتح الدرس'])
            ->assertRedirect(route('admin.admin_notifications.index'))
            ->assertSessionHasNoErrors();
        self::assertSame(
            'rokn://course/12/lesson/34',
            AdminNotification::query()->where('title_ar', 'إعلان يدوي جديد')->sole()->link
        );

        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);
        $this->app->instance(CoursePublishingService::class, $publishing);
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس الإشعار', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة الأولى', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'الدرس الأول', 'is_opened' => true, 'duration_minutes' => 2]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'الدرس الأول',
            'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
        self::assertTrue(CourseContentNotificationService::notifyCourseUpdate($course));
        self::assertTrue(CourseContentNotificationService::notifyNewCourse($course, (string) Str::uuid()));
        $links = NotificationCampaign::query()->where('course_id', $course->id)->pluck('link')->all();
        self::assertSame(['rokn://course/'.$course->id, 'rokn://course/'.$course->id], $links);
    }

    public function test_manual_messages_cannot_be_saved_to_an_unread_surface_or_with_a_dead_action(): void
    {
        $this->actingAs($this->admin, 'web')->get(route('admin.admin_notifications.create'))
            ->assertRedirect(NotificationTemplateResource::getUrl('create'));

        $this->postTemplate(['surface' => 'transactional'])->assertSessionHasErrors('surface');
        $this->flushSession();
        $this->postTemplate(['link' => 'rokn://wallet', 'action_label_ar' => ''])->assertSessionHasErrors('action_label_ar');
        $this->flushSession();
        $this->postTemplate(['link' => '', 'action_label_ar' => 'افتح'])->assertSessionHasErrors('link');
        self::assertFalse(AdminNotification::query()->where('title_ar', 'إعلان يدوي جديد')->exists());

        config(['app.url' => 'https://localhost']);
        $template = app(AdminNotificationTemplateAuthoringService::class)->create(
            [...$this->templatePayload(), 'title_ar' => 'إعلان مصور'], (string) Str::uuid(),
            UploadedFile::fake()->image('announcement.png', 24, 24), static function (): void {}
        );
        $imageUrl = $template->fresh()->photo?->assetPath();
        self::assertNotNull($imageUrl);
        $listing = $this->xpath($this->actingAs($this->admin, 'web')
            ->get(NotificationTemplateResource::getUrl())->assertOk()->getContent());
        self::assertSame(1, $listing->query('//img[@src="'.$imageUrl.'"]')->length);
    }

    /** @param array<string, mixed> $overrides */
    private function postTemplate(array $overrides): TestResponse
    {
        return $this->actingAs($this->admin, 'web')
            ->from(route('admin.admin_notifications.create'))
            ->post(route('admin.admin_notifications.store'), [
                ...$this->templatePayload(),
                'authoring_request_id' => (string) Str::uuid(),
                ...$overrides,
            ]);
    }

    /** @return array<string, mixed> */
    private function templatePayload(): array
    {
        return [
            'system_key' => '', 'surface' => 'announcement', 'title_ar' => 'إعلان يدوي جديد',
            'description_ar' => 'تفاصيل الإعلان اليدوي', 'link' => 'https://rokn.app/wallet',
            'action_label_ar' => 'افتح الرصيد', 'priority' => 20, 'cooldown_hours' => 0,
            'is_active' => '1', 'is_dismissible' => '1',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function campaign(array $overrides): NotificationCampaign
    {
        return NotificationCampaign::query()->create([
            'delivery_key' => (string) Str::uuid(),
            'notification_type' => 'admin_broadcast',
            'audience' => 'all',
            'title_ar' => 'حملة للطلاب',
            'message_ar' => 'رسالة الحملة',
            'status' => NotificationCampaign::STATUS_COMPLETED,
            'queued_at' => now(),
            ...$overrides,
        ]);
    }

    private function student(): User
    {
        return User::query()->forceCreate([
            'name' => 'Notified Student', 'email' => 'notified-'.uniqid().'@rokn.test', 'role' => 'client', 'active' => true,
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }
}
