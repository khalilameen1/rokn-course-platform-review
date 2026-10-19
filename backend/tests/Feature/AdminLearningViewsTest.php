<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ArtworkSettings;
use App\Mail\AdminContact;
use App\Models\CoinEarningMethod;
use App\Models\Contact;
use App\Models\User;
use App\Services\PublicAppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

final class AdminLearningViewsTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    private const STYLESHEET = 'admin-learning-views.css';

    #[DataProvider('learningPages')]
    public function test_learning_pages_load_the_scoped_learning_stylesheet(string $page, string $scope): void
    {
        [$admin, $method, $contact] = $this->records();
        $url = match ($page) {
            'admin.coin-earning-methods.edit' => route($page, $method),
            'admin.contacts.show' => route($page, $contact),
            default => route($page),
        };
        $html = (string) $this->adminGet($admin, $url)->assertOk()->getContent();

        $this->assertNoInlineStyleAttributes($html, $page);
        self::assertSame(0, $this->styleBlockCount($html), $page);
        $this->assertLinksStylesheet($html, self::STYLESHEET, $page);
        self::assertGreaterThan(0, $this->dom($html)->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " admin-learning ")'
            .' and contains(concat(" ", normalize-space(@class), " "), " admin-learning--'.$scope.' ")]'
        )->length, $page);
        // The served stylesheet styles the scope this page renders.
        self::assertStringContainsString(
            '.admin-learning--'.$scope,
            (string) file_get_contents(public_path('admin/assets/css/'.self::STYLESHEET))
        );
    }

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

    public function test_shared_admin_partials_are_used_by_learning_screens(): void
    {
        [$admin, $method] = $this->records();

        foreach ([route('admin.privacy'), route('admin.policy')] as $url) {
            $xpath = $this->dom((string) $this->adminGet($admin, $url)->assertOk()->getContent());
            self::assertSame(1, $xpath->query('//*[@class="admin-page__header"]//h1[@class="admin-page__title"]')->length, $url);
        }

    }

    public function test_legal_editor_contracts_are_preserved(): void
    {
        [$admin] = $this->records();

        foreach (['admin.privacy' => 'privacy', 'admin.policy' => 'policy'] as $page => $field) {
            $xpath = $this->dom((string) $this->adminGet($admin, route($page))->assertOk()->getContent());
            $form = '//form[starts-with(@action, "'.route('admin.abouts.update').'")]';
            self::assertSame(1, $xpath->query($form)->length, $page);
            foreach ([$field.'_ar', $field.'_en', 'editor_version'] as $name) {
                self::assertSame(1, $xpath->query($form.'//*[@name="'.$name.'"]')->length, $page.' '.$name);
            }
        }
    }

    public function test_public_links_and_legal_copy_have_one_dashboard_editor(): void
    {
        [$admin] = $this->records();
        $this->adminGet($admin, route('admin.settings'))->assertRedirect(\App\Filament\Pages\AppSettings::getUrl());
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('operations'));
        $settings = \Livewire\Livewire::test(\App\Filament\Pages\AppSettings::class);
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

    /** @return array<string, array{string, string}> */
    public static function learningPages(): array
    {
        return [
            'privacy editor' => ['admin.privacy', 'legal'],
            'policy editor' => ['admin.policy', 'legal'],
        ];
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
