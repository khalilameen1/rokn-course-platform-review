<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminLearningViewsTest extends TestCase
{
    private const STYLESHEET = 'admin-learning-views.css';

    #[DataProvider('bladeViews')]
    public function test_interactive_learning_views_do_not_embed_presentation_styles(
        string $view,
        bool $loadsStylesheet
    ): void {
        $source = $this->viewSource($view);

        self::assertStringNotContainsString('<style', $source, $view);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $view);

        if ($loadsStylesheet) {
            self::assertStringContainsString(
                'admin/assets/css/'.self::STYLESHEET,
                $source,
                $view
            );
        }
    }

    public function test_learning_stylesheet_is_scoped_and_uses_shared_admin_tokens(): void
    {
        $css = $this->stylesheetSource(self::STYLESHEET);

        self::assertStringContainsString('.admin-learning {', $css);
        self::assertStringContainsString('.admin-learning--catalog', $css);
        self::assertStringContainsString('.admin-learning--coins', $css);
        self::assertStringContainsString('.admin-learning--contacts', $css);
        self::assertStringContainsString('.admin-learning--legal', $css);
        self::assertStringContainsString('.admin-learning--online', $css);

        foreach ([
            '--rokn-admin-primary',
            '--rokn-admin-primary-dark',
            '--rokn-admin-success',
            '--rokn-admin-warning',
            '--rokn-admin-danger',
            '--rokn-admin-surface',
            '--rokn-admin-surface-soft',
            '--rokn-admin-border',
            '--rokn-admin-radius',
            '--rokn-admin-shadow',
        ] as $token) {
            self::assertStringContainsString($token, $css);
        }

        self::assertStringNotContainsString('{{', $css);
        self::assertStringNotContainsString('<style', $css);
    }

    public function test_shared_admin_partials_are_used_by_learning_screens(): void
    {
        foreach ([
            'abouts/privacy.blade.php',
            'abouts/policy.blade.php',
        ] as $view) {
            self::assertStringContainsString(
                "@include('admin.partials.page-header'",
                $this->viewSource($view),
                $view
            );
        }

        self::assertStringContainsString(
            "@include('admin.partials.status-badge'",
            $this->viewSource('coin_earning_methods/index.blade.php')
        );
    }

    public function test_coin_method_routes_and_reward_fields_are_preserved(): void
    {
        $index = $this->viewSource('coin_earning_methods/index.blade.php');
        $create = $this->viewSource('coin_earning_methods/create.blade.php');
        $edit = $this->viewSource('coin_earning_methods/edit.blade.php');
        $form = $this->viewSource('coin_earning_methods/_form.blade.php');
        $all = $index.$create.$edit.$form;

        foreach ([
            "route('admin.coin-earning-methods.update-settings')",
            "route('admin.coin-earning-methods.create')",
            "route('admin.coin-earning-methods.store')",
            "route('admin.coin-earning-methods.edit'",
            "route('admin.coin-earning-methods.update'",
            "route('admin.coin-earning-methods.destroy'",
            "route('admin.coin-earning-methods.index')",
        ] as $route) {
            self::assertStringContainsString($route, $all);
        }

        foreach ([
            'how_to_use_coins_ar',
            'how_to_use_coins_en',
            'title_ar',
            'title_en',
            'coins_amount',
            'action_key',
            'is_active',
            'action_url',
            'requires_external_visit',
            'verification_delay_seconds',
            'sort_order',
        ] as $field) {
            self::assertStringContainsString("name=\"{$field}\"", $all);
        }

        self::assertStringContainsString("@method('DELETE')", $index);
        self::assertStringContainsString("@method('PUT')", $edit);
        self::assertStringContainsString("rel=\"noopener noreferrer\"", $index);
        self::assertStringContainsString('name="editor_version"', $index);
        self::assertStringContainsString('name="editor_version"', $edit);
    }

    public function test_contact_routes_workflow_fields_and_delete_guard_are_preserved(): void
    {
        $index = $this->viewSource('contacts/index.blade.php');
        $show = $this->viewSource('contacts/show.blade.php');
        $mail = $this->viewSource('contacts/send_email.blade.php');
        $all = $index.$show.$mail;

        foreach ([
            "route('admin.contacts.index')",
            "route('admin.contacts.show'",
            "route('admin.contacts.read'",
            "route('admin.contacts.processing'",
            "route('admin.contacts.close-deletion-request'",
            "route('admin.contacts.destroy'",
            "route('admin.users.show'",
        ] as $route) {
            self::assertStringContainsString($route, $all);
        }

        foreach (['outcome', 'resolution_note', 'confirm_close'] as $field) {
            self::assertStringContainsString("name=\"{$field}\"", $show);
        }

        self::assertStringContainsString('@unless($contact->isAccountDeletionRequest())', $index);
        self::assertStringContainsString("@method('DELETE')", $show);
        self::assertStringContainsString('onsubmit="return confirm(', $show);
    }

    public function test_legal_editor_and_online_map_contracts_are_preserved(): void
    {
        $privacy = $this->viewSource('abouts/privacy.blade.php');
        $policy = $this->viewSource('abouts/policy.blade.php');
        $online = $this->viewSource('pages/online.blade.php');

        self::assertStringContainsString("route('admin.abouts.update'", $privacy);
        self::assertStringContainsString("route('admin.abouts.update'", $policy);
        self::assertStringContainsString('name="privacy_ar"', $privacy);
        self::assertStringContainsString('name="privacy_en"', $privacy);
        self::assertStringContainsString('name="policy_ar"', $policy);
        self::assertStringContainsString('name="policy_en"', $policy);
        self::assertStringContainsString('name="editor_version"', $privacy);
        self::assertStringContainsString('name="editor_version"', $policy);

        self::assertStringContainsString('json_encode($providers)', $online);
        self::assertStringContainsString('function initMap()', $online);
        self::assertStringContainsString('navigator.geolocation.getCurrentPosition', $online);
        self::assertStringContainsString("config('services.google.maps_browser_key')", $online);
        self::assertStringContainsString('class="online-map__marker-label"', $online);
    }

    public function test_public_links_and_legal_copy_have_one_dashboard_editor(): void
    {
        $settings = $this->viewSource('settings/index.blade.php');
        $design = $this->viewSource('design-settings/index.blade.php');
        $publicSettings = file_get_contents(dirname(__DIR__, 2).'/app/Services/PublicAppSettingsService.php');
        self::assertIsString($publicSettings);

        foreach (['facebook_url', 'instagram_url', 'tiktok_url', 'whatsapp_url', 'support_whatsapp_url'] as $field) {
            self::assertStringContainsString($field, $settings);
        }
        foreach (['technical_contact', 'policy_content_ar', 'policy_content_en'] as $retiredField) {
            self::assertStringNotContainsString($retiredField, $design);
        }
        self::assertStringNotContainsString("'policy_content' =>", $publicSettings);
        self::assertStringNotContainsString("'about_us_url' =>", $publicSettings);
        self::assertStringNotContainsString("'privacy_policy_url' =>", $publicSettings);
    }

    public function test_dead_standalone_location_prototypes_stay_removed(): void
    {
        foreach ([
            'lessons/location.html',
        ] as $view) {
            self::assertFileDoesNotExist(
                dirname(__DIR__, 2).'/resources/views/admin/'.$view,
                $view.' is an unreferenced prototype; the live map belongs to pages/online.blade.php.'
            );
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function bladeViews(): array
    {
        return [
            'coin method list' => ['coin_earning_methods/index.blade.php', true],
            'coin method create' => ['coin_earning_methods/create.blade.php', true],
            'coin method edit' => ['coin_earning_methods/edit.blade.php', true],
            'contact list' => ['contacts/index.blade.php', true],
            'contact details' => ['contacts/show.blade.php', true],
            'contact notification email' => ['contacts/send_email.blade.php', false],
            'privacy editor' => ['abouts/privacy.blade.php', true],
            'policy editor' => ['abouts/policy.blade.php', true],
            'online users map' => ['pages/online.blade.php', true],
        ];
    }

    private function viewSource(string $view): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);

        self::assertNotFalse($source, $view);

        return $source;
    }

    private function stylesheetSource(string $stylesheet): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/css/'.$stylesheet);

        self::assertNotFalse($source, $stylesheet);

        return $source;
    }
}
