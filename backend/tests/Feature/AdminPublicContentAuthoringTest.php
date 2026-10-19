<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\PublicContent;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\About;
use App\Models\User;
use App\Services\AdminPublicContentAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The dashboard About/Privacy/Terms editor through its HTTP routes. */
final class AdminPublicContentAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_first_save_creates_the_single_document_with_untouched_texts_empty(): void
    {
        About::query()->delete();
        $admin = $this->admin();
        $version = app(AdminPublicContentAuthoringService::class)->editorState()['editorVersion'];

        $this->actingAs($admin, 'web')->get(route('admin.about'))->assertRedirect(PublicContent::getUrl(['tab' => 'about']));
        $this->patch(route('admin.abouts.update'), [
            'about_ar' => 'ركن منصة تعلم',
            'editor_version' => $version,
        ])->assertRedirect()->assertSessionHas('success');

        $about = About::query()->sole();
        self::assertSame('ركن منصة تعلم', $about->about_ar);
        self::assertSame('', $about->privacy_ar);
        self::assertSame('', $about->policy_en);
    }

    public function test_a_save_changes_only_the_submitted_text(): void
    {
        $before = About::query()->sole();
        $version = app(AdminPublicContentAuthoringService::class)->editorState()['editorVersion'];

        $this->actingAs($this->admin(), 'web')->patch(route('admin.abouts.update'), [
            'policy_en' => 'Updated terms',
            'editor_version' => $version,
        ])->assertRedirect()->assertSessionHas('success');

        $after = About::query()->sole();
        self::assertSame('Updated terms', $after->policy_en);
        self::assertSame($before->privacy_ar, $after->privacy_ar);
        self::assertSame($before->about_ar, $after->about_ar);
    }

    public function test_a_save_from_a_stale_editor_is_refused_without_overwriting(): void
    {
        $admin = $this->admin();
        $content = app(AdminPublicContentAuthoringService::class);
        $stale = $content->editorState()['editorVersion'];
        $this->actingAs($admin, 'web')->patch(route('admin.abouts.update'), [
            'privacy_ar' => 'سياسة الخصوصية الأولى',
            'editor_version' => $stale,
        ])->assertSessionHasNoErrors();
        $current = $content->editorState()['editorVersion'];
        self::assertNotSame($stale, $current);

        $this->patch(route('admin.abouts.update'), [
            'privacy_ar' => 'نسخة قديمة',
            'editor_version' => $stale,
        ])->assertRedirect()->assertSessionHasErrors('editor_version');
        self::assertSame('سياسة الخصوصية الأولى', About::query()->sole()->privacy_ar);

        $this->patch(route('admin.abouts.update'), [
            'privacy_ar' => 'سياسة الخصوصية الثانية',
            'editor_version' => $current,
        ])->assertSessionHasNoErrors();
        self::assertSame('سياسة الخصوصية الثانية', About::query()->sole()->privacy_ar);
        self::assertSame(1, About::query()->count());
    }

    private function admin(): User
    {
        return User::query()->forceCreate([
            'name' => 'Admin', 'email' => Str::uuid().'@rokn.test',
            'password' => 'not-used', 'role' => 'admin', 'active' => true,
        ]);
    }
}
