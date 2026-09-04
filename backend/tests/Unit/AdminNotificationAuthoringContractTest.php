<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminNotificationAuthoringContractTest extends TestCase
{
    public function test_broadcast_form_exposes_the_complete_campaign_contract(): void
    {
        $form = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/notifications/create.blade.php');
        self::assertIsString($form);

        foreach (['user_id', 'title_ar', 'message_ar', 'image', 'action_label', 'action_link', 'audience', 'send_at'] as $field) {
            self::assertStringContainsString('name="'.$field.'"', $form, $field);
        }
    }

    public function test_campaign_list_does_not_expose_internal_codes_or_raw_deep_links(): void
    {
        $list = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/notifications/index.blade.php');
        self::assertIsString($list);
        self::assertStringNotContainsString('{{ $campaign->failure_code }}', $list);
        self::assertStringNotContainsString('<code>{{ $campaign->link', $list);
    }

    public function test_template_authoring_cannot_create_unwired_event_keys(): void
    {
        $request = file_get_contents(dirname(__DIR__, 2).'/app/Http/Requests/Admin/AdminNotificationRequest.php');
        $form = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/admin_notifications/_form.blade.php');

        self::assertIsString($request);
        self::assertIsString($form);
        self::assertStringContainsString('Rule::in(AdminNotification::SYSTEM_KEYS)', $request);
        self::assertStringContainsString('name="system_key" type="hidden" value=""', $form);
    }

    public function test_quiz_is_not_an_available_notification_family(): void
    {
        $model = file_get_contents(dirname(__DIR__, 2).'/app/Models/AdminNotification.php');
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Services/NotificationService.php');
        $job = file_get_contents(dirname(__DIR__, 2).'/app/Jobs/SendStudentNotification.php');

        self::assertIsString($model);
        self::assertIsString($service);
        self::assertIsString($job);
        self::assertStringNotContainsString("'new_quiz'", $model);
        self::assertStringNotContainsString('notifyNewQuiz', $service);
        self::assertStringNotContainsString("'new_quiz'", $job);
    }

    public function test_individual_and_group_authoring_share_one_campaign_controller(): void
    {
        $users = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Admin/UsersController.php');
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');
        $studentSurface = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/admin/users/partials/show/notification.blade.php'
        );

        self::assertIsString($users);
        self::assertIsString($routes);
        self::assertIsString($studentSurface);
        self::assertStringNotContainsString('function sendNotification', $users);
        self::assertStringNotContainsString('users.send_notification', $routes);
        self::assertStringContainsString("route('admin.notifications.create', ['user_id' => \$user->id])", $studentSurface);
    }

    public function test_campaign_and_template_lists_are_bounded_and_links_are_canonicalized(): void
    {
        $campaigns = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/AdminNotificationCampaignReadService.php'
        );
        $templates = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Admin/AdminNotificationsController.php'
        );
        $notificationService = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/NotificationService.php'
        );

        self::assertIsString($campaigns);
        self::assertIsString($templates);
        self::assertIsString($notificationService);
        self::assertStringContainsString('->paginate(30)', $campaigns);
        self::assertStringContainsString('->paginate(30)', $templates);
        self::assertStringContainsString("\$payload['link'] = RoknAppLink::normalize", $templates);
        self::assertStringNotContainsString('/Courses/', $notificationService);
    }

    public function test_manual_messages_cannot_be_saved_to_an_unread_surface_or_with_a_dead_action(): void
    {
        $request = file_get_contents(dirname(__DIR__, 2).'/app/Http/Requests/Admin/AdminNotificationRequest.php');
        $form = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/admin_notifications/_form.blade.php');
        $list = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/admin_notifications/index.blade.php');

        self::assertIsString($request);
        self::assertIsString($form);
        self::assertIsString($list);
        self::assertStringContainsString("\$systemKey === '' && \$surface !== 'announcement'", $request);
        self::assertStringContainsString('$hasLink && !$hasArabicAction', $request);
        self::assertStringContainsString('name="surface" type="hidden" value="announcement"', $form);
        self::assertStringContainsString('$notification->public_image_url', $list);
    }
}
