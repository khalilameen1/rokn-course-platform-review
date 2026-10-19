<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\PublicContent;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\About;
use App\Models\User;
use App\Services\ManagedPublicContentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentPublicContentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_read_and_saved_get_urls_do_not_create_a_document(): void
    {
        About::query()->delete();
        Livewire::test(PublicContent::class)->assertFormFieldExists('about_ar')->assertFormFieldExists('policy_en');
        self::assertSame(0, About::query()->count());
        foreach (['about', 'privacy', 'policy'] as $tab) {
            $this->get(route('admin.'.$tab))->assertRedirect(PublicContent::getUrl(['tab' => $tab]));
        }
        self::assertSame(0, About::query()->count());
    }

    public function test_first_save_supports_blank_translations_and_publishes_through_the_same_reader(): void
    {
        About::query()->delete();
        $page = Livewire::test(PublicContent::class)->fillForm(['privacy_ar' => 'سياسة الخصوصية المحررة', 'privacy_en' => null]);
        $version = $page->get('editorVersion');
        $page->call('save')->assertHasNoFormErrors()->assertNotified('تم حفظ النص المنشور');
        self::assertSame('', About::query()->sole()->privacy_en);
        self::assertSame('', About::query()->sole()->about_ar);
        self::assertNotSame($version, $page->get('editorVersion'));
        self::assertSame('سياسة الخصوصية المحررة', app(ManagedPublicContentService::class)->body('privacy', 'ar'));
        $page->fillForm(['policy_ar' => 'شروط الاستخدام المحررة'])->call('save')->assertHasNoFormErrors();
        self::assertSame('سياسة الخصوصية المحررة', About::query()->sole()->privacy_ar);
        self::assertSame('شروط الاستخدام المحررة', app(ManagedPublicContentService::class)->body('terms', 'ar'));
    }

    public function test_newer_content_refuses_a_stale_editor_and_preserves_all_other_documents(): void
    {
        $page = Livewire::test(PublicContent::class)->fillForm(['about_ar' => 'نص قديم']);
        About::query()->firstOrFail()->update(['privacy_ar' => 'سياسة أحدث']);
        $page->call('save')->assertNotified('تغيّر النص المنشور');
        self::assertSame('سياسة أحدث', About::query()->sole()->privacy_ar);
        self::assertNotSame('نص قديم', About::query()->sole()->about_ar);
    }

    public function test_long_text_is_a_visible_validation_error(): void
    {
        Livewire::test(PublicContent::class)->fillForm(['about_ar' => str_repeat('a', 100001)])->call('save')->assertHasFormErrors(['about_ar' => 'max']);
    }

    public function test_moderator_cannot_read_or_save_public_policies(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        $this->get(PublicContent::getUrl())->assertForbidden();
    }
}
