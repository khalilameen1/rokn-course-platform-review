<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\NotificationAudience;
use App\Support\NotificationCampaignIntent;
use DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationCampaignIntentTest extends TestCase
{
    public function test_audience_normalizes_recipient_identity_without_resolving_users(): void
    {
        $audience = new NotificationAudience(
            selector: NotificationAudience::ALL,
            userIds: [8, '3', 8, 0, -2],
            excludeUserIds: ['7', 2, 7]
        );
        self::assertSame([3, 8], $audience->userIds);
        self::assertSame([2, 7], $audience->excludeUserIds);
        self::assertTrue($audience->matchesRecipients([8, 3, 8], [7, 2]));
        self::assertFalse($audience->matchesRecipients([3], [7, 2]));
        self::assertFalse($audience->matchesRecipients([3, 8], [7]));
    }

    public function test_normalized_audience_limit_does_not_count_duplicates_or_invalid_ids(): void
    {
        $audience = new NotificationAudience(
            selector: NotificationAudience::ALL,
            userIds: array_merge(range(1, 500), [1, 2, 0, -1]),
            excludeUserIds: array_fill(0, 600, 1)
        );
        self::assertCount(500, $audience->userIds);
        self::assertSame([1], $audience->excludeUserIds);
    }

    public static function invalidAudiences(): array
    {
        return [
            'unknown selector' => ['unknown', null, [], []],
            'missing enrolled course' => ['enrolled', null, [], []],
            'missing non-enrolled course' => ['not_enrolled', null, [], []],
            'zero course' => ['all', 0, [], []],
            'negative course' => ['enrolled', -1, [], []],
            'too many recipients' => ['all', null, range(1, 501), []],
            'too many exclusions' => ['all', null, [], range(1, 501)],
        ];
    }

    #[DataProvider('invalidAudiences')]
    public function test_invalid_audience_cannot_reach_persistence(
        string $selector, ?int $courseId, array $userIds, array $exclusions
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        new NotificationAudience(
            selector: $selector, courseId: $courseId, userIds: $userIds, excludeUserIds: $exclusions
        );
    }

    public function test_course_selectors_remain_lazy_instead_of_collecting_enrollments(): void
    {
        foreach ([NotificationAudience::ALL, NotificationAudience::ENROLLED, NotificationAudience::NOT_ENROLLED] as $selector) {
            $audience = new NotificationAudience(selector: $selector, courseId: 53);
            self::assertSame($selector, $audience->selector);
            self::assertSame(53, $audience->courseId);
            self::assertSame([], $audience->userIds);
        }
    }

    public function test_identity_is_canonical_once_and_long_keys_are_not_truncated(): void
    {
        $short = $this->intent('  delivery:1  ');
        $longKey = str_repeat('a', 64) . 'b';
        self::assertSame('delivery:1', $short->deliveryKey);
        self::assertSame(hash('sha256', $longKey), $this->intent($longKey)->deliveryKey);
        self::assertNotSame($this->intent($longKey)->deliveryKey, $this->intent(substr($longKey, 0, 64) . 'c')->deliveryKey);
        $generated = $this->intent('');
        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $generated->deliveryKey);
        self::assertNotSame($generated->deliveryKey, $this->intent('')->deliveryKey);
    }

    public function test_intent_freezes_a_mutable_schedule_but_does_not_render_or_translate_authored_copy(): void
    {
        $date = new DateTime('2026-09-25 23:30:00', new \DateTimeZone('Africa/Cairo'));
        $intent = new NotificationCampaignIntent(
            notificationType: 'service_notice', deliveryKey: 'intent:1',
            audience: new NotificationAudience(selector: NotificationAudience::ALL),
            titleAr: 'خطوة جديدة', titleEn: 'A new step',
            messageAr: "الجزء الأول\nالجزء الثاني", messageEn: 'Different authored English copy',
            link: '/wallet', imageUrl: '  ', scheduledAt: $date, authoredBy: -1
        );
        $date->modify('+1 day');
        self::assertSame('2026-09-25 23:30:00', $intent->scheduledAt->format('Y-m-d H:i:s'));
        self::assertSame('Africa/Cairo', $intent->scheduledAt->getTimezone()->getName());
        self::assertSame('خطوة جديدة', $intent->titleAr);
        self::assertSame('A new step', $intent->titleEn);
        self::assertSame("الجزء الأول\nالجزء الثاني", $intent->messageAr);
        self::assertSame('Different authored English copy', $intent->messageEn);
        self::assertSame('/wallet', $intent->link);
        self::assertFalse($intent->hasExplicitImage());
        self::assertNull($intent->authoredBy);
    }

    public function test_recipient_arrays_cannot_be_mutated_after_validation(): void
    {
        $audience = new NotificationAudience(selector: NotificationAudience::ALL, userIds: [1]);
        $this->expectException(\Error::class);
        $audience->userIds[] = 2;
    }

    private function intent(string $key): NotificationCampaignIntent
    {
        return new NotificationCampaignIntent(
            notificationType: 'service_notice', deliveryKey: $key,
            audience: new NotificationAudience(selector: NotificationAudience::ALL),
            titleAr: 'تنبيه', titleEn: 'Notice', messageAr: 'رسالة', messageEn: 'Message'
        );
    }
}
