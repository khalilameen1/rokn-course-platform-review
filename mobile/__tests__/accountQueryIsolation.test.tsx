import React from 'react';
import {AppState, type AppStateStatus} from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';
import {
  focusManager,
  onlineManager,
  type QueryClient,
} from '@tanstack/react-query';
import {
  createTestQueryClient,
  withAppQueryProvider,
  withQueryClient,
} from './helpers/queryClient';

let mockScope = 'user-a';
const mockDashboard = jest.fn();
const mockWallet = jest.fn();
const mockTasks = jest.fn();
const mockLearningCourses = jest.fn();
const mockCataloguePage = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    jest.requireActual('react').useEffect(effect, [effect]);
  },
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  captureAccountSessionBoundary: async () => ({scope: mockScope, epoch: 1}),
  assertAccountSessionBoundary: (boundary: {scope: string}) => {
    if (boundary.scope !== mockScope) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => true,
  getLearningDashboard: (...args: unknown[]) => mockDashboard(...args),
  getWallet: (...args: unknown[]) => mockWallet(...args),
  getCoinTasks: (...args: unknown[]) => mockTasks(...args),
  getLearningCourses: (...args: unknown[]) => mockLearningCourses(...args),
  getPublishedCoursesPage: (...args: unknown[]) => mockCataloguePage(...args),
}));

import {useMyCornerData} from '../src/screens/myCorner/useMyCornerData';
import {useWalletData} from '../src/screens/wallet/useWalletData';
import {useCourseAccessOverlay} from '../src/screens/home/useCourseAccessOverlay';
import {usePublishedCourseCatalogue} from '../src/screens/home/usePublishedCourseCatalogue';
import {attachQueryPersistence} from '../src/services/query/queryPersistence';
import {installReactNativeQueryManagers} from '../src/services/query/reactNativeQueryManagers';

const learningCourse = (id: string) => ({
  id,
  title: `كورس ${id}`,
  progress: 20,
  started: true,
  completedSections: 1,
  totalSections: 5,
  category: 'freelance' as const,
  accessType: 'paid',
  chatAvailable: false,
  certificateAvailable: true,
});
const dashboardFor = (id: string) => ({
  courses: [learningCourse(id)],
  paths: [],
  badges: [],
  activityDays: [],
  currentStreakDays: 1,
});
const walletOf = (balance: number) => ({
  balance,
  paidBalance: 0,
  rewardBalance: balance,
  rewardContributionCap: 1000,
  spendableBalance: balance,
  spendPolicy: 'reward_first_then_paid' as const,
  coinRules: [],
  transactions: [],
});
const cataloguePage = (ids: string[]) => ({
  courses: ids.map(id => ({id, title: `كورس ${id}`})),
  page: 1,
  hasMore: false,
  total: ids.length,
  revision: 1,
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
const offline = () =>
  Object.assign(new Error('Network Error'), {code: 'ERR_NETWORK'});
const settle = async (ms = 40) => {
  await act(async () => {
    await new Promise(resolve => setTimeout(resolve, ms));
  });
};

/** What a previous process left on the device for `scope`. */
const seedDeviceSnapshot = async (
  scope: string,
  entries: Array<[readonly unknown[], unknown]>,
) => {
  const previousScope = mockScope;
  mockScope = scope;
  const previous = createTestQueryClient();
  const persistence = attachQueryPersistence(previous, {throttleTime: 0});
  await persistence.restorePublic();
  await persistence.activateAccountScope(scope);
  entries.forEach(([key, data]) => previous.setQueryData(key, data));
  await new Promise(resolve => setTimeout(resolve, 60));
  persistence.dispose();
  previous.clear();
  mockScope = previousScope;
};

describe('account isolation of TanStack queries', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let client: QueryClient;

  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockScope = 'user-a';
    client = createTestQueryClient();
    mockLearningCourses.mockImplementation(async () => [
      learningCourse(mockScope === 'user-a' ? '11' : '22'),
    ]);
    mockCataloguePage.mockResolvedValue(cataloguePage(['1']));
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  });

  it('never shows the previous account’s courses or wallet after a switch', async () => {
    mockDashboard.mockResolvedValue(dashboardFor('11'));
    mockWallet.mockResolvedValue(walletOf(10));
    mockTasks.mockResolvedValue([]);
    const frames: Array<{
      corner: string[];
      overlay: string[];
      balance: number | null;
    }> = [];
    const Probe = ({identityKey}: {identityKey: string}) => {
      const corner = useMyCornerData(identityKey);
      const wallet = useWalletData(identityKey);
      const overlay = useCourseAccessOverlay({
        active: true,
        appIsActive: true,
        identityKey,
      });
      frames.push({
        corner: corner.dashboard?.courses.map(course => course.id) ?? [],
        overlay: overlay.courses.map(course => course.id),
        balance: wallet.wallet?.balance ?? null,
      });
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<Probe identityKey="account-a" />, client),
      );
    });
    expect(frames.at(-1)).toEqual({
      corner: ['11'],
      overlay: ['11'],
      balance: 10,
    });

    // The next account's reads are still in flight.
    const nextDashboard = deferred<ReturnType<typeof dashboardFor>>();
    const nextWallet = deferred<ReturnType<typeof walletOf>>();
    mockDashboard.mockReturnValue(nextDashboard.promise);
    mockWallet.mockReturnValue(nextWallet.promise);
    mockScope = 'user-b';
    const switchedAt = frames.length;
    await act(async () => {
      renderer!.update(
        withQueryClient(<Probe identityKey="account-b" />, client),
      );
    });
    const afterSwitch = frames.slice(switchedAt);
    expect(afterSwitch.length).toBeGreaterThan(0);
    afterSwitch.forEach(frame => {
      expect(frame.corner).not.toContain('11');
      expect(frame.overlay).not.toContain('11');
      expect(frame.balance).not.toBe(10);
    });
    // The previous scope's entries are gone, not merely hidden.
    expect(client.getQueryCache().findAll({queryKey: ['user-a']})).toHaveLength(
      0,
    );

    await act(async () => {
      nextDashboard.resolve(dashboardFor('22'));
      nextWallet.resolve(walletOf(25));
    });
    expect(frames.at(-1)).toEqual({
      corner: ['22'],
      overlay: ['22'],
      balance: 25,
    });
  });

  it('discards a previous account’s response that lands after the switch', async () => {
    const late = deferred<ReturnType<typeof walletOf>>();
    mockWallet.mockReturnValueOnce(late.promise);
    mockTasks.mockResolvedValue([]);
    let balance: number | null = null;
    const Probe = ({identityKey}: {identityKey: string}) => {
      balance = useWalletData(identityKey).wallet?.balance ?? null;
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<Probe identityKey="account-a" />, client),
      );
    });
    mockScope = 'user-b';
    mockWallet.mockResolvedValue(walletOf(40));
    await act(async () => {
      renderer!.update(
        withQueryClient(<Probe identityKey="account-b" />, client),
      );
    });
    await act(async () => late.resolve(walletOf(999)));
    expect(balance).toBe(40);
    expect(client.getQueryData(['user-a', 'wallet'])).toBeUndefined();
    expect(client.getQueryData(['user-b', 'wallet'])).toMatchObject({
      balance: 40,
    });
  });

  it('removes the previous account’s queries at the root even with no screen mounted', async () => {
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<></>, client, 'account-a'),
      );
    });
    await settle();
    client.setQueryData(['user-a', 'wallet'], walletOf(10));
    client.setQueryData(['public', 'catalogue', ''], {
      pages: [cataloguePage(['1'])],
      pageParams: [{page: 1}],
    });
    mockScope = 'user-b';
    await act(async () => {
      renderer!.update(withAppQueryProvider(<></>, client, 'account-b'));
    });
    await settle();
    expect(client.getQueryData(['user-a', 'wallet'])).toBeUndefined();
    // Public discovery is account-neutral and stays.
    expect(client.getQueryData(['public', 'catalogue', ''])).toBeDefined();
  });

  it('restores the device snapshot on cold start and shows the existing offline copy', async () => {
    await seedDeviceSnapshot('user-a', [
      [
        ['public', 'catalogue', ''],
        {
          pages: [{...cataloguePage(['7']), query: ''}],
          pageParams: [{page: 1}],
        },
      ],
      [['user-a', 'learning-dashboard'], dashboardFor('11')],
      [['user-a', 'wallet'], walletOf(33)],
    ]);
    // The phone is offline: every read fails after its transport attempt.
    const failLater = () =>
      new Promise((_resolve, reject) =>
        setTimeout(() => reject(offline()), 30),
      );
    mockCataloguePage.mockImplementation(failLater);
    mockDashboard.mockImplementation(failLater);
    mockWallet.mockImplementation(failLater);
    mockTasks.mockImplementation(failLater);
    mockLearningCourses.mockImplementation(failLater);

    let catalogue!: ReturnType<typeof usePublishedCourseCatalogue>;
    let corner!: ReturnType<typeof useMyCornerData>;
    let wallet!: ReturnType<typeof useWalletData>;
    const Screens = () => {
      catalogue = usePublishedCourseCatalogue({
        active: true,
        appIsActive: true,
        searchQuery: '',
      });
      corner = useMyCornerData('account-a');
      wallet = useWalletData('account-a');
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screens />, client, 'account-a'),
      );
    });
    await settle(15);
    // Restored before the network answered: shown at once.
    expect(catalogue.browseCourses.map(course => course.id)).toEqual(['7']);
    expect(catalogue.staleNotice).toBe(
      'نعرض النسخة المحفوظة\nسنحدّثها عند عودة الاتصال',
    );
    expect(corner.dashboard?.courses.map(course => course.id)).toEqual(['11']);
    expect(wallet.wallet?.balance).toBe(33);

    await settle(80);
    expect(catalogue.error).toBe('');
    expect(catalogue.staleNotice).toBe(
      'نعرض النسخة المحفوظة\nسنحدّثها عند عودة الاتصال',
    );
    expect(corner.dashboard?.courses.map(course => course.id)).toEqual(['11']);
    expect(corner.dashboardError).toBe(
      'نعرض آخر تقدم محفوظ\nسنحدّثه عند عودة الاتصال',
    );
    expect(corner.learningOwnershipFresh).toBe(false);
    expect(wallet.wallet?.balance).toBe(33);
    expect(wallet.walletStatus).toBe('error');
  });

  it('never restores another account’s snapshot', async () => {
    await seedDeviceSnapshot('user-a', [
      [['user-a', 'learning-dashboard'], dashboardFor('11')],
    ]);
    mockScope = 'user-b';
    const pending = deferred<ReturnType<typeof dashboardFor>>();
    mockDashboard.mockReturnValue(pending.promise);
    let corner!: ReturnType<typeof useMyCornerData>;
    const Screen = () => {
      corner = useMyCornerData('account-b');
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screen />, client, 'account-b'),
      );
    });
    await settle();
    expect(corner.dashboard).toBeNull();
    expect(
      client.getQueryData(['user-a', 'learning-dashboard']),
    ).toBeUndefined();
    await act(async () => pending.resolve(dashboardFor('22')));
  });
});

describe('React Native focus and connectivity (TanStack managers)', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let appStateListener: ((state: AppStateStatus) => void) | undefined;
  let netInfoListener:
    | ((state: {isConnected: boolean; isInternetReachable: boolean}) => void)
    | undefined;

  beforeEach(() => {
    jest.clearAllMocks();
    jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((_event, listener) => {
        appStateListener = listener as typeof appStateListener;
        return {remove: jest.fn()};
      });
    jest.mocked(NetInfo.addEventListener).mockImplementation(listener => {
      netInfoListener = listener as unknown as typeof netInfoListener;
      return jest.fn();
    });
    installReactNativeQueryManagers();
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    focusManager.setFocused(undefined);
    onlineManager.setOnline(true);
    jest.restoreAllMocks();
  });

  const mountCatalogue = async (client: QueryClient) => {
    const Screen = () => {
      usePublishedCourseCatalogue({
        active: true,
        appIsActive: true,
        searchQuery: '',
      });
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(withQueryClient(<Screen />, client));
    });
  };

  it('refetches a stale catalogue when the app returns to the foreground', async () => {
    mockCataloguePage.mockResolvedValue(cataloguePage(['1']));
    const client = createTestQueryClient();
    await mountCatalogue(client);
    expect(mockCataloguePage).toHaveBeenCalledTimes(1);

    // Fresh data: returning within two minutes does not read again.
    await act(async () => {
      appStateListener?.('background');
      appStateListener?.('active');
    });
    expect(mockCataloguePage).toHaveBeenCalledTimes(1);

    // Two minutes later the list is stale.
    await act(async () => {
      await client.invalidateQueries({refetchType: 'none'});
    });
    await act(async () => {
      appStateListener?.('background');
      appStateListener?.('active');
    });
    expect(mockCataloguePage).toHaveBeenCalledTimes(2);
  });

  it('refetches a stale catalogue when connectivity returns', async () => {
    mockCataloguePage.mockResolvedValue(cataloguePage(['1']));
    const client = createTestQueryClient();
    await mountCatalogue(client);
    await act(async () => {
      await client.invalidateQueries({refetchType: 'none'});
    });
    await act(async () => {
      netInfoListener?.({isConnected: false, isInternetReachable: false});
    });
    expect(onlineManager.isOnline()).toBe(false);
    await act(async () => {
      netInfoListener?.({isConnected: true, isInternetReachable: true});
    });
    expect(mockCataloguePage).toHaveBeenCalledTimes(2);
  });
});
