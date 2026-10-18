import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';
import type {QueryClient} from '@tanstack/react-query';
import {
  createTestQueryClient,
  withAppQueryProvider,
} from './helpers/queryClient';

let mockScope = 'user-a';
const offline = () =>
  Object.assign(new Error('Network Error'), {code: 'ERR_NETWORK'});
const mockRead = jest.fn(async () => {
  throw offline();
});

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
// Offline: every read is refused at once.
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => mockScope.startsWith('user-'),
  getPublishedCoursesPage: () => mockRead(),
  getLearningDashboard: () => mockRead(),
  getWallet: () => mockRead(),
  getCoinTasks: () => mockRead(),
  getLearningCourses: () => mockRead(),
  getCourseDetailsSnapshot: () => mockRead(),
  isCourseUnavailableError: () => false,
}));
jest.mock('../src/services/walletSettlement', () => ({
  subscribeWalletSettlements: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  mapCoursePayload: () => null,
  applyLocalLearningState: async (value: unknown) => value,
}));

import {usePublishedCourseCatalogue} from '../src/screens/home/usePublishedCourseCatalogue';
import {useMyCornerData} from '../src/screens/myCorner/useMyCornerData';
import {useWalletData} from '../src/screens/wallet/useWalletData';
import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';
import {attachQueryPersistence} from '../src/services/query/queryPersistence';

const CATALOGUE_SAVED = 'نعرض النسخة المحفوظة\nسنحدّثها عند عودة الاتصال';
const DASHBOARD_SAVED = 'نعرض آخر تقدم محفوظ\nسنحدّثه عند عودة الاتصال';
const DETAILS_SAVED = 'نعرض آخر تفاصيل محفوظة\nسنحدّثها عند عودة الاتصال';

const catalogue = {
  pages: [
    {
      courses: [{id: '7', title: 'كورس 7'}],
      page: 1,
      hasMore: false,
      total: 1,
      revision: 1,
      query: '',
    },
  ],
  pageParams: [{page: 1}],
};
const dashboard = {
  courses: [
    {
      id: '11',
      title: 'كورس 11',
      progress: 20,
      started: true,
      completedSections: 1,
      totalSections: 5,
      category: 'freelance',
      accessType: 'paid',
      chatAvailable: false,
      certificateAvailable: true,
    },
  ],
  paths: [],
  badges: [],
  activityDays: [],
  currentStreakDays: 1,
};
const wallet = {
  balance: 33,
  paidBalance: 0,
  rewardBalance: 33,
  rewardContributionCap: 1000,
  spendableBalance: 33,
  spendPolicy: 'reward_first_then_paid',
  coinRules: [],
  transactions: [],
};
const details = {
  course: {
    id: '3',
    publishedRevision: 1,
    title: 'نسخة محفوظة',
    price: 0,
    owned: false,
    accessPlans: [],
    modules: [],
  },
  learning: null,
  learningRead: null,
};

const sleep = (ms: number) =>
  new Promise<void>(resolve => setTimeout(resolve, ms));

/** What a previous process left on the device. */
const seed = async (
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
  await sleep(60);
  persistence.dispose();
  previous.clear();
  mockScope = previousScope;
};

describe('offline cold start with a slow device snapshot', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let client: QueryClient;

  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    client = createTestQueryClient();
  });
  let restoreDeviceSpeed = () => undefined as void;
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    restoreDeviceSpeed();
  });

  /** The device store answers the snapshot read after ~300 ms. */
  const slowDeviceSnapshot = () => {
    const nativeGet = jest.mocked(AsyncStorage.getItem);
    const read = nativeGet.getMockImplementation()!;
    nativeGet.mockImplementation(async key => {
      if (String(key).startsWith('@rokn/query-cache')) await sleep(300);
      return read(key);
    });
    return () => nativeGet.mockImplementation(read);
  };

  it('shows the saved catalogue, dashboard and wallet with their offline notes', async () => {
    await seed('user-a', [
      [['public', 'catalogue', ''], catalogue],
      [['user-a', 'learning-dashboard'], dashboard],
      [['user-a', 'wallet'], wallet],
    ]);
    mockScope = 'user-a';
    restoreDeviceSpeed = slowDeviceSnapshot();
    const frames: Array<{catalogueError: string; dashboardError: string}> = [];
    let home!: ReturnType<typeof usePublishedCourseCatalogue>;
    let corner!: ReturnType<typeof useMyCornerData>;
    let rewards!: ReturnType<typeof useWalletData>;
    const Screens = () => {
      home = usePublishedCourseCatalogue({
        active: true,
        appIsActive: true,
        searchQuery: '',
      });
      corner = useMyCornerData('account-a');
      rewards = useWalletData('account-a');
      frames.push({
        catalogueError: home.error,
        dashboardError: corner.dashboardError,
      });
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screens />, client, 'account-a'),
      );
    });
    await act(async () => {
      await sleep(100);
    });
    // The network has already refused every read; the screens still wait.
    expect(mockRead).toHaveBeenCalled();
    expect(home.error).toBe('');
    expect(corner.dashboardError).toBe('');

    await act(async () => {
      await sleep(400);
    });
    expect(home.browseCourses.map(course => course.id)).toEqual(['7']);
    expect(home.error).toBe('');
    expect(home.staleNotice).toBe(CATALOGUE_SAVED);
    expect(corner.dashboard?.courses.map(course => course.id)).toEqual(['11']);
    expect(corner.dashboardError).toBe(DASHBOARD_SAVED);
    expect(rewards.wallet?.balance).toBe(33);
    // No frame ever showed the bare network error instead of the copy.
    frames.forEach(frame => {
      expect(frame.catalogueError).toBe('');
      expect(
        frame.dashboardError === '' || frame.dashboardError === DASHBOARD_SAVED,
      ).toBe(true);
    });
  });

  it('shows a guest the saved course details with their offline note', async () => {
    await seed('guest-g', [[['guest-g', 'course-details', '3'], details]]);
    mockScope = 'guest-g';
    restoreDeviceSpeed = slowDeviceSnapshot();
    let course!: ReturnType<typeof useCourseDetailsData>;
    const errors: string[] = [];
    const Screen = () => {
      course = useCourseDetailsData({courseId: '3', identityKey: 'guest'});
      errors.push(course.course.error);
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screen />, client, 'guest'),
      );
    });
    await act(async () => {
      await sleep(500);
    });
    expect(mockRead).toHaveBeenCalled();
    expect(course.course.value).toMatchObject({
      title: 'نسخة محفوظة',
      fromCache: true,
    });
    expect(course.course.notice).toBe(DETAILS_SAVED);
    expect(errors.every(error => error === '')).toBe(true);
  });

  it('shows the error only when there is no saved copy', async () => {
    mockScope = 'user-a';
    restoreDeviceSpeed = slowDeviceSnapshot();
    let corner!: ReturnType<typeof useMyCornerData>;
    const Screen = () => {
      corner = useMyCornerData('account-a');
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screen />, client, 'account-a'),
      );
    });
    await act(async () => {
      await sleep(500);
    });
    expect(corner.dashboard).toBeNull();
    expect(corner.dashboardError).not.toBe('');
    expect(corner.dashboardError).not.toBe(DASHBOARD_SAVED);
  });
});
