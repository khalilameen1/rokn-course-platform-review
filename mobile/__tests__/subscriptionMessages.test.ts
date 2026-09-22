import {Alert} from 'react-native';
import {
  subscriptionMessages,
  subscriptionMessageText,
} from '../src/constants/subscriptionMessages';
import {showPortfolioUploadGate} from '../src/components/portfolioUploadGate';

describe('approved subscription copy', () => {
  afterEach(() => jest.restoreAllMocks());

  it.each([
    [
      'chatSubscribe',
      'الشات يحتاج اشتراكًا',
      'اختر اشتراكًا يشمل الشات مع مدرب رُكن',
      'عرض الاشتراكات',
    ],
    [
      'chatUpgrade',
      'اشتراكك لا يشمل الشات',
      'قم بترقية اشتراكك وادفع فرق السعر فقط',
      'ترقية الاشتراك',
    ],
    [
      'chatExhausted',
      'استخدمت كل رسائلك',
      'قم بترقية اشتراكك للحصول على رسائل أكثر وادفع فرق السعر فقط',
      'ترقية الاشتراك',
    ],
    [
      'portfolioSubscribe',
      'لإضافة أعمالك',
      'اشترك في كورس باشتراك يشمل شهادة',
      'تصفح الكورسات',
    ],
    [
      'portfolioUpgrade',
      'لإضافة أعمالك',
      'قم بترقية اشتراكك إلى اشتراك يشمل شهادة',
      'عرض الاشتراكات',
    ],
    [
      'chatDailyLimit',
      'استخدمت رسائل اليوم',
      'يمكنك إرسال رسائل جديدة غدًا',
      'حسنًا',
    ],
  ])('keeps %s consistent across surfaces', (key, title, body, action) => {
    const message =
      subscriptionMessages[key as keyof typeof subscriptionMessages];
    expect(message).toEqual({title, body, action});
    expect(subscriptionMessageText(message)).toBe(`${title}\n${body}`);
  });

  it.each([true, false])(
    'routes a denied portfolio upload using the server subscription state %s',
    hasSubscription => {
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation(() => undefined);
      const events: string[] = [];
      const navigate = jest.fn(() => events.push('navigate'));
      const close = () => events.push('close');
      const message = hasSubscription
        ? subscriptionMessages.portfolioUpgrade
        : subscriptionMessages.portfolioSubscribe;
      expect(
        showPortfolioUploadGate(
          {
            status: 403,
            data: {
              code: 'PORTFOLIO_CERTIFICATE_SUBSCRIPTION_REQUIRED',
              has_subscription: hasSubscription,
            },
          },
          navigate,
          close,
        ),
      ).toBe(true);
      expect(alert).toHaveBeenCalledWith(
        message.title,
        message.body,
        expect.any(Array),
      );
      const buttons = alert.mock.calls[0][2]!;
      expect(buttons.map(button => button.text)).toEqual([
        'إغلاق',
        message.action,
      ]);
      buttons[1].onPress?.();
      expect(navigate).toHaveBeenCalledWith(hasSubscription);
      expect(events).toEqual(['close', 'navigate']);
    },
  );

  it('does not disguise an offline failure as a subscription restriction', () => {
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation(() => undefined);
    expect(showPortfolioUploadGate(new Error('offline'), jest.fn())).toBe(
      false,
    );
    expect(alert).not.toHaveBeenCalled();
  });
});
