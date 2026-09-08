import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {CourseAccessPlan, WalletSnapshot} from '../src/services/roknApi';

const mockPost = jest.fn();
const mockOpenBrowser = jest.fn();
const mockGetWallet = jest.fn();
const mockQuote = jest.fn();
const mockPurchaseCourse = jest.fn();
const mockStorage = new Map<string, unknown>();
const mockNavigation = {setParams: jest.fn(), dispatch: jest.fn()};
let mockBoundary = {scope: 'learner', epoch: 1};
let mockBalance = 0;
let mockOrderRef = '';
let mockCase = 0;

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const {useEffect} = jest.requireActual('react');
    useEffect(effect, [effect]);
  },
  useIsFocused: () => true,
  useNavigation: () => mockNavigation,
  useRoute: () => ({params: {}}),
}));
jest.mock('expo-crypto', () => ({
  randomUUID: () => '11111111-1111-4111-8111-111111111111',
}));
jest.mock('expo-web-browser', () => ({
  openAuthSessionAsync: (...args: unknown[]) => mockOpenBrowser(...args),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'direct',
  CAN_START_EXTERNAL_CHECKOUT: true,
  CAN_START_NATIVE_CHECKOUT: false,
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
  getItem: async (key: string) => mockStorage.get(key) ?? null,
  saveItem: async (key: string, value: unknown) => {
    mockStorage.set(key, value);
    return true;
  },
  removeItem: async (key: string) => mockStorage.delete(key),
}));
jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: async () => undefined,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/navigation/checkoutReturn', () => ({
  savePendingCheckoutReturn: async () => undefined,
  claimPendingCheckoutReturn: async () => undefined,
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => true,
  getWallet: (...args: unknown[]) => mockGetWallet(...args),
  getCoinPackages: async () => [],
  getCoinTasks: async () => [],
  getCourseDetailsSnapshot: async () => ({
    course: {id: '3', owned: false, price: 900, accessPlans: []},
  }),
  isCourseUnavailableError: () => false,
  quoteCoursePurchase: (...args: unknown[]) => mockQuote(...args),
  purchaseCourse: (...args: unknown[]) => mockPurchaseCourse(...args),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/screens/wallet/walletCache', () => ({
  readWalletCache: async () => null,
  saveWalletCache: async () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(),
  mapCoursePayload: jest.fn(),
}));

import {
  openCoinCheckout,
  reconcilePendingCoinCheckout,
  subscribeCoinCheckoutCredits,
} from '../src/services/coinCheckout';
import {useWalletData} from '../src/screens/wallet/useWalletData';
import {useWalletCheckout} from '../src/screens/wallet/useWalletCheckout';
import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';
import {useCourseCheckout} from '../src/screens/CourseDetails/details/useCourseCheckout';

const coinPackage = {id: '7', coins: 600, price: 49, label: 'باقة'};
const plan: CourseAccessPlan = {
  code: 'guided',
  name: 'إرشاد',
  priceCoins: 600,
  minimumPaidCoins: 0,
  chatEnabled: false,
  chatMessageLimit: 0,
  projectFeedbackLevel: 'report',
  projectReportEnabled: true,
  projectOutputEnabled: true,
  certificateEnabled: true,
};
const snapshot = (): WalletSnapshot => ({
  balance: mockBalance,
  paidBalance: mockBalance,
  rewardBalance: 0,
  rewardContributionCap: 100,
  spendableBalance: mockBalance,
  spendPolicy: 'reward_first_then_paid',
  coinRules: [],
  transactions: mockBalance
    ? [{id: 'credit', amount: mockBalance, label: 'شحن الرصيد'}]
    : [],
});
const response = (data: Record<string, unknown>) => ({data: {data}});
const paidResponse = () =>
  response({
    status: 'approved',
    financial_status: 'settled',
    package: {coins: 600},
  });
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};

describe('checkout credit survives its initiating screen', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let wallet!: ReturnType<typeof useWalletData>;
  let checkout!: ReturnType<typeof useWalletCheckout>;
  let course!: ReturnType<typeof useCourseDetailsData>;
  let courseCheckout!: ReturnType<typeof useCourseCheckout>;
  let credit: jest.Mock;
  let unsubscribe: () => void;
  const Wallet = () => {
    wallet = useWalletData(mockBoundary.scope);
    checkout = useWalletCheckout(wallet);
    return null;
  };
  const Course = () => {
    course = useCourseDetailsData({
      courseId: '3',
      identityKey: mockBoundary.scope,
    });
    return null;
  };
  const CourseOwner = () => {
    course = useCourseDetailsData({
      courseId: '3',
      identityKey: mockBoundary.scope,
    });
    courseCheckout = useCourseCheckout({
      courseId: '3',
      identityKey: mockBoundary.scope,
      selectedPlan: plan,
      couponApplied: false,
      effectivePrice: 600,
      purchasePrice: 600,
      shortfall: 600,
      publishedRevision: 1,
      packages: [coinPackage],
      closePurchase: jest.fn(),
      invalidateCoupon: jest.fn(),
      replaceCouponQuote: jest.fn(),
      showConfirm: jest.fn(),
      showPlans: jest.fn(),
      showSuccess: jest.fn(),
      showTopup: jest.fn(),
      setNotice: jest.fn(),
      setOwned: course.course.setOwned,
      setPackages: course.commerce.setPackages,
      updateWallet: course.commerce.updateWallet,
      reload: course.course.reload,
    });
    return null;
  };
  const mount = async (element = <Wallet />) => {
    await act(async () => {
      renderer = TestRenderer.create(element);
    });
  };
  const removeScreen = async () => {
    await act(async () => renderer!.unmount());
    renderer = undefined;
  };
  const prepareCheckout = () => {
    const reconcile = deferred<ReturnType<typeof response>>();
    mockPost.mockImplementation(
      (endpoint: string, body?: {idempotency_key: string}) => {
        if (endpoint === 'payment/initiate') {
          return Promise.resolve(
            response({
              payment_url: 'https://checkout.kashier.io/session',
              order_ref: mockOrderRef,
              idempotency_key: body!.idempotency_key,
            }),
          );
        }
        if (endpoint === `payment/reconcile/${mockOrderRef}`)
          return reconcile.promise;
        throw new Error(`Unexpected mocked endpoint ${endpoint}`);
      },
    );
    return reconcile;
  };

  beforeEach(() => {
    jest.clearAllMocks();
    mockStorage.clear();
    mockBoundary = {scope: `learner-${++mockCase}`, epoch: mockCase};
    mockOrderRef = `PKG-OWNER-${mockCase}`;
    mockBalance = 0;
    mockGetWallet.mockImplementation(async () => snapshot());
    mockQuote.mockResolvedValue({
      courseRevision: 1,
      accessPlanCode: 'guided',
      originalPrice: 600,
      finalPrice: 600,
      discountAmount: 0,
      discountPercentage: 0,
      couponCode: '',
    });
    mockOpenBrowser.mockImplementation(async () => ({
      type: 'success',
      url: `rokn://payment-result?status=pending&order_ref=${mockOrderRef}&coins=0`,
    }));
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    credit = jest.fn();
    unsubscribe = subscribeCoinCheckoutCredits(credit);
  });
  afterEach(async () => {
    if (renderer) await removeScreen();
    unsubscribe();
    jest.restoreAllMocks();
  });

  it.each(['wallet', 'course'] as const)(
    'refreshes the replacement %s after the old Wallet is removed before paid reconciliation',
    async destination => {
      const reconcile = prepareCheckout();
      await mount();
      let operation!: Promise<void>;
      await act(async () => {
        operation = checkout.startCheckout(coinPackage);
      });
      expect(mockPost).toHaveBeenCalledWith(
        `payment/reconcile/${mockOrderRef}`,
      );
      // Runtime joins this actual checkout while the provider result is pending.
      const recovery = reconcilePendingCoinCheckout();
      await removeScreen();
      await mount(destination === 'wallet' ? <Wallet /> : <Course />);
      expect(mockGetWallet).toHaveBeenCalledTimes(2);
      expect(
        destination === 'wallet'
          ? wallet.wallet?.balance
          : course.commerce.balance,
      ).toBe(0);

      await act(async () => {
        mockBalance = 600;
        reconcile.resolve(paidResponse());
        await operation;
        await recovery;
      });
      expect(
        destination === 'wallet'
          ? wallet.wallet?.balance
          : course.commerce.balance,
      ).toBe(600);
      expect(mockGetWallet).toHaveBeenCalledTimes(3);
      if (destination === 'wallet')
        expect(wallet.wallet?.transactions).toHaveLength(1);
      expect(credit).toHaveBeenCalledTimes(1);
      expect(credit.mock.calls[0][1]).toBe(mockBoundary.scope);
      expect(mockOpenBrowser).toHaveBeenCalledTimes(1);
      expect(Alert.alert).not.toHaveBeenCalled();
      await act(async () => {
        await reconcilePendingCoinCheckout();
      });
      expect(credit).toHaveBeenCalledTimes(1);
      expect(mockGetWallet).toHaveBeenCalledTimes(3);
    },
  );

  it('notifies a course top-up intent once despite a joined caller and foreground recovery', async () => {
    const reconcile = prepareCheckout();
    await mount();
    const options = {
      returnTo: {
        name: 'CourseDetails' as const,
        params: {courseId: '3', openPurchase: true},
      },
    };
    const first = openCoinCheckout(coinPackage, options);
    const duplicate = openCoinCheckout(coinPackage, options);
    await act(async () => undefined);
    const recovery = reconcilePendingCoinCheckout();
    await act(async () => {
      mockBalance = 600;
      reconcile.resolve(paidResponse());
      await Promise.all([first, duplicate, recovery]);
    });
    expect(credit).toHaveBeenCalledTimes(1);
    expect(wallet.wallet?.balance).toBe(600);
    expect(mockGetWallet).toHaveBeenCalledTimes(2);
    expect(mockOpenBrowser).toHaveBeenCalledTimes(1);
  });

  it('refreshes a mounted initiating Wallet only once and retains its success notice', async () => {
    const reconcile = prepareCheckout();
    await mount();
    let operation!: Promise<void>;
    await act(async () => {
      operation = checkout.startCheckout(coinPackage);
    });
    expect(checkout.checkoutLoading).toBe(coinPackage.id);
    await act(async () => {
      mockBalance = 600;
      reconcile.resolve(paidResponse());
      await operation;
    });
    expect(credit).toHaveBeenCalledTimes(1);
    expect(mockGetWallet).toHaveBeenCalledTimes(2);
    expect(wallet.wallet?.balance).toBe(600);
    expect(checkout.checkoutLoading).toBeNull();
    expect(Alert.alert).toHaveBeenCalledTimes(1);
    expect(Alert.alert).toHaveBeenCalledWith(
      'تم شحن الرصيد',
      expect.any(String),
    );
  });

  it('bounds the mounted course to one credit reload and one exact purchase requote', async () => {
    const reconcile = prepareCheckout();
    await mount(<CourseOwner />);
    let operation!: Promise<void>;
    await act(async () => {
      operation = courseCheckout.buyCoins(coinPackage);
    });
    await act(async () => {
      mockBalance = 600;
      reconcile.resolve(paidResponse());
      await operation;
    });
    expect(credit).toHaveBeenCalledTimes(1);
    expect(course.commerce.balance).toBe(600);
    expect(mockQuote).toHaveBeenCalledTimes(1);
    // The credit subscriber refreshes course/revision/packages as well as its
    // wallet. Purchase separately verifies the exact wallet+quote to confirm.
    expect(mockGetWallet).toHaveBeenCalledTimes(3);
    expect(mockPurchaseCourse).not.toHaveBeenCalled();
    expect(courseCheckout.busy).toBe(false);
  });

  it('does not let a late pre-credit course read replace the confirmed balance', async () => {
    const reconcile = prepareCheckout();
    await mount(<CourseOwner />);
    const beforeCredit = snapshot();
    const oldWalletRead = deferred<WalletSnapshot>();
    mockGetWallet.mockReturnValueOnce(oldWalletRead.promise);
    await act(async () => {
      course.course.reload();
    });
    expect(mockGetWallet).toHaveBeenCalledTimes(2);
    let operation!: Promise<void>;
    await act(async () => {
      operation = courseCheckout.buyCoins(coinPackage);
    });
    await act(async () => {
      mockBalance = 600;
      reconcile.resolve(paidResponse());
      await operation;
    });
    expect(course.commerce.balance).toBe(600);
    await act(async () => {
      oldWalletRead.resolve(beforeCredit);
    });
    expect(course.commerce.balance).toBe(600);
    expect(mockGetWallet).toHaveBeenCalledTimes(4);
    expect(mockQuote).toHaveBeenCalledTimes(1);
    expect(mockPurchaseCourse).not.toHaveBeenCalled();
    expect(credit).toHaveBeenCalledTimes(1);
  });

  it.each(['pending', 'cancelled', 'failed'] as const)(
    'does not announce a credit for an actual %s checkout result',
    async outcome => {
      const reconcile = prepareCheckout();
      if (outcome === 'cancelled') {
        mockOpenBrowser.mockResolvedValue({type: 'cancel'});
        mockPost.mockImplementation(
          (endpoint: string, body?: {idempotency_key: string}) =>
            Promise.resolve(
              response(
                endpoint === 'payment/initiate'
                  ? {
                      payment_url: 'https://checkout.kashier.io/session',
                      order_ref: mockOrderRef,
                      idempotency_key: body!.idempotency_key,
                    }
                  : {
                      status: 'cancelled',
                      financial_status: 'pending',
                      coins_added: 0,
                    },
              ),
            ),
        );
      }
      await mount();
      let operation!: Promise<void>;
      await act(async () => {
        operation = checkout.startCheckout(coinPackage);
      });
      await act(async () => {
        if (outcome === 'pending') {
          reconcile.resolve(
            response({status: 'approved', financial_status: 'review_required'}),
          );
        } else if (outcome === 'failed') {
          reconcile.reject({
            response: {status: 403, data: {code: 'forbidden'}},
          });
        }
        await operation;
      });
      expect(credit).not.toHaveBeenCalled();
      expect(wallet.wallet?.balance).toBe(0);
      expect(checkout.checkoutLoading).toBeNull();
      expect(Alert.alert).toHaveBeenCalledTimes(1);
      expect(mockOpenBrowser).toHaveBeenCalledTimes(1);
    },
  );

  it.each(['another account', 'new session for the same account'])(
    'does not announce the old credit after switching to %s during reconciliation',
    async replacement => {
      const reconcile = prepareCheckout();
      await mount();
      let operation!: Promise<void>;
      await act(async () => {
        operation = checkout.startCheckout(coinPackage);
      });
      await removeScreen();
      mockBoundary = {
        scope:
          replacement === 'another account'
            ? `${mockBoundary.scope}-new`
            : mockBoundary.scope,
        epoch: mockBoundary.epoch + 1,
      };
      mockBalance = 25;
      await mount();
      await act(async () => {
        reconcile.resolve(paidResponse());
        await operation;
      });
      expect(credit).not.toHaveBeenCalled();
      expect(wallet.wallet?.balance).toBe(25);
      expect(mockGetWallet).toHaveBeenCalledTimes(2);
      expect(Alert.alert).not.toHaveBeenCalled();
      expect(checkout.checkoutLoading).toBeNull();
    },
  );

  it('does not repeat the credit event when a later explicit call receives the same paid order', async () => {
    mockPost.mockResolvedValue(
      response({
        checkout_state: 'paid',
        order_status: 'approved',
        financial_status: 'settled',
        order_ref: mockOrderRef,
        coins_added: 600,
      }),
    );
    await mount();
    await act(async () => {
      mockBalance = 600;
      await openCoinCheckout(coinPackage);
    });
    await act(async () => {
      await openCoinCheckout(coinPackage);
    });
    expect(credit).toHaveBeenCalledTimes(1);
    expect(mockGetWallet).toHaveBeenCalledTimes(2);
    expect(wallet.wallet?.balance).toBe(600);
    expect(mockOpenBrowser).not.toHaveBeenCalled();
  });
});
