<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplateResource\Pages\CreateNotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages\EditNotificationTemplate;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AdminNotification;
use App\Models\User;
use App\Services\AdminNotificationTemplateAuthoringService;
use App\Support\BusinessClock;
use App\Support\NotificationTemplateEditorVersion;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentNotificationTemplateEditorWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_create_and_edit_preserve_media_local_time_and_manual_identity(): void
    {
        $starts = BusinessClock::now()->addDay()->format('Y-m-d\TH:i');
        $page = Livewire::test(CreateNotificationTemplate::class)->fillForm([...$this->fields(),
            'starts_at' => $starts, 'system_key' => 'new_course', 'surface' => 'transactional',
            'image' => UploadedFile::fake()->image('announcement.png')]);
        $intent = $page->get('creationIntent');
        $page->call('create')->assertHasNoFormErrors();
        $record = AdminNotification::query()->where('authoring_request_id', $intent)->sole();
        self::assertNull($record->system_key);
        self::assertSame('announcement', $record->surface);
        self::assertSame($record->title_ar, $record->title_en);
        self::assertSame('rokn://wallet', $record->link);
        self::assertSame(BusinessClock::localInputToUtc($starts)->getTimestamp(), $record->starts_at->getTimestamp());
        $original = $record->photo->path;
        Storage::disk('public')->assertExists($original);
        Livewire::test(EditNotificationTemplate::class, ['record' => $record->id])->assertSet('data.starts_at', $starts)
            ->fillForm(['title_ar' => 'الإعلان الأحدث', 'image' => UploadedFile::fake()->image('replacement.png')])
            ->call('save')->assertHasNoFormErrors();
        $record->refresh()->load('photo');
        self::assertSame($starts, BusinessClock::forDateTimeInput($record->starts_at));
        self::assertNotSame($original, $record->photo->path);
        Storage::disk('public')->assertExists($record->photo->path);
        self::assertSame(1, $record->allPhotos()->count());
        Livewire::test(EditNotificationTemplate::class, ['record' => $record->id])->fillForm(['remove_image' => true])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame(0, $record->allPhotos()->count());
        Http::assertNothingSent();
    }

    public function test_native_validation_rejects_unknown_variables_invalid_links_dead_buttons_and_bad_schedule(): void
    {
        foreach ([
            ['title_ar' => 'عنوان {unknown}', 'field' => 'title_ar'],
            ['link' => 'https://evil.example/payment', 'field' => 'link'],
            ['action_label_ar' => '', 'field' => 'action_label_ar'],
            ['link' => '', 'field' => 'link'],
            ['priority' => 1001, 'field' => 'priority'],
            ['starts_at' => '2026-12-20T12:00', 'ends_at' => '2026-12-19T12:00', 'field' => 'ends_at'],
        ] as $case) {
            $field = $case['field'];
            unset($case['field']);
            Livewire::test(CreateNotificationTemplate::class)->fillForm([...$this->fields(), ...$case])
                ->call('create')->assertHasFormErrors([$field]);
        }
        self::assertFalse(AdminNotification::query()->whereNull('system_key')->exists());
    }

    public function test_welcome_policy_is_fixed_while_authored_copy_and_required_buttons_are_preserved(): void
    {
        $welcome = AdminNotification::query()->where('system_key', 'guest_registration_prompt')->sole();
        $page = Livewire::test(EditNotificationTemplate::class, ['record' => $welcome->id])
            ->assertFormFieldIsDisabled('surface')->assertFormFieldIsDisabled('cooldown_hours')
            ->assertFormFieldIsDisabled('priority')->assertFormFieldIsDisabled('is_dismissible')
            ->assertFormFieldIsHidden('link')->assertSee('العدد من مكافأة الترحيب');
        $page->fillForm(['title_ar' => 'هديتك الخاصة جاهزة', 'description_ar' => '', 'is_active' => true,
            'action_label_ar' => 'تسجيل الدخول لاستلام الهدية', 'secondary_action_label_ar' => 'تابع كزائر',
            'surface' => 'announcement', 'priority' => 999, 'cooldown_hours' => 88, 'is_dismissible' => false,
            'system_key' => 'new_course', 'link' => '/wallet'])->call('save')->assertHasNoFormErrors();
        $welcome->refresh();
        self::assertSame('guest_registration_prompt', $welcome->system_key);
        self::assertSame('هديتك الخاصة جاهزة', $welcome->title_ar);
        self::assertSame('guest_prompt', $welcome->surface);
        self::assertSame(10, $welcome->priority);
        self::assertSame(0, $welcome->cooldown_hours);
        self::assertTrue($welcome->is_dismissible);
        self::assertNull($welcome->link);
        foreach (['action_label_ar', 'secondary_action_label_ar'] as $field) {
            Livewire::test(EditNotificationTemplate::class, ['record' => $welcome->id])->fillForm([$field => '', 'is_active' => true])
                ->call('save')->assertHasFormErrors([$field]);
        }
    }

    public function test_stale_native_editor_cannot_replace_a_newer_copy_or_photo(): void
    {
        $record = AdminNotification::query()->create($this->fields());
        $page = Livewire::test(EditNotificationTemplate::class, ['record' => $record->id]);
        $record->update(['title_ar' => 'النسخة الأحدث']);
        $page->fillForm(['title_ar' => 'نسخة قديمة'])->call('save')->assertNotified('تغيّر القالب أثناء التعديل');
        self::assertSame('النسخة الأحدث', $record->fresh()->title_ar);
        $page = Livewire::test(EditNotificationTemplate::class, ['record' => $record->id]);
        $record->allPhotos()->create(['type' => 'featured', 'path' => 'notifications/new.png']);
        $page->fillForm(['title_ar' => 'نسخة قديمة'])->call('save')->assertNotified('تغيّر القالب أثناء التعديل');
        self::assertSame(1, $record->allPhotos()->count());
    }

    public function test_owning_service_enforces_shared_journey_invariants_without_a_form(): void
    {
        $writer = app(AdminNotificationTemplateAuthoringService::class);
        foreach ([['surface' => 'retention', 'field' => 'surface'], ['link' => null, 'field' => 'link'],
            ['action_label_ar' => '', 'field' => 'action_label_ar']] as $case) {
            $field = $case['field'];
            unset($case['field']);
            try {
                $writer->create([...$this->fields(), ...$case], (string) Str::uuid(), null, static function (): void {});
                self::fail('Writer must reject an unread message or dead action.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey($field, $exception->errors());
            }
        }
        $record = AdminNotification::query()->create($this->fields());
        $writer->update((int) $record->id, [...$this->fields(), 'system_key' => 'invented_event'],
            NotificationTemplateEditorVersion::for($record), null, false);
        self::assertNull($record->fresh()->system_key);
    }

    public function test_saved_editor_urls_redirect_and_ordinary_moderators_cannot_author(): void
    {
        $record = AdminNotification::query()->create($this->fields());
        $this->get(route('admin.admin_notifications.create'))->assertRedirect(NotificationTemplateResource::getUrl('create'));
        $this->get(route('admin.admin_notifications.edit', $record))->assertRedirect(NotificationTemplateResource::getUrl('edit', ['record' => $record->id]));
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(CreateNotificationTemplate::class)->assertForbidden();
        Livewire::test(EditNotificationTemplate::class, ['record' => $record->id])->assertForbidden();
    }

    private function fields(): array
    {
        return ['system_key' => null, 'surface' => 'announcement', 'title_ar' => 'إعلان القالب الجديد',
            'description_ar' => 'نص الإعلان عن {course}', 'link' => '/wallet', 'action_label_ar' => 'افتح الرصيد',
            'priority' => 100, 'cooldown_hours' => 72, 'is_active' => true, 'is_dismissible' => true];
    }
}
