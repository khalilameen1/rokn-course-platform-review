const mockPost = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'user-1', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'play',
}));
jest.mock('../src/services/checkoutRouting', () => ({
  courseCheckoutTransport: {kind: 'native', apiChannel: 'google', channel: 'play'},
  checkoutPackageChannel: (channel: string) => {
    if (channel === 'direct') return 'direct';
    if (channel === 'google') return 'play';
    if (channel === 'apple') return 'appstore';
    throw new Error('API_CONTRACT_INVALID_COURSE_CHECKOUT');
  },
}));
import {
  mapCourseCheckout,
  selectCheckoutPackage,
  quoteCourseCheckout,
} from '../src/services/api/courseCheckout';

const data = {
  channel: 'google',
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
  beforeEach(() => {
    require('../src/services/checkoutRouting').courseCheckoutTransport.apiChannel = 'google';
    mockPost.mockReset();
  });
  it('negotiates exact funding and refuses a legacy whole-package response', async () => {
    require('../src/services/checkoutRouting').courseCheckoutTransport.apiChannel = 'direct';
    mockPost.mockResolvedValue({data: {success: true, status: 200, data: {...data, channel: 'direct'}}});
    await expect(quoteCourseCheckout({courseId: '3', planCode: 'guided', mode: 'purchase'}))
      .rejects.toThrow('API_UNSUPPORTED_EXACT_COURSE_FUNDING');
    expect(mockPost).toHaveBeenCalledWith('course-checkouts',
      expect.objectContaining({channel: 'direct', funding_mode: 'exact_shortfall'}));
  });
  it('maps exact direct funding from the issued quote instead of the full denomination', () => {
    const funding = {
      id: 5, coins: 300, price: 3.70, direct_price: 3.33,
      name_ar: 'الرصيد', funding_mode: 'exact_shortfall',
      pricing_basis: {coins: 900, price: 11.11, direct_price: 10},
      channels: {direct: true, google: false, apple: false}, store_products: {},
    };
    expect(mapCourseCheckout({...data, channel: 'direct', funding_mode: 'exact_shortfall',
      recommended_packages: [funding], selected_package: funding,
    })).toMatchObject({
      fundingMode: 'exact_shortfall', deficit: 300, rewardCoins: 80,
      selectedPackage: {id: '5', coins: 300, price: 3.33},
    });
    expect(() => mapCourseCheckout({...data, channel: 'direct', funding_mode: 'exact_shortfall',
      recommended_packages: [funding], selected_package: {...funding, coins: 900},
    })).toThrow('API_CONTRACT_INVALID_SELECTED_PACKAGE');
    expect(() => mapCourseCheckout({...data, funding_mode: 'exact_shortfall'}))
      .toThrow('API_CONTRACT_INVALID_COURSE_CHECKOUT');
  });
  it('maps a direct course package at its direct price without requiring a store product', () => {
    const quote = mapCourseCheckout({
      ...data,
      channel: 'direct',
      recommended_packages: [
        {
          id: 5,
          coins: 400,
          price: 100,
          direct_price: 90,
          name_ar: 'الرصيد',
          channels: {direct: true, google: false, apple: false},
          store_products: {},
        },
      ],
    });
    expect(quote.channel).toBe('direct');
    expect(quote.packages).toEqual([
      expect.objectContaining({id: '5', price: 90}),
    ]);
  });
  it('sends the required capability on both quotes including the store package binding', async () => {
    mockPost.mockResolvedValue({data: {success: true, status: 200, data}});
    await quoteCourseCheckout({
      courseId: '3',
      planCode: 'mentor',
      mode: 'upgrade',
      requiredFeature: 'chat',
    });
    await quoteCourseCheckout({
      courseId: '3',
      planCode: 'mentor',
      mode: 'upgrade',
      requiredFeature: 'chat',
      packageId: '5',
    });
    expect(mockPost).toHaveBeenNthCalledWith(
      1,
      'course-checkouts',
      expect.objectContaining({required_feature: 'chat', mode: 'upgrade'}),
    );
    expect(mockPost).toHaveBeenNthCalledWith(
      2,
      'course-checkouts',
      expect.objectContaining({required_feature: 'chat', package_id: 5}),
    );
  });
  it('keeps paid and rewarded value separate', () =>
    expect(mapCourseCheckout(data)).toMatchObject({
      paidCoins: 400,
      rewardCoins: 80,
      deficit: 300,
      remainingRewardCoins: 120,
    }));
  it.each([
    {...data, channel: undefined},
    {...data, channel: 'unknown'},
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
