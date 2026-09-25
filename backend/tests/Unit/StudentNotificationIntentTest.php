<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StudentNotificationIntent;
use PHPUnit\Framework\TestCase;

final class StudentNotificationIntentTest extends TestCase
{
    public function test_authored_input_is_not_rendered_or_normalized_by_the_value_object(): void
    {
        $variables = ['coins' => 17];
        $intent = new StudentNotificationIntent(
            notificationType: 'coins_claimed', titleAr: 'عنوان', titleEn: 'Title',
            messageAr: '{coins} عملة', messageEn: '{coins} coins',
            deliveryKey: ' raw-key ', templateVariables: $variables
        );
        $variables['coins'] = 90;
        self::assertSame(['coins' => 17], $intent->templateVariables);
        self::assertSame(' raw-key ', $intent->deliveryKey);
        self::assertSame('{coins} عملة', $intent->messageAr);
        self::assertSame('{coins} coins', $intent->messageEn);
        self::assertNull($intent->link);
        self::assertNull($intent->imageUrl);
        self::assertNull($intent->notifiableType);
        self::assertNull($intent->notifiableId);
    }

    public function test_input_cannot_change_after_it_is_authored(): void
    {
        $intent = new StudentNotificationIntent(
            notificationType: 'service_notice', titleAr: 'عنوان', titleEn: 'Title',
            messageAr: 'رسالة', messageEn: 'Message'
        );
        self::assertSame([], $intent->templateVariables);
        $this->expectException(\Error::class);
        $intent->templateVariables['coins'] = 40;
    }
}
