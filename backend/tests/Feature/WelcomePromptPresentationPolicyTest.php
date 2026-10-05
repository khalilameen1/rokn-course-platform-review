<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Admin\AdminNotificationRequest;
use App\Models\AdminNotification;
use App\Models\RewardRule;
use App\Services\AdminNotificationTemplateAuthoringService;
use App\Support\NotificationTemplateEditorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WelcomePromptPresentationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_read_projects_effective_policy_without_rewriting_old_copy_or_reward(): void
    {
        $template = $this->welcome();
        $template->update([
            'surface' => 'announcement', 'is_dismissible' => false,
            'cooldown_hours' => 72, 'priority' => 88, 'link' => '/wallet',
            'title_ar' => 'هديتك الخاصة جاهزة', 'action_label_ar' => 'استلم هديتك',
            'secondary_action_label_ar' => 'أكمل التصفح',
        ]);
        RewardRule::query()->where('event_key', 'welcome_bonus')->update(['coins_amount' => 137]);
        $before = $template->fresh()->getAttributes();

        $this->getJson('/api/v1/engagement/messages/guest_registration_prompt')->assertOk()
            ->assertJsonPath('data.surface', 'guest_prompt')
            ->assertJsonPath('data.dismissible', true)
            ->assertJsonPath('data.cooldown_hours', 0)
            ->assertJsonPath('data.link', null)
            ->assertJsonPath('data.title_ar', 'هديتك الخاصة جاهزة')
            ->assertJsonPath('data.action_label_ar', 'استلم هديتك')
            ->assertJsonPath('data.secondary_action_label_ar', 'أكمل التصفح')
            ->assertJsonPath('data.coins', 137);

        self::assertSame($before, $template->fresh()->getAttributes());
        $this->assertDatabaseHas('reward_rules', ['event_key' => 'welcome_bonus', 'coins_amount' => 137]);
    }

    public function test_legacy_editor_payload_saves_without_a_login_deep_link(): void
    {
        $template = $this->welcome();
        $request = $this->request($template, [
            'surface' => 'retention', 'link' => '/login', 'priority' => 900,
            'cooldown_hours' => 72, 'is_dismissible' => false,
        ]);
        $request->validateResolved();
        $validated = $request->validated();
        foreach (AdminNotification::presentationOverrides('guest_registration_prompt') as $field => $value) {
            self::assertSame($value, $validated[$field]);
        }
        app(AdminNotificationTemplateAuthoringService::class)->update(
            (int) $template->id, $validated, $validated['editor_version'], null, false
        );
        self::assertSame('عنوان الترحيب المعدل', $template->fresh()->title_ar);
        self::assertSame('', $template->fresh()->description_ar);
        self::assertTrue($template->fresh()->is_dismissible);
        self::assertSame(0, $template->fresh()->cooldown_hours);
        self::assertNull($template->fresh()->link);
    }

    public function test_welcome_edit_without_submitted_system_key_keeps_the_locked_journey(): void
    {
        $template = $this->welcome();
        $request = $this->request($template);
        $request->request->remove('system_key');
        $request->validateResolved();
        app(AdminNotificationTemplateAuthoringService::class)->update(
            (int) $template->id, $request->validated(), $request->validated('editor_version'), null, false
        );
        self::assertSame('guest_registration_prompt', $template->fresh()->system_key);
        self::assertSame('guest_prompt', $template->fresh()->surface);
    }

    public function test_writer_uses_persisted_system_identity_not_the_submitted_key(): void
    {
        $writer = app(AdminNotificationTemplateAuthoringService::class);
        $welcome = $this->welcome();
        $writer->update((int) $welcome->id, [
            ...$this->input($welcome), 'system_key' => 'new_course',
            'surface' => 'retention', 'cooldown_hours' => 72, 'is_dismissible' => false,
            'link' => '/wallet',
        ], NotificationTemplateEditorVersion::for($welcome), null, false);
        self::assertSame('guest_registration_prompt', $welcome->fresh()->system_key);
        self::assertSame('guest_prompt', $welcome->fresh()->surface);
        self::assertSame(0, $welcome->fresh()->cooldown_hours);
        self::assertTrue($welcome->fresh()->is_dismissible);

        $other = AdminNotification::query()->where('system_key', 'coin_offer')->firstOrFail();
        $writer->update((int) $other->id, [
            ...$this->input($other), 'system_key' => 'guest_registration_prompt',
            'surface' => 'retention', 'cooldown_hours' => 48, 'priority' => 77,
            'is_dismissible' => false, 'link' => '/wallet', 'description_ar' => 'رسالة المهمة',
        ], NotificationTemplateEditorVersion::for($other), null, false);
        self::assertSame('coin_offer', $other->fresh()->system_key);
        self::assertSame('retention', $other->fresh()->surface);
        self::assertSame(48, $other->fresh()->cooldown_hours);
        self::assertSame(77, $other->fresh()->priority);
        self::assertFalse($other->fresh()->is_dismissible);
        self::assertSame('rokn://wallet', $other->fresh()->link);
    }

    public function test_active_welcome_requires_both_visible_button_labels(): void
    {
        foreach (['action_label_ar', 'secondary_action_label_ar'] as $field) {
            try {
                $this->request($this->welcome(), [$field => ''])->validateResolved();
                self::fail('Active welcome must require '.$field);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_other_template_options_and_link_validation_are_not_changed(): void
    {
        $other = AdminNotification::query()->where('system_key', 'coin_offer')->firstOrFail();
        $request = $this->request($other, [
            'surface' => 'retention', 'description_ar' => 'رسالة المهمة', 'link' => '/wallet',
            'cooldown_hours' => 48, 'priority' => 77, 'is_dismissible' => false,
        ]);
        $request->validateResolved();
        self::assertSame(48, $request->validated('cooldown_hours'));
        self::assertFalse($request->validated('is_dismissible'));
        try {
            $this->request($other, ['description_ar' => 'رسالة المهمة', 'link' => null])->validateResolved();
            self::fail('Generic action still requires its destination.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('link', $exception->errors());
        }
    }

    public function test_welcome_editor_has_only_honest_fixed_presentation_controls(): void
    {
        $welcome = $this->welcome();
        $html = view('admin.admin_notifications._form', [
            'admin_notification' => $welcome,
            'editorVersion' => NotificationTemplateEditorVersion::for($welcome),
            'errors' => new \Illuminate\Support\ViewErrorBag(),
        ])->render();
        self::assertStringContainsString('مرة واحدة عند أول زيارة على الجهاز', $html);
        self::assertStringContainsString('يمكن للزائر إغلاقها دائمًا', $html);
        self::assertStringContainsString('تسجيل الدخول لاستلام الهدية', $html);
        foreach (['id="cooldown_hours"', 'id="priority"', 'id="link"', '<select'] as $unusedControl) {
            self::assertStringNotContainsString($unusedControl, $html);
        }
        self::assertStringContainsString('name="is_dismissible" type="hidden" value="1"', $html);
        self::assertStringContainsString('name="image"', $html);
        self::assertStringContainsString('name="starts_at"', $html);
        self::assertStringContainsString('name="is_active"', $html);

        $other = AdminNotification::query()->where('system_key', 'coin_offer')->firstOrFail();
        $otherHtml = view('admin.admin_notifications._form', [
            'admin_notification' => $other, 'editorVersion' => NotificationTemplateEditorVersion::for($other),
            'errors' => new \Illuminate\Support\ViewErrorBag(),
        ])->render();
        self::assertStringContainsString('id="cooldown_hours"', $otherHtml);
        self::assertStringContainsString('id="link"', $otherHtml);
    }

    private function welcome(): AdminNotification
    {
        return AdminNotification::query()->where('system_key', 'guest_registration_prompt')->firstOrFail();
    }

    private function input(AdminNotification $template): array
    {
        return [
            'system_key' => $template->system_key, 'surface' => 'guest_prompt',
            'title_ar' => 'عنوان الترحيب المعدل', 'description_ar' => '',
            'action_label_ar' => 'تسجيل الدخول', 'secondary_action_label_ar' => 'تابع كزائر',
            'is_active' => true, 'editor_version' => NotificationTemplateEditorVersion::for($template),
        ];
    }

    private function request(AdminNotification $template, array $changes = []): AdminNotificationRequest
    {
        $request = AdminNotificationRequest::create('/dashboard/templates/'.$template->id, 'PATCH', [
            ...$this->input($template), ...$changes,
        ]);
        $route = new Route(['PATCH'], '/dashboard/templates/{admin_notification}', static fn () => null);
        $route->bind($request);
        $route->setParameter('admin_notification', $template);
        $request->setRouteResolver(static fn () => $route);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        return $request;
    }
}
