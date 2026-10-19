<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PathResource\Pages\CreatePath;
use App\Filament\Resources\PathResource\Pages\EditPath;
use App\Filament\Resources\TeacherResource\Pages\CreateTeacher;
use App\Models\Path;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentAuthoringDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = new User;
        $user->forceFill(['email' => 'drafts@example.test', 'role' => 'admin', 'active' => true])->save();
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_restore_keeps_the_create_intent_across_reload_and_does_not_write_until_validated(): void
    {
        $receipt = (string) Str::uuid();
        $draft = ['version' => '', 'receipt' => $receipt, 'values' => ['title_ar' => 'مسار مستعاد', 'title_en' => 'Recovered', 'interest_ids' => [], 'course_ids' => []]];
        $editor = Livewire::test(CreatePath::class)->call('restoreAuthoringDraft', $draft)
            ->assertReturned(['state' => 'restored'])
            ->assertSet('creationIntent', $receipt)->assertSet('data.title_ar', 'مسار مستعاد');
        self::assertSame(0, Path::query()->count());
        $editor->call('create')->assertHasNoFormErrors()->assertDispatched('authoring-draft-saved');
        self::assertSame(1, Path::query()->count());
        Livewire::test(CreatePath::class)->call('restoreAuthoringDraft', $draft)
            ->assertSet('data.title_ar', null);
        self::assertSame(1, Path::query()->count());
    }

    public function test_a_stale_draft_cannot_adopt_the_revision_loaded_on_a_clean_get(): void
    {
        $path = Path::query()->create(['title_ar' => 'الأصل', 'title_en' => 'Original']);
        $first = Livewire::test(EditPath::class, ['record' => $path->id]);
        $oldVersion = $first->get('editorVersion');
        $path->update(['title_ar' => 'تعديل محرر آخر']);
        Livewire::test(EditPath::class, ['record' => $path->id])->call('restoreAuthoringDraft', [
            'version' => $oldVersion, 'receipt' => (string) Str::uuid(), 'values' => ['title_ar' => 'مسودة قديمة'],
        ])->assertReturned(['state' => 'conflict'])->assertSet('data.title_ar', 'تعديل محرر آخر');
        self::assertSame('تعديل محرر آخر', $path->fresh()->title_ar);
    }

    public function test_restore_excludes_credentials_uploads_and_authorization_fields(): void
    {
        $editor = Livewire::test(CreateTeacher::class)->call('restoreAuthoringDraft', [
            'version' => '', 'receipt' => (string) Str::uuid(), 'values' => [
                'name_ar' => 'اسم مستعاد', 'email' => 'forged@example.test', 'password' => 'not-a-draft',
                'manage_credentials' => true, 'image' => 'livewire-file:forged', 'role' => 'admin',
            ],
        ])->assertSet('data.name_ar', 'اسم مستعاد')->assertSet('data.manage_credentials', false);
        self::assertEmpty($editor->get('data.password'));
        self::assertEmpty($editor->get('data.email'));
        self::assertEmpty($editor->get('data.image'));
    }

    public function test_invalid_receipts_are_rejected_without_restoring_input(): void
    {
        Livewire::test(CreatePath::class)->call('restoreAuthoringDraft', [
            'version' => '', 'receipt' => 'broken', 'values' => ['title_ar' => 'غير صالح'],
        ])->assertHasErrors('receipt')->assertSet('data.title_ar', null);
        self::assertSame(0, Path::query()->count());
    }

    public function test_http_normalized_empty_version_and_empty_selections_are_restorable(): void
    {
        Livewire::test(CreatePath::class)->call('restoreAuthoringDraft', [
            'version' => null, 'receipt' => (string) Str::uuid(),
            'values' => ['title_ar' => 'مسار', 'interest_ids' => [], 'course_ids' => []],
        ])->assertReturned(['state' => 'restored'])->assertSet('data.title_ar', 'مسار');
    }
}
