<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AdminContact;
use App\Models\CoinEarningMethod;
use App\Models\Contact;
use App\Models\User;
use App\Services\PublicAppSettingsService;
use DOMXPath;
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

        foreach ([route('admin.privacy'), route('admin.policy'), route('admin.coin-earning-methods.index')] as $url) {
            $xpath = $this->dom((string) $this->adminGet($admin, $url)->assertOk()->getContent());
            self::assertSame(1, $xpath->query('//*[@class="admin-page__header"]//h1[@class="admin-page__title"]')->length, $url);
        }

        $index = $this->dom((string) $this->adminGet($admin, route('admin.coin-earning-methods.index'))->getContent());
        self::assertGreaterThan(0, $index->query('//span[contains(concat(" ", normalize-space(@class), " "), " admin-status ")]')->length);
    }

    public function test_coin_method_routes_and_reward_fields_are_preserved(): void
    {
        [$admin, $method] = $this->records();
        $index = $this->dom((string) $this->adminGet($admin, route('admin.coin-earning-methods.index'))->assertOk()->getContent());
        $create = $this->dom((string) $this->adminGet($admin, route('admin.coin-earning-methods.create'))->assertOk()->getContent());
        $edit = $this->dom((string) $this->adminGet($admin, route('admin.coin-earning-methods.edit', $method))->assertOk()->getContent());

        $settings = '//form[@action="'.route('admin.coin-earning-methods.update-settings').'"]';
        self::assertSame(1, $index->query($settings)->length);
        foreach (['how_to_use_coins_ar', 'how_to_use_coins_en', 'editor_version'] as $field) {
            self::assertGreaterThan(0, $index->query($settings.'//*[@name="'.$field.'"]')->length, $field);
        }
        self::assertGreaterThan(0, $index->query('//a[@href="'.route('admin.coin-earning-methods.create').'"]')->length);
        self::assertGreaterThan(0, $index->query('//a[@href="'.route('admin.coin-earning-methods.edit', $method->id).'"]')->length);
        $destroy = '//form[@action="'.route('admin.coin-earning-methods.destroy', $method->id).'"]';
        self::assertSame('DELETE', $index->evaluate('string('.$destroy.'//input[@name="_method"]/@value)'));
        self::assertSame(1, $index->query($destroy.'//input[@name="editor_version"]')->length);
        self::assertSame(
            'noopener noreferrer',
            $index->evaluate('string(//a[@href="https://wa.me/201000000000" and @target="_blank"]/@rel)')
        );

        $fields = ['title_ar', 'title_en', 'coins_amount', 'action_key', 'is_active', 'action_url',
            'requires_external_visit', 'verification_delay_seconds', 'sort_order'];
        $store = '//form[@action="'.route('admin.coin-earning-methods.store').'"]';
        $update = '//form[@action="'.route('admin.coin-earning-methods.update', $method->id).'"]';
        foreach ($fields as $field) {
            self::assertGreaterThan(0, $create->query($store.'//*[@name="'.$field.'"]')->length, 'create '.$field);
            self::assertGreaterThan(0, $edit->query($update.'//*[@name="'.$field.'"]')->length, 'edit '.$field);
        }
        self::assertSame('PUT', $edit->evaluate('string('.$update.'//input[@name="_method"]/@value)'));
        self::assertSame(1, $edit->query($update.'//input[@name="editor_version"]')->length);
        self::assertGreaterThan(0, $edit->query('//a[@href="'.route('admin.coin-earning-methods.index').'"]')->length);
    }

    public function test_contact_routes_workflow_fields_and_delete_guard_are_preserved(): void
    {
        [$admin, , $contact] = $this->records();
        $student = $this->dashboardUser('client', 'deleting-learner@rokn.test');
        $deletion = Contact::query()->create([
            'name' => 'Deleting learner', 'email' => 'deleting-learner@rokn.test', 'phone' => '-',
            'message' => '[ACCOUNT_DELETION_REQUEST] احذف حسابي', 'read' => false,
        ]);

        $index = $this->dom((string) $this->adminGet($admin, route('admin.contacts.index'))->assertOk()->getContent());
        foreach ([$contact, $deletion] as $row) {
            // "View details" marks the message read on its way to the details page.
            self::assertSame(1, $index->query('//form[@action="'.route('admin.contacts.read', $row->id).'"]//input[@name="editor_version"]')->length);
        }
        // Account-deletion requests are closed through the workflow, never deleted from the list.
        self::assertSame(1, $this->deleteForms($index, $contact));
        self::assertSame(0, $this->deleteForms($index, $deletion));

        $message = $this->dom((string) $this->adminGet($admin, route('admin.contacts.show', $contact))->assertOk()->getContent());
        $destroy = '//form[@action="'.route('admin.contacts.destroy', $contact).'"]';
        self::assertSame('DELETE', $message->evaluate('string('.$destroy.'//input[@name="_method"]/@value)'));
        self::assertStringStartsWith('return confirm(', $message->evaluate('string('.$destroy.'/@onsubmit)'));
        self::assertGreaterThan(0, $message->query('//a[@href="'.route('admin.contacts.index').'"]')->length);

        $request = $this->dom((string) $this->adminGet($admin, route('admin.contacts.show', $deletion))->assertOk()->getContent());
        $close = '//form[@action="'.route('admin.contacts.close-deletion-request', $deletion).'"]';
        foreach (['outcome', 'resolution_note', 'confirm_close'] as $field) {
            self::assertSame(1, $request->query($close.'//*[@name="'.$field.'"]')->length, $field);
        }
        foreach (['admin.contacts.read', 'admin.contacts.processing'] as $action) {
            self::assertGreaterThan(0, $request->query('//form[@action="'.route($action, $deletion).'"]')->length, $action);
        }
        self::assertGreaterThan(0, $request->query('//a[@href="'.route('admin.users.show', $student).'"]')->length);
        self::assertSame(0, $request->query('//form[@action="'.route('admin.contacts.destroy', $deletion).'"]')->length);
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
        $settings = $this->dom((string) $this->adminGet($admin, route('admin.settings'))->assertOk()->getContent());
        foreach (['facebook_url', 'instagram_url', 'tiktok_url', 'whatsapp_url', 'support_whatsapp_url'] as $field) {
            self::assertGreaterThan(0, $settings->query('//form//input[@name="'.$field.'"]')->length, $field);
        }

        $design = $this->dom((string) $this->adminGet($admin, route('admin.design-settings.index'))->assertOk()->getContent());
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
            'coin method list' => ['admin.coin-earning-methods.index', 'coins'],
            'coin method create' => ['admin.coin-earning-methods.create', 'coins'],
            'coin method edit' => ['admin.coin-earning-methods.edit', 'coins'],
            'contact list' => ['admin.contacts.index', 'contacts'],
            'contact details' => ['admin.contacts.show', 'contacts'],
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

    private function deleteForms(DOMXPath $index, Contact $contact): int
    {
        return $index->query(
            '//form[@action="'.route('admin.contacts.destroy', $contact).'"]//input[@name="_method" and @value="DELETE"]'
        )->length;
    }
}
