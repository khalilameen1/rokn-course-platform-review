import {publicRequest} from '../src/constants/api';
jest.mock('../src/constants/api', () => ({publicRequest: {get: jest.fn()}}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'learner-a', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
import {getCoursePurchaseExitOffer} from '../src/services/api/engagement';
import {assertAccountSessionBoundary} from '../src/constants/helpers';
const base = {
  id: '1',
  key: 'coin_offer',
  title_ar: 'عملات إضافية',
  description_ar: 'مهمة',
  action_label_ar: 'افتح المهمة',
  secondary_action_label_ar: 'لاحقًا',
  task_id: '8',
  campaign_key: 'coin-offer:8',
};
it('accepts only the requested course/plan and a positive integral benefit', async () => {
  jest.mocked(publicRequest.get).mockResolvedValue({
    data: {
      data: {
        ...base,
        purchase_exit: {
          course_id: '52',
          access_plan_code: 'basic',
          additional_discount_coins: 20,
        },
      },
    },
  });
  await expect(getCoursePurchaseExitOffer('52', 'basic')).resolves.toEqual({
    taskId: '8',
    additionalDiscountCoins: 20,
  });
  expect(publicRequest.get).toHaveBeenCalledWith('engagement/next', {
    params: {course_id: '52', access_plan_code: 'basic'},
  });
});
it.each([
  undefined,
  {
    course_id: 'other',
    access_plan_code: 'basic',
    additional_discount_coins: 20,
  },
  {course_id: '52', access_plan_code: 'guided', additional_discount_coins: 20},
  ...[0, -1, NaN, Infinity, 1.5, '20'].map(additional_discount_coins => ({
    course_id: '52',
    access_plan_code: 'basic',
    additional_discount_coins,
  })),
])('rejects a generic/old/malformed opportunity %o', async purchase_exit => {
  jest
    .mocked(publicRequest.get)
    .mockResolvedValue({data: {data: {...base, purchase_exit}}});
  await expect(getCoursePurchaseExitOffer('52', 'basic')).resolves.toBeNull();
});

it('rejects a response when the captured account boundary changes during the read', async () => {
  jest
    .mocked(publicRequest.get)
    .mockResolvedValue({
      data: {
        data: {
          ...base,
          purchase_exit: {
            course_id: '52',
            access_plan_code: 'basic',
            additional_discount_coins: 20,
          },
        },
      },
    });
  jest
    .mocked(assertAccountSessionBoundary)
    .mockImplementationOnce(() => undefined)
    .mockImplementationOnce(() => {
      throw new Error('ACCOUNT_SESSION_CHANGED');
    });
  await expect(getCoursePurchaseExitOffer('52', 'basic')).rejects.toThrow(
    'ACCOUNT_SESSION_CHANGED',
  );
});
