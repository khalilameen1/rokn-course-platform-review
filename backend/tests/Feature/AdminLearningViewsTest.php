<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AppSettings;
use App\Filament\Pages\ArtworkSettings;
use App\Filament\Pages\PublicContent;
use App\Mail\AdminContact;
use App\Models\CoinEarningMethod;
use App\Models\Contact;
use App\Models\User;
use App\Services\PublicAppSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

final class AdminLearningViewsTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    public function test_contact_notification_email_has_no_embedded_styles(): void
    {
        $contact = Contact::query()->create([
            'name' => 'Learner', 'email' => 'mail-contact@rokn.test', 'phone' => '201000000000',
            'message' => 'رسالة للمتابعة', 'read' => false,
        ]);

        $html = (new AdminContact($contact))->render();

        self::assertStringContainsString('رسالة للمتابعة', $html);
        $this->assertNoInlineStyleAttributes($html, 'contact email');
        self::assertSame(0, $this->styleBlockCount($html));
    }

    public function test_legal_editor_contracts_are_preserved(): void
    {
        [$admin] = $this->records();
        foreach (['admin.privacy' => 'privacy', 'admin.policy' => 'policy'] as $page => $field) {
            $this->adminGet($admin, route($page))->assertRedirect(PublicContent::getUrl(['tab' => $field]));
        }
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $editor = Livewire::test(PublicContent::class);
        foreach (['privacy_ar', 'privacy_en', 'policy_ar', 'policy_en'] as $field) {
            $editor->assertFormFieldExists($field);
        }
        self::assertSame(64, strlen($editor->get('editorVersion')));
    }

    public function test_public_links_and_legal_copy_have_one_dashboard_editor(): void
    {
        [$admin] = $this->records();
        $this->adminGet($admin, route('admin.settings'))->assertRedirect(AppSettings::getUrl());
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $settings = Livewire::test(AppSettings::class);
        foreach (['facebook_url', 'instagram_url', 'tiktok_url', 'whatsapp_url', 'support_whatsapp_url'] as $field) {
            $settings->assertFormFieldExists($field);
        }

        $design = $this->dom((string) $this->adminGet($admin, ArtworkSettings::getUrl())->assertOk()->getContent());
        foreach (['technical_contact', 'policy_content_ar', 'policy_content_en'] as $retiredField) {
            self::assertSame(0, $design->query('//*[@name="'.$retiredField.'"]')->length, $retiredField);
        }

        $snapshot = app(PublicAppSettingsService::class)->snapshot('ar');
        foreach (['policy_content', 'about_us_url', 'privacy_policy_url'] as $retiredKey) {
            self::assertArrayNotHasKey($retiredKey, $snapshot);
        }
    }

    public function test_dead_standalone_location_prototypes_stay_removed(): void
    {
        foreach ([
            'lessons/location.html',
            // Never routed and needed a $providers variable no controller supplied.
            'pages/online.blade.php',
        ] as $view) {
            self::assertFileDoesNotExist(resource_path('views/admin/'.$view), $view.' is an unreferenced prototype.');
        }
    }

    /** @return array{User, CoinEarningMethod, Contact} */
    private function records(): array
    {
        $admin = $this->dashboardUser('admin');
        $method = CoinEarningMethod::query()->create([
            'title_ar' => 'زيارة واتساب', 'title_en' => 'Visit WhatsApp', 'coins_amount' => 5,
            'action_key' => 'visit_whatsapp_contract', 'action_url' => 'https://wa.me/201000000000',
            'requires_external_visit' => true, 'verification_delay_seconds' => 10, 'is_active' => true,
        ]);
        $contact = Contact::query()->create([
            'name' => 'Learner', 'email' => 'learning-contact@rokn.test', 'phone' => '201000000000',
            'message' => 'رسالة عادية', 'read' => false,
        ]);

        return [$admin, $method, $contact];
    }
}
