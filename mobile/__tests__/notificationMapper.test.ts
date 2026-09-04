import {mapNotification} from '../src/services/notificationMapper';

describe('production notification language boundary', () => {
  it('prefers explicit Arabic copy over generic and English fields', () => {
    const notification = mapNotification({
      id: 7,
      notification_type: 'wallet_credit',
      title: 'Generic title',
      title_en: 'English title',
      title_ar: 'عنوان عربي',
      message: 'Generic message',
      message_en: 'English message',
      message_ar: 'رسالة عربية',
      action_label_ar: 'افتح المحفظة',
    });

    expect(notification.title).toBe('عنوان عربي');
    expect(notification.description).toBe('رسالة عربية');
    expect(notification.tone).toBe('coins');
    expect(notification.kind).toBe('coin_reward');
    expect(notification.actionLabel).toBe('افتح المحفظة');
  });

  it('does not invent learner copy when the delivery contract is incomplete', () => {
    const notification = mapNotification({
      id: 'n-1',
      title: 'Fallback title',
      message: 'Fallback message',
    });

    expect(notification.title).toBe('');
    expect(notification.description).toBe('');
    expect(notification.actionLabel).toBe('');
  });

  it('maps a rich course campaign without changing its destination', () => {
    const notification = mapNotification({
      id: 'course-9',
      notification_type: 'enrolled_stalled',
      title_ar: 'الكورس متاح لك',
      message_ar: 'أكمل من مكانك',
      link: 'rokn://course/9/watch',
      course_image: 'https://cdn.rokn.app/course-9.jpg',
      action_label_ar: 'أكمل الكورس',
    });

    expect(notification.kind).toBe('continue_course');
    expect(notification.imageUrl).toBe('https://cdn.rokn.app/course-9.jpg');
    expect(notification.actionLabel).toBe('أكمل الكورس');
    expect(notification.link).toBe('rokn://course/9/watch');
  });

  it('does not invent a course destination when the delivery omits its link', () => {
    const notification = mapNotification({
      id: 'recommended-12',
      notification_type: 'course_recommendation',
      title_ar: 'قد يناسبك هذا الكورس',
      message_ar: 'شاهد التفاصيل واختر ما يناسبك',
      course_id: '12',
    });

    expect(notification.link).toBeUndefined();
  });

  it('does not infer a player destination from a broad notification kind', () => {
    const notification = mapNotification({
      id: 'continue-12',
      notification_type: 'continue_course',
      course_id: '12',
    });

    expect(notification.link).toBeUndefined();
  });

  it('keeps a ready certificate pointed at the certificates tab', () => {
    const notification = mapNotification({
      id: 'certificate-12',
      notification_type: 'certificate_ready',
      link: 'rokn://profile/certificates',
      action_label_ar: 'افتح الشهادة',
    });

    expect(notification.link).toBe('rokn://profile/certificates');
    expect(notification.actionLabel).toBe('افتح الشهادة');
  });

  it('understands current backend event names and upgrades a learning nudge to the player', () => {
    const reward = mapNotification({
      id: 'welcome',
      notification_type: 'coins_claimed',
      link: '/wallet',
      notifiable_type: 'App\\Models\\CoinEarningMethod',
      notifiable_id: 4,
    });
    const nudge = mapNotification({
      id: 'nudge',
      notification_type: 'learning_nudge',
      link: '/course/72/watch',
      notifiable_type: 'App\\Models\\Course',
      notifiable_id: 72,
    });

    expect(reward.kind).toBe('coin_reward');
    expect(reward.tone).toBe('coins');
    expect(reward.link).toBe('/wallet');
    expect(nudge.kind).toBe('continue_course');
    expect(nudge.courseId).toBe('72');
    expect(nudge.link).toBe('/course/72/watch');
  });

  it('never emits an unusable fallback from an unsafe course id', () => {
    const notification = mapNotification({
      id: 'unsafe',
      notification_type: 'course_recommendation',
      course_id: 'course 12',
    });

    expect(notification.courseId).toBeUndefined();
    expect(notification.link).toBeUndefined();
  });
});
