import React from 'react';
import {AppState} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockPost = jest.fn();
const mockGet = jest.fn();
const mockCaptureBoundary = jest.fn();
const mockPackages = jest.fn();
const mockTasks = jest.fn();
const mockQuote = jest.fn();
const mockPurchase = jest.fn();
let mockBoundary = {scope: 'learner', epoch: 1};
let mockBalance = 20;

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    jest.requireActual('react').useEffect(effect, [effect]);
  },
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    post: (...args: unknown[]) => mockPost(...args),
    get: (...args: unknown[]) => mockGet(...args),
  },
}));
jest.mock('../src/constants/distribution', () => ({
  IS_STORE_DISTRIBUTION: false,
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: (...args: unknown[]) =>
    mockCaptureBoundary(...args),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/walletEconomy'),
  hasSession: async () => true,
  getCoinPackages: (...args: unknown[]) => mockPackages(...args),
  getCoinTasks: (...args: unknown[]) => mockTasks(...args),
  quoteCoursePurchase: (...args: unknown[]) => mockQuote(...args),
  purchaseCourse: (...args: unknown[]) => mockPurchase(...args),
  getCourseDetailsSnapshot: async () => ({
    course: {id: '3', owned: false, price: 900, accessPlans: []},
  }),
  isCourseUnavailableError: () => false,
}));
jest.mock('../src/services/api/engagement', () => ({
  getEngagementMessage: jest.fn(async () => null),
  getNextEngagementMessage: jest.fn(async () => null),
}));
jest.mock('../src/services/pendingWelcomeBonus', () => ({
  getPendingWelcomeBonus: async () => null,
  clearPendingWelcomeBonus: async () => true,
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/screens/wallet/walletCache', () => ({
  readWalletCache: async () => null,
  saveWalletCache: async () => true,
}));
jest.mock('../src/services/coinCheckout', () => ({
  subscribeCoinCheckoutCredits: jest.requireActual(
    '../src/services/coinCheckoutCoordinator',
  ).subscribeCoinCheckoutCredits,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(),
  mapCoursePayload: jest.fn(),
}));

import {useHomeEngagement} from '../src/screens/home/useHomeEngagement';
import {useWalletData} from '../src/screens/wallet/useWalletData';
import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';
import {useCourseCheckout} from '../src/screens/CourseDetails/details/useCourseCheckout';
import {claimDailyReward, getWallet} from '../src/services/api/walletEconomy';
import {subscribeWalletSettlements} from '../src/services/walletSettlement';
import * as walletSettlements from '../src/services/walletSettlement';

const response = (data: Record<string, unknown>) => ({data: {data}});
const walletResponse = () =>
  response({
    total_balance: mockBalance,
    purchased_balance: 0,
    reward_balance: mockBalance,
    course_spendable_balance: mockBalance,
    reward_contribution_cap_per_course: 1200,
    spend_policy: 'reward_first_then_paid',
    coin_rules: [],
    recent_transactions:
      mockBalance > 20
        ? [
            {
              id: 'daily-credit',
              direction: 'credit',
              amount: 7,
              label_ar: 'مكافأة يومية',
            },
          ]
        : [],
  });
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};

describe('daily reward settlement reaches the visible wallet', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let wallet!: ReturnType<typeof useWalletData>;
  let course!: ReturnType<typeof useCourseDetailsData>;
  let checkout!: ReturnType<typeof useCourseCheckout>;
  let stopObserving: (() => void) | undefined;
  const Home = () => {
    useHomeEngagement({
      active: true,
      identityKey: mockBoundary.scope,
      loading: true,
      navigation: {} as never,
      openCourse: () => false,
      remoteCourses: [],
      serverSession: true,
    });
    return null;
  };
  const Wallet = () => {
    wallet = useWalletData(mockBoundary.scope);
    return null;
  };
  const Course = () => {
    course = useCourseDetailsData({
      courseId: '3',
      identityKey: mockBoundary.scope,
    });
    checkout = useCourseCheckout({
      courseId: '3',
      identityKey: mockBoundary.scope,
      selectedPlan: {
        code: 'guided',
        name: 'إرشاد',
        priceCoins: 900,
        minimumPaidCoins: 0,
        chatEnabled: false,
        chatMessageLimit: 0,
        projectFeedbackLevel: 'report',
        projectReportEnabled: true,
        projectOutputEnabled: true,
        certificateEnabled: true,
      },
      couponApplied: false,
      effectivePrice: 900,
      purchasePrice: 900,
      shortfall: 880,
      publishedRevision: 1,
      packages: [],
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
  beforeEach(() => {
    jest.resetAllMocks();
    mockBoundary = {scope: 'learner', epoch: 1};
    mockBalance = 20;
    mockPackages.mockResolvedValue([]);
    mockTasks.mockResolvedValue([]);
    mockQuote.mockResolvedValue({
      couponCode: '',
      originalPrice: 900,
      finalPrice: 900,
      courseRevision: 1,
    });
    mockCaptureBoundary.mockImplementation(async () => ({...mockBoundary}));
    mockGet.mockImplementation(async (endpoint: string) => {
      expect(endpoint).toBe('wallet');
      return walletResponse();
    });
    jest
      .spyOn(AppState, 'addEventListener')
      .mockReturnValue({remove: jest.fn()});
    AppState.currentState = 'active';
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    stopObserving?.();
    stopObserving = undefined;
    jest.restoreAllMocks();
  });

  it.each(['before', 'after'])(
    'updates after daily flight registers %s the Wallet GET without blocking initial balance',
    async registrationOrder => {
      const credit = deferred<ReturnType<typeof response>>();
      const ownerCapture = deferred<typeof mockBoundary>();
      mockPost.mockReturnValue(credit.promise);
      if (registrationOrder === 'after') {
        // Boundary capture hashes the signed-in owner using an async native
        // operation. The first Home effect can still be waiting when we leave.
        mockCaptureBoundary.mockReturnValueOnce(ownerCapture.promise);
      }
      await act(async () => {
        renderer = TestRenderer.create(<Home />);
      });
      expect(mockPost).toHaveBeenCalledTimes(
        registrationOrder === 'before' ? 1 : 0,
      );
      await act(async () => renderer!.update(<Wallet />));
      expect(wallet.walletStatus).toBe('ready');
      expect(wallet.wallet?.balance).toBe(20);
      expect(mockGet).toHaveBeenCalledTimes(1);

      if (registrationOrder === 'after') {
        await act(async () => ownerCapture.resolve({...mockBoundary}));
      }
      expect(mockPost).toHaveBeenCalledWith('rewards/daily');
      await act(async () => {
        // The server commits the daily ledger credit only after the first
        // Wallet GET. No navigation or foreground event follows this ACK.
        mockBalance = 27;
        credit.resolve(response({awarded: 7, balance: 27, reward_balance: 27}));
      });
      expect(wallet.wallet?.balance).toBe(27);
      expect(wallet.wallet?.transactions).toEqual([
        {id: 'daily-credit', amount: 7, label: 'مكافأة يومية'},
      ]);
      expect(mockGet).toHaveBeenCalledTimes(2);
      expect(mockPost).toHaveBeenCalledTimes(1);
    },
  );

  it('updates course purchase balance when the Home daily credit settles after entry', async () => {
    const credit = deferred<ReturnType<typeof response>>();
    mockPost.mockReturnValue(credit.promise);
    await act(async () => {
      renderer = TestRenderer.create(<Home />);
    });
    await act(async () => renderer!.update(<Course />));
    expect(course.commerce.balance).toBe(20);
    expect(course.commerce.loading).toBe(false);
    expect(mockGet).toHaveBeenCalledTimes(1);

    await act(async () => {
      mockBalance = 27;
      credit.resolve(response({awarded: 7, balance: 27, reward_balance: 27}));
    });
    expect(course.commerce.balance).toBe(27);
    expect(course.commerce.rewardBalance).toBe(27);
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockPurchase).not.toHaveBeenCalled();
  });

  it('does not let an older imperative requote wallet read replace the settled balance', async () => {
    const credit = deferred<ReturnType<typeof response>>();
    const oldWallet = deferred<ReturnType<typeof response>>();
    const preCreditWallet = walletResponse();
    mockPost.mockReturnValue(credit.promise);
    await act(async () => {
      renderer = TestRenderer.create(<Home />);
    });
    await act(async () => renderer!.update(<Course />));
    mockGet.mockReturnValueOnce(oldWallet.promise);
    let reviewing!: Promise<void>;
    await act(async () => {
      reviewing = checkout.reviewAfterCredit();
    });
    expect(mockGet).toHaveBeenCalledTimes(2);
    await act(async () => {
      mockBalance = 27;
      credit.resolve(response({awarded: 7, balance: 27, reward_balance: 27}));
    });
    expect(course.commerce.balance).toBe(27);
    await act(async () => {
      oldWallet.resolve(preCreditWallet);
      await reviewing;
    });
    expect(course.commerce.balance).toBe(27);
    expect(mockPurchase).not.toHaveBeenCalled();
  });

  it('emits once for joined daily callers and queues one fresh wallet/tasks read behind packages', async () => {
    const credit = deferred<ReturnType<typeof response>>();
    const packages = deferred<[]>();
    const observed = jest.fn();
    stopObserving = subscribeWalletSettlements(observed);
    mockPost.mockReturnValue(credit.promise);
    mockPackages.mockReturnValueOnce(packages.promise);
    await act(async () => {
      renderer = TestRenderer.create(<Home />);
    });
    await act(async () => renderer!.update(<Wallet />));
    expect(wallet.wallet?.balance).toBe(20);
    let firstJoin!: ReturnType<typeof claimDailyReward>;
    let secondJoin!: ReturnType<typeof claimDailyReward>;
    await act(async () => {
      firstJoin = claimDailyReward({...mockBoundary});
      secondJoin = claimDailyReward({...mockBoundary});
    });
    await act(async () => {
      mockBalance = 27;
      credit.resolve(response({awarded: 7, balance: 27, reward_balance: 27}));
      await Promise.all([firstJoin, secondJoin]);
    });
    expect(observed).toHaveBeenCalledTimes(1);
    expect(observed).toHaveBeenCalledWith(mockBoundary);
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockGet).toHaveBeenCalledTimes(1);
    await act(async () => packages.resolve([]));
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(mockTasks).toHaveBeenCalledTimes(2);
    expect(wallet.wallet?.balance).toBe(27);
  });

  it.each(['transport', 'contract'])(
    'does not announce or refresh an unconfirmed daily result (%s)',
    async failure => {
      const credit = deferred<ReturnType<typeof response>>();
      const observed = jest.fn();
      stopObserving = subscribeWalletSettlements(observed);
      mockPost.mockReturnValue(credit.promise);
      await act(async () => {
        renderer = TestRenderer.create(<Home />);
      });
      await act(async () => renderer!.update(<Wallet />));
      await act(async () => {
        if (failure === 'transport') credit.reject(new Error('lost response'));
        else credit.resolve(response({awarded: 7, balance: 'invalid'}));
      });
      expect(observed).not.toHaveBeenCalled();
      expect(mockGet).toHaveBeenCalledTimes(1);
      expect(wallet.wallet?.balance).toBe(20);
      expect(wallet.walletStatus).toBe('ready');
    },
  );

  it('refreshes on a successful replay with zero new award after the first credit ACK was lost', async () => {
    const firstAttempt = deferred<ReturnType<typeof response>>();
    mockPost.mockReturnValueOnce(firstAttempt.promise);
    await act(async () => {
      renderer = TestRenderer.create(<Home />);
    });
    await act(async () => renderer!.update(<Wallet />));
    await act(async () => {
      mockBalance = 27;
      firstAttempt.reject(new Error('lost credited response'));
    });
    expect(wallet.wallet?.balance).toBe(20);
    mockPost.mockResolvedValueOnce(
      response({awarded: 0, balance: 27, reward_balance: 27}),
    );
    await act(async () => {
      await claimDailyReward({...mockBoundary});
    });
    expect(wallet.wallet?.balance).toBe(27);
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(mockPost).toHaveBeenCalledTimes(2);
  });

  it.each(['learner', 'replacement'])(
    'does not notify or refresh a replacement session for the old credit (%s)',
    async scope => {
      const credit = deferred<ReturnType<typeof response>>();
      const observed = jest.fn();
      stopObserving = subscribeWalletSettlements(observed);
      mockPost.mockReturnValue(credit.promise);
      await act(async () => {
        renderer = TestRenderer.create(<Home />);
      });
      mockBoundary = {scope, epoch: 2};
      mockBalance = 50;
      await act(async () => renderer!.update(<Wallet />));
      expect(wallet.wallet?.balance).toBe(50);
      await act(async () => {
        credit.resolve(response({awarded: 7, balance: 27, reward_balance: 27}));
      });
      expect(observed).not.toHaveBeenCalled();
      expect(mockGet).toHaveBeenCalledTimes(1);
      expect(wallet.wallet?.balance).toBe(50);
    },
  );

  it('keeps the committed result successful if an observer throws and no screen remains', async () => {
    const credit = deferred<ReturnType<typeof response>>();
    mockPost.mockReturnValue(credit.promise);
    await act(async () => {
      renderer = TestRenderer.create(<Home />);
    });
    await act(async () => renderer!.update(<Wallet />));
    await act(async () => renderer!.unmount());
    renderer = undefined;
    stopObserving = subscribeWalletSettlements(() => {
      throw new Error('observer failed');
    });
    const joined = claimDailyReward({...mockBoundary});
    await act(async () => {
      mockBalance = 27;
      credit.resolve(response({awarded: 7, balance: 27, reward_balance: 27}));
      await expect(joined).resolves.toMatchObject({awarded: 7, balance: 27});
    });
    expect(mockGet).toHaveBeenCalledTimes(1);
    await act(async () => {
      renderer = TestRenderer.create(<Wallet />);
    });
    expect(wallet.wallet?.balance).toBe(27);
    expect(mockGet).toHaveBeenCalledTimes(2);
  });

  it('keeps ordinary wallet reads to one GET and removes their settlement listener', async () => {
    const realSubscribe = walletSettlements.subscribeWalletSettlements;
    const removed = jest.fn();
    jest
      .spyOn(walletSettlements, 'subscribeWalletSettlements')
      .mockImplementation(listener => {
        const remove = realSubscribe(listener);
        return () => {
          removed();
          remove();
        };
      });
    await expect(getWallet()).resolves.toMatchObject({balance: 20});
    expect(mockGet).toHaveBeenCalledTimes(1);
    expect(removed).toHaveBeenCalledTimes(1);
    walletSettlements.notifyWalletSettlement({...mockBoundary});
    expect(mockGet).toHaveBeenCalledTimes(1);
  });

  it('retries an overtaken GET once and returns the authoritative lower balance too', async () => {
    const oldRead = deferred<ReturnType<typeof response>>();
    const oldResponse = walletResponse();
    mockGet.mockReturnValueOnce(oldRead.promise);
    let reading!: ReturnType<typeof getWallet>;
    await act(async () => {
      reading = getWallet();
    });
    mockBalance = 10;
    walletSettlements.notifyWalletSettlement({...mockBoundary});
    oldRead.resolve(oldResponse);
    await expect(reading).resolves.toMatchObject({balance: 10});
    expect(mockGet).toHaveBeenCalledTimes(2);
  });

  it.each(['initial', 'retry'])(
    'does not return an old balance when the %s GET fails and always removes its listener',
    async failureAt => {
      const realSubscribe = walletSettlements.subscribeWalletSettlements;
      const removed = jest.fn();
      jest
        .spyOn(walletSettlements, 'subscribeWalletSettlements')
        .mockImplementation(listener => {
          const remove = realSubscribe(listener);
          return () => {
            removed();
            remove();
          };
        });
      const oldRead = deferred<ReturnType<typeof response>>();
      mockGet
        .mockReturnValueOnce(oldRead.promise)
        .mockRejectedValueOnce(new Error('wallet offline'));
      let reading!: ReturnType<typeof getWallet>;
      await act(async () => {
        reading = getWallet();
      });
      const outcome = reading.catch(error => error);
      if (failureAt === 'retry') {
        walletSettlements.notifyWalletSettlement({...mockBoundary});
        oldRead.resolve(walletResponse());
      } else {
        oldRead.reject(new Error('wallet offline'));
      }
      await expect(outcome).resolves.toMatchObject({message: 'wallet offline'});
      expect(mockGet).toHaveBeenCalledTimes(failureAt === 'retry' ? 2 : 1);
      expect(removed).toHaveBeenCalledTimes(1);
      walletSettlements.notifyWalletSettlement({...mockBoundary});
      expect(removed).toHaveBeenCalledTimes(1);
    },
  );

  it('does not retry an overtaken old-owner read after account replacement', async () => {
    const oldRead = deferred<ReturnType<typeof response>>();
    mockGet.mockReturnValueOnce(oldRead.promise);
    let reading!: ReturnType<typeof getWallet>;
    await act(async () => {
      reading = getWallet();
    });
    const outcome = reading.catch(error => error);
    walletSettlements.notifyWalletSettlement({...mockBoundary});
    mockBoundary = {scope: 'replacement', epoch: 2};
    oldRead.resolve(walletResponse());
    await expect(outcome).resolves.toMatchObject({
      message: 'ACCOUNT_CHANGED_DURING_REQUEST',
    });
    expect(mockGet).toHaveBeenCalledTimes(1);
  });

  it('bounds repeated overtaking to two GETs without returning a stale snapshot', async () => {
    const firstRead = deferred<ReturnType<typeof response>>();
    const retry = deferred<ReturnType<typeof response>>();
    mockGet
      .mockReturnValueOnce(firstRead.promise)
      .mockReturnValueOnce(retry.promise);
    let reading!: ReturnType<typeof getWallet>;
    await act(async () => {
      reading = getWallet();
    });
    const outcome = reading.catch(error => error);
    await act(async () => {
      walletSettlements.notifyWalletSettlement({...mockBoundary});
      firstRead.resolve(walletResponse());
    });
    expect(mockGet).toHaveBeenCalledTimes(2);
    walletSettlements.notifyWalletSettlement({...mockBoundary});
    retry.resolve(walletResponse());
    await expect(outcome).resolves.toMatchObject({
      message: 'WALLET_CHANGED_DURING_REQUEST',
    });
    expect(mockGet).toHaveBeenCalledTimes(2);
  });
});
