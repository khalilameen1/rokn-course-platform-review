jest.mock('../src/constants/api', () => ({publicRequest: {}}));
jest.mock('../src/constants/helpers', () => ({}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'play',
}));
import {
  mapCourseCheckout,
  selectCheckoutPackage,
} from '../src/services/api/courseCheckout';

const data = {
  id: 'checkout-1',
  status: 'quoted',
  course_id: 3,
  access_plan_code: 'guided',
  course_revision: 4,
  expires_at: '2099-01-01T00:00:00Z',
  original_price: 500,
  discount_amount: 20,
  final_price: 480,
  allocation: {paid_coins: 400, reward_coins: 80},
  purchased_balance: 100,
  reward_balance: 200,
  deficit: 300,
  remaining_purchased_balance: 0,
  remaining_reward_balance: 120,
  recommended_packages: [],
  selected_package: null,
};
describe('authoritative course checkout contract', () => {
  it('keeps paid and rewarded value separate', () =>
    expect(mapCourseCheckout(data)).toMatchObject({
      paidCoins: 400,
      rewardCoins: 80,
      deficit: 300,
      remainingRewardCoins: 120,
    }));
  it.each([
    {...data, final_price: 499},
    {...data, allocation: {paid_coins: 401, reward_coins: 80}},
    {...data, purchased_balance: null},
    {...data, expires_at: ''},
  ])(
    'rejects malformed pricing rather than inventing missing values',
    malformed =>
      expect(() => mapCourseCheckout(malformed)).toThrow(
        'API_CONTRACT_INVALID',
      ),
  );
  it('chooses lowest actual cash price first and least extra credit on ties', () => {
    const base = {label: 'coins', displayPrice: 'EGP'};
    expect(
      selectCheckoutPackage(
        [
          {...base, id: '1', coins: 500, price: 30},
          {...base, id: '2', coins: 700, price: 20},
          {...base, id: '3', coins: 400, price: 20},
        ],
        380,
      )?.id,
    ).toBe('3');
  });
});
