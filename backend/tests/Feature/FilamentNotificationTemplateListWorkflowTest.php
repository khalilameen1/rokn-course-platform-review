<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AdminNotification;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentNotificationTemplateListWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_literal_search_surface_and_activation_filters_preserve_the_template_selection(): void
    {
        $match = $this->template(['title_ar' => 'Design_100%']);
        $other = $this->template(['title_ar' => 'DesignX1000']);
        $disabled = $this->template(['title_ar' => 'Design_100%', 'is_active' => false]);
        Livewire::test(ListNotificationTemplates::class)->searchTable('_100%')->filterTable('surface', 'announcement')->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$match])->assertCanNotSeeTableRecords([$other, $disabled]);
        $this->get(route('admin.admin_notifications.index'))->assertRedirect(NotificationTemplateResource::getUrl());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_native_list_eager_loads_artwork_once_and_never_writes_on_read(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->template(['title_ar' => 'قالب مصور '.$i])->allPhotos()->create(['type' => 'featured', 'path' => 'notifications/template-'.$i.'.png']);
        }
        $total = AdminNotification::query()->count();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Livewire::test(ListNotificationTemplates::class)->assertCountTableRecords($total);
        self::assertCount(1, array_filter($queries, fn (string $sql): bool => preg_match('/from ["`]?photos["`]?/i', $sql) === 1));
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
    }

    public function test_identical_authored_titles_remain_identifiable_by_their_distinct_event_keys(): void
    {
        Livewire::test(ListNotificationTemplates::class)->searchTable('أكمل من مكانك')
            ->assertSee('learning_nudge')->assertSee('continue_course');
    }

    public function test_native_remove_disables_a_system_template_but_deletes_a_manual_announcement(): void
    {
        $system = AdminNotification::query()->where('system_key', 'guest_registration_prompt')->firstOrFail();
        $system->update(['is_active' => true]);
        Livewire::test(ListNotificationTemplates::class)->callTableAction('remove', $system)->assertNotified('تم إيقاف القالب');
        self::assertFalse($system->fresh()->is_active);
        self::assertSame('guest_registration_prompt', $system->fresh()->system_key);
        $manual = $this->template();
        $photo = $manual->allPhotos()->create(['type' => 'featured', 'path' => 'notifications/remove.png']);
        Livewire::test(ListNotificationTemplates::class)->callTableAction('remove', $manual)->assertNotified('تم حذف القالب');
        self::assertNull($manual->fresh());
        self::assertNull($photo->fresh());
    }

    public function test_confirmation_rejects_newer_copy_or_artwork_and_permissions_remain_restricted(): void
    {
        $record = $this->template();
        $page = Livewire::test(ListNotificationTemplates::class)->mountTableAction('remove', $record);
        $record->update(['title_ar' => 'التعديل الأحدث']);
        $page->callMountedTableAction()->assertNotified("تغيّر القالب منذ فتح الصفحة\nأعد تحميله قبل الإيقاف أو الحذف");
        self::assertSame('التعديل الأحدث', $record->fresh()->title_ar);
        $page = Livewire::test(ListNotificationTemplates::class)->mountTableAction('remove', $record->fresh());
        $record->allPhotos()->create(['type' => 'featured', 'path' => 'notifications/new.png']);
        $page->callMountedTableAction()->assertNotified("تغيّر القالب منذ فتح الصفحة\nأعد تحميله قبل الإيقاف أو الحذف");
        self::assertNotNull($record->fresh());
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListNotificationTemplates::class)->assertForbidden();
    }

    private function template(array $overrides = []): AdminNotification
    {
        return AdminNotification::query()->create(['surface' => 'announcement', 'title_ar' => 'إعلان يدوي', 'description_ar' => 'نص الإعلان',
            'priority' => 10, 'cooldown_hours' => 0, 'is_active' => true, ...$overrides]);
    }
}
