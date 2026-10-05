import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  createHomePresentationSession,
  WELCOME_PRESENTED_KEY,
} from '../src/screens/appInitializer/homePresentationSession';

let mockSession = createHomePresentationSession();
let mockReady = true;
let mockBoundary = {scope: 'guest', epoch: 1};
let mockPending: number | null = null;
const mockReceipts = new Map<string, unknown>();
const mockGuest = jest.fn();
const mockNotifications = jest.fn();
const mockLogin = jest.fn();
const mockClearPending = jest.fn();
const mockCredit = jest.fn(async (..._args: unknown[]) => ({}));
jest.mock('../src/screens/appInitializer/StartupExperience', () => ({
  useStartupExperience: () => ({
    presentationSession: mockSession,
    readyForPrompts: mockReady,
  }),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED');
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
  getItem: async (key: string) => mockReceipts.get(key) ?? null,
  saveItem: async (key: string, value: unknown) => {
    mockReceipts.set(key, value);
  },
}));
jest.mock('../src/services/roknApi', () => ({
  claimDailyReward: (...args: unknown[]) => mockCredit(...args),
  markNotificationRead: jest.fn(async () => undefined),
}));
jest.mock('../src/services/api/notifications', () => ({
  getNotificationsPage: (...args: unknown[]) => mockNotifications(...args),
}));
jest.mock('../src/services/api/engagement', () => ({
  getEngagementMessage: (...args: unknown[]) => mockGuest(...args),
}));
jest.mock('../src/services/pendingWelcomeBonus', () => ({
  getPendingWelcomeBonus: async () => mockPending,
  clearPendingWelcomeBonus: (...args: unknown[]) => mockClearPending(...args),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: (...args: unknown[]) => mockLogin(...args),
}));
import {useHomeEngagement} from '../src/screens/home/useHomeEngagement';

const courses = [
  {
    id: '3',
    title: 'عنوان الكورس المنشور',
    image: {uri: 'https://cdn.example/cover.jpg'},
    published: true,
    owned: false,
  },
];
const gift = {
  id: '1',
  key: 'guest_registration_prompt',
  coins: 137,
  title: 'حصلت على هدية ترحيبية',
  description: '',
  actionLabel: 'تسجيل الدخول',
  secondaryActionLabel: 'تابع كزائر',
  version: '1',
};
const notification = {
  id: '71',
  kind: 'new_course',
  title: 'عنوان قديم في الإشعار',
  description: 'وصف مكرر',
  courseId: '3',
  link: '/course/3',
  read: false,
  homeCourse: {
    id: '3',
    title: courses[0].title,
    imageUrl: 'https://cdn.example/cover.jpg',
  },
};
const page = (
  notifications: (typeof notification)[],
  nextCursor: string | null = null,
) => ({
  notifications,
  nextCursor,
  hasMore: nextCursor !== null,
  page: 1,
});

describe('approved first Home presentation', () => {
  let engagement!: ReturnType<typeof useHomeEngagement>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const openCourse = jest.fn(() => true);
  const Harness = ({
    authenticated = false,
    active = true,
  }: {
    authenticated?: boolean;
    active?: boolean;
  }) => {
    engagement = useHomeEngagement({
      active,
      identityKey: mockBoundary.scope,
      loading: false,
      navigation: {} as never,
      openCourse,
      serverSession: authenticated,
    });
    return null;
  };
  const mount = async (authenticated = false) => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness authenticated={authenticated} />);
    });
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockSession = createHomePresentationSession();
    mockReady = true;
    mockBoundary = {scope: 'guest', epoch: 1};
    mockPending = null;
    mockReceipts.clear();
    mockGuest.mockResolvedValue(gift);
    mockNotifications.mockReset().mockResolvedValue(page([notification]));
  });
  afterEach(() => {
    if (renderer) act(() => renderer!.unmount());
    renderer = undefined;
  });

  it('uses the configured amount and records the first display without crediting anything', async () => {
    await mount();
    expect(engagement.guestPrompt?.coins).toBe(137);
    expect(mockReceipts.get(WELCOME_PRESENTED_KEY)).toBe(true);
    expect(mockCredit).not.toHaveBeenCalled();
    expect(mockNotifications).not.toHaveBeenCalled();
  });
  it('opens login and suppresses every further popup after login in that launch', async () => {
    await mount();
    act(() => engagement.openGuest());
    expect(mockLogin).toHaveBeenCalledTimes(1);
    mockBoundary = {scope: 'student-1', epoch: 2};
    await act(async () => renderer!.update(<Harness authenticated />));
    expect(engagement.guestPrompt).toBeNull();
    expect(engagement.campaign).toBeNull();
    expect(mockNotifications).not.toHaveBeenCalled();
  });
  it('does not repeat the gift after a new launch or template edit on the same installation', async () => {
    mockReceipts.set(WELCOME_PRESENTED_KEY, true);
    await mount();
    expect(engagement.guestPrompt).toBeNull();
    expect(mockGuest).not.toHaveBeenCalled();
  });
  it('does not show a zero-value or disabled welcome offer', async () => {
    mockGuest.mockResolvedValue({...gift, coins: 0});
    await mount();
    expect(engagement.guestPrompt).toBeNull();
    expect(mockReceipts.has(WELCOME_PRESENTED_KEY)).toBe(false);
  });
  it('uses the current course title and cover and does not drain a popup queue after closing', async () => {
    mockBoundary = {scope: 'student-1', epoch: 1};
    mockNotifications.mockResolvedValue(
      page([notification, {...notification, id: '72'}]),
    );
    await mount(true);
    expect(engagement.campaign).toMatchObject({
      title: courses[0].title,
      image: courses[0].image,
      description: '',
      badge: 'جديد',
      actionLabel: 'ابدأ الكورس',
    });
    await act(async () => engagement.dismissCampaign(false));
    await act(async () =>
      renderer!.update(<Harness authenticated active={false} />),
    );
    await act(async () => renderer!.update(<Harness authenticated />));
    expect(engagement.campaign).toBeNull();
    expect(mockNotifications).toHaveBeenCalledTimes(1);
  });
  it('continues an empty server-filtered page to find an eligible campaign', async () => {
    mockBoundary = {scope: 'student-1', epoch: 1};
    mockNotifications
      .mockResolvedValueOnce(page([], 'older'))
      .mockResolvedValueOnce(page([notification]));
    await mount(true);
    expect(engagement.campaign?.id).toBe('71');
    expect(mockNotifications.mock.calls[1][0]).toMatchObject({
      surface: 'home',
      cursor: 'older',
    });
  });
  it('continues beyond thirty locally seen course cards instead of hiding the older unseen card', async () => {
    mockBoundary = {scope: 'student-1', epoch: 1};
    const seen = Array.from({length: 30}, (_, index) => ({
      ...notification,
      id: String(100 + index),
    }));
    for (const item of seen)
      mockReceipts.set(
        `@rokn/home-receipt/campaign/${item.id}:student-1`,
        true,
      );
    mockNotifications
      .mockResolvedValueOnce(page(seen, 'older'))
      .mockResolvedValueOnce(page([notification]));
    await mount(true);
    expect(engagement.campaign?.id).toBe('71');
    expect(mockNotifications).toHaveBeenCalledTimes(2);
  });
  it('does not repeat a broken cursor or reserve a slot for an empty page', async () => {
    mockBoundary = {scope: 'student-1', epoch: 1};
    mockNotifications.mockResolvedValue(page([], 'same'));
    await mount(true);
    expect(mockNotifications).toHaveBeenCalledTimes(2);
    expect(engagement.campaign).toBeNull();
    expect(mockSession.available()).toBe(true);
  });
  it.each(['focus', 'account'])(
    'retires a late candidate after %s replacement',
    async reason => {
      mockBoundary = {scope: 'student-1', epoch: 1};
      let resolve!: (value: ReturnType<typeof page>) => void;
      mockNotifications.mockReturnValueOnce(
        new Promise(done => {
          resolve = done;
        }),
      );
      await mount(true);
      const signal = mockNotifications.mock.calls[0][0].signal as AbortSignal;
      if (reason === 'account') mockBoundary = {scope: 'student-2', epoch: 2};
      await act(async () =>
        renderer!.update(<Harness authenticated active={reason !== 'focus'} />),
      );
      await act(async () => resolve(page([notification])));
      expect(signal.aborted).toBe(true);
      expect(engagement.campaign).toBeNull();
    },
  );
  it('retains the single presentation slot across Home remounts', async () => {
    mockBoundary = {scope: 'student-1', epoch: 1};
    await mount(true);
    act(() => renderer!.unmount());
    renderer = undefined;
    await mount(true);
    expect(engagement.campaign).toBeNull();
    expect(mockNotifications).toHaveBeenCalledTimes(1);
  });
  it('retires the old post-login welcome receipt without showing another dialog', async () => {
    mockBoundary = {scope: 'student-1', epoch: 1};
    mockPending = 60;
    await mount(true);
    expect(mockClearPending).toHaveBeenCalled();
    expect(engagement.campaign).toBeNull();
    expect(mockNotifications).not.toHaveBeenCalled();
  });
  it('waits for the startup cover to leave before presenting anything', async () => {
    mockReady = false;
    await mount();
    expect(mockGuest).not.toHaveBeenCalled();
    mockReady = true;
    await act(async () => renderer!.update(<Harness />));
    expect(engagement.guestPrompt?.coins).toBe(137);
  });
  it('does not use a retained guest CTA after an account switch', async () => {
    await mount();
    const oldAction = engagement.openGuest;
    mockBoundary = {scope: 'student-1', epoch: 2};
    await act(async () => renderer!.update(<Harness authenticated />));
    act(() => oldAction());
    expect(mockLogin).not.toHaveBeenCalled();
  });
});
