import React from 'react';
import {RefreshControl} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockNavigate = jest.fn();
const mockGet = jest.fn();
let mockAuthenticated = true;
let mockSessionError = false;
let mockBoundary = {scope: 'learner', epoch: 1};
const mockCached = jest.fn();
let mockFocused = true;

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: mockNavigate}),
  useFocusEffect: (effect: () => void | (() => void)) => {
    require('react').useEffect(
      () => (mockFocused ? effect() : undefined),
      [effect, mockFocused],
    );
  },
}));
jest.mock('react-redux', () => ({
  useSelector: (select: (state: unknown) => unknown) =>
    select({auth: {userData: {id: 1}}}),
}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => mockBoundary.scope,
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary !== mockBoundary)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => {
    if (mockSessionError) throw new Error('session read failed');
    return mockAuthenticated;
  },
  getCachedLearningDashboard: () => mockCached(),
  getLearningDashboard: () => mockGet(),
}));
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

import MyCorner from '../src/screens/MyCorner';
import {CourseShelf} from '../src/screens/myCorner/CourseShelf';
import {StatusView} from '../src/components/ui/PremiumUI';
import {LearningDashboardSkeleton} from '../src/components/ui/Skeleton';
import type {LearningDashboard} from '../src/services/roknApi';

const dashboard: LearningDashboard = {
  courses: [
    {
      id: '3',
      title: 'الكورس المشترى',
      category: 'freelance',
      accessType: 'paid',
      progress: 20,
      started: true,
      completedSections: 1,
      totalSections: 5,
      chatAvailable: true,
      certificateAvailable: false,
      nextSectionId: '31',
      nextSectionType: 'lesson',
      nextSectionTitle: 'المقطع التالي',
    },
  ],
  paths: [],
  badges: [],
  activityDays: [],
  currentStreakDays: 0,
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(accept => {
    resolve = accept;
  });
  return {promise, resolve};
};
const retryButton = (renderer: TestRenderer.ReactTestRenderer) => {
  const status = renderer.root
    .findAllByType(StatusView)
    .find(node => node.props.state === 'error');
  if (status) {
    expect(status.props.actionLabel).toBe('إعادة المحاولة');
    return status.props.onAction as () => void;
  }
  const retry = renderer.root.findAll(
    node =>
      node.props.accessibilityRole === 'button' &&
      node.props.accessibilityLabel === 'إعادة المحاولة' &&
      typeof node.props.onPress === 'function',
  )[0];
  expect(retry).toBeDefined();
  return retry.props.onPress as () => void;
};

describe('MyCorner recovers within the same screen', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  beforeEach(() => {
    mockNavigate.mockReset();
    mockGet.mockReset().mockResolvedValue(dashboard);
    mockCached.mockReset().mockResolvedValue(null);
    mockAuthenticated = true;
    mockSessionError = false;
    mockFocused = true;
    mockBoundary = {scope: 'learner', epoch: 1};
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer.unmount());
  });

  it.each([false, true])(
    'retries a failed read with cached courses=%s without leaving or duplicating requests',
    async cached => {
      if (cached) mockCached.mockResolvedValue(dashboard);
      mockGet.mockRejectedValueOnce(new Error('offline'));
      await act(async () => {
        renderer = TestRenderer.create(<MyCorner />);
      });
      expect(mockGet).toHaveBeenCalledTimes(1);
      const retry = retryButton(renderer);
      const fresh = deferred<LearningDashboard>();
      mockGet.mockReturnValueOnce(fresh.promise);
      await act(async () => {
        retry();
        retry();
      });
      expect(mockGet).toHaveBeenCalledTimes(2);
      expect(mockNavigate).not.toHaveBeenCalled();
      if (cached) {
        expect(
          renderer.root.findByType(CourseShelf).props.orderedCourses[0].id,
        ).toBe('3');
        expect(
          renderer.root.findByType(CourseShelf).props.learningOwnershipFresh,
        ).toBe(false);
      }
      await act(async () => fresh.resolve(dashboard));
      expect(
        renderer.root.findByType(CourseShelf).props.learningOwnershipFresh,
      ).toBe(true);
      expect(renderer.root.findByType(CourseShelf).props.error).toBe('');
    },
  );

  it('can refresh owned courses after a server update without changing tabs', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    const refresh = renderer.root.findAllByType(RefreshControl)[0];
    expect(refresh).toBeDefined();
    const fresh = deferred<LearningDashboard>();
    mockGet.mockReturnValueOnce(fresh.promise);
    await act(async () => {
      refresh.props.onRefresh();
      refresh.props.onRefresh();
    });
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(
      renderer.root.findAllByType(RefreshControl)[0].props.refreshing,
    ).toBe(true);
    await act(async () => fresh.resolve({...dashboard, courses: []}));
    expect(renderer.root.findAllByType(CourseShelf)).toHaveLength(0);
    expect(
      renderer.root.findAllByType(RefreshControl)[0].props.refreshing,
    ).toBe(false);
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it('keeps a successful empty library distinct from an error', async () => {
    mockGet.mockResolvedValue({...dashboard, courses: []});
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    const empty = renderer.root.findByType(StatusView);
    expect(empty.props.state).toBe('empty');
    expect(empty.props.actionLabel).toBe('فتح الرئيسية');
    await act(async () => empty.props.onAction());
    expect(mockNavigate).toHaveBeenCalledWith('Home');
    expect(renderer.root.findAllByType(LearningDashboardSkeleton)).toHaveLength(
      0,
    );
  });

  it('shows recovery rather than an endless skeleton when session lookup fails', async () => {
    mockSessionError = true;
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    expect(renderer.root.findAllByType(LearningDashboardSkeleton)).toHaveLength(
      0,
    );
    const retry = retryButton(renderer);
    mockSessionError = false;
    await act(async () => retry());
    expect(
      renderer.root.findByType(CourseShelf).props.learningOwnershipFresh,
    ).toBe(true);
  });

  it.each(['blur', 'unmount'])(
    'retires an old retry callback after %s',
    async exit => {
      mockGet.mockRejectedValueOnce(new Error('offline'));
      await act(async () => {
        renderer = TestRenderer.create(<MyCorner />);
      });
      const retry = retryButton(renderer);
      if (exit === 'blur') {
        mockFocused = false;
        await act(async () => renderer.update(<MyCorner />));
      } else await act(async () => renderer.unmount());
      await act(async () => retry());
      expect(mockGet).toHaveBeenCalledTimes(1);
    },
  );

  it('ignores an old retry result after changing accounts', async () => {
    mockGet.mockRejectedValueOnce(new Error('offline'));
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    const retry = retryButton(renderer);
    const oldRead = deferred<LearningDashboard>();
    mockGet.mockReturnValueOnce(oldRead.promise);
    await act(async () => retry());
    mockBoundary = {scope: 'second-learner', epoch: 2};
    mockGet.mockResolvedValue({...dashboard, courses: []});
    await act(async () => renderer.update(<MyCorner />));
    await act(async () => oldRead.resolve(dashboard));
    expect(renderer.root.findAllByType(CourseShelf)).toHaveLength(0);
    expect(renderer.root.findByType(StatusView).props.state).toBe('empty');
  });

  it('does not turn a guest sign-in action into an authenticated retry', async () => {
    mockAuthenticated = false;
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    expect(renderer.root.findByType(StatusView).props.actionLabel).toBe(
      'تسجيل الدخول',
    );
    expect(renderer.root.findAllByType(RefreshControl)).toHaveLength(0);
    expect(mockGet).not.toHaveBeenCalled();
  });
});
