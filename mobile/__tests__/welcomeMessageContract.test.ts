import {publicRequest} from '../src/constants/api';
jest.mock('../src/constants/api', () => ({publicRequest: {get: jest.fn()}}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'guest', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
import {getEngagementMessage} from '../src/services/api/engagement';

it('accepts the compact welcome copy with a separate dashboard-owned amount and no duplicate body', async () => {
  jest
    .mocked(publicRequest.get)
    .mockResolvedValue({
      data: {
        data: {
          id: '1',
          key: 'guest_registration_prompt',
          title_ar: 'حصلت على هدية ترحيبية',
          description_ar: '',
          action_label_ar: 'تسجيل الدخول',
          secondary_action_label_ar: 'تابع كزائر',
          coins: 83,
        },
      },
    });
  await expect(
    getEngagementMessage('guest_registration_prompt'),
  ).resolves.toMatchObject({coins: 83, description: ''});
});
it('keeps the body requirement on other engagement messages', async () => {
  jest
    .mocked(publicRequest.get)
    .mockResolvedValue({
      data: {
        data: {
          id: '1',
          key: 'coin_offer',
          title_ar: 'عرض',
          description_ar: '',
          action_label_ar: 'افتح',
          secondary_action_label_ar: 'لاحقًا',
          coins: 83,
        },
      },
    });
  await expect(getEngagementMessage('coin_offer')).resolves.toBeNull();
});
