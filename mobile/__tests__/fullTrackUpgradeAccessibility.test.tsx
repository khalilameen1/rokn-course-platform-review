import React from 'react';
import {ActivityIndicator, Modal} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import FullTrackUpgradeSheet from '../src/components/FullTrackUpgradeSheet';
import CourseSubscriptionSheet from '../src/components/CourseSubscriptionSheet';
import type {CourseAccessPlan} from '../src/services/roknApi';

const mockCourseDetails = jest.fn();
const mockUpgradeQuote = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getCourseDetails: (...args: unknown[]) => mockCourseDetails(...args),
  getFullTrackUpgradeQuote: (...args: unknown[]) => mockUpgradeQuote(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'user-1', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/hooks/useCourseSubscriptionCheckout', () => ({
  useCourseSubscriptionCheckout: () => ({
    quote: null,
    loading: false,
    busy: false,
    pending: false,
    notice: '',
    retry: jest.fn(),
  }),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({bottom: 0, left: 0, right: 0, top: 0}),
}));
jest.mock('react-native/Libraries/Modal/Modal', () => ({
  __esModule: true,
  default: 'Modal',
}));

const plan: CourseAccessPlan = {
  code: 'guided',
  name: 'Plus',
  priceCoins: 500,
  chatEnabled: true,
  chatMessageLimit: 50,
  projectsEnabled: true,
  projectFeedbackLevel: 'report',
  projectReportEnabled: true,
  projectOutputEnabled: false,
  certificateEnabled: true,
};
const course = {accessPlans: [plan], projectCount: 2, publishedRevision: 9};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((accept, decline) => {
    resolve = accept;
    reject = decline;
  });
  return {promise, resolve, reject};
};

describe('upgrade sheet accessibility ownership', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  beforeEach(() => {
    jest.clearAllMocks();
    mockCourseDetails.mockReset();
    mockUpgradeQuote.mockResolvedValue({
      alreadyUpgraded: false,
      targetPlanCode: 'guided',
    });
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
  });

  it('selects only a plan with project discussion and reuses the existing upgrade checkout', async () => {
    const mentor = {
      ...plan,
      code: 'mentor',
      name: 'Pro',
      projectFollowupEnabled: true,
      projectFollowupMessageLimit: 20,
    };
    mockCourseDetails.mockResolvedValue({
      ...course,
      accessPlans: [plan, mentor],
    });
    const refreshed = jest.fn();
    const close = jest.fn();
    await act(async () => {
      renderer = TestRenderer.create(
        <FullTrackUpgradeSheet
          visible
          courseId="7"
          courseTitle="تصميم"
          requiredFeature="project_discussion"
          onClose={close}
          onUpgraded={refreshed}
        />,
      );
    });
    const checkout = renderer!.root.findByType(CourseSubscriptionSheet);
    expect(checkout.props.plans).toEqual([mentor]);
    expect(checkout.props.selectedPlan).toEqual(mentor);
    expect(checkout.props.mode).toBe('upgrade');
    expect(checkout.props.courseRevision).toBe(9);
    expect(refreshed).not.toHaveBeenCalled();
    await act(async () => checkout.props.onCompleted());
    expect(refreshed).toHaveBeenCalledTimes(1);
    expect(close).toHaveBeenCalledTimes(1);
  });

  it.each([false, true])(
    'filters chat upgrades by the actual chat capability when chatEnabled=%s',
    async enabled => {
      const guided = {...plan, chatEnabled: enabled, chatMessageLimit: 0};
      const mentor = {...plan, code: 'mentor', name: 'Pro'};
      mockCourseDetails.mockResolvedValue({
        ...course,
        accessPlans: [guided, mentor],
      });
      await act(async () => {
        renderer = TestRenderer.create(
          <FullTrackUpgradeSheet
            visible
            courseId="7"
            courseTitle="تصميم"
            requiredFeature="chat"
            onClose={jest.fn()}
          />,
        );
      });
      const sheet = renderer!.root.findByType(CourseSubscriptionSheet);
      expect(sheet.props.plans).toEqual([mentor]);
      expect(sheet.props.selectedPlan).toEqual(mentor);
      expect(sheet.props.requiredFeature).toBe('chat');
    },
  );

  it.each([true, false])(
    'keeps unavailable upgrades explicit without reporting success when alreadyUpgraded=%s',
    async alreadyUpgraded => {
      mockUpgradeQuote.mockResolvedValue({
        alreadyUpgraded,
        targetPlanCode: alreadyUpgraded ? null : 'guided',
      });
      mockCourseDetails.mockResolvedValue({
        ...course,
        accessPlans: [{...plan, chatEnabled: false}],
      });
      const close = jest.fn();
      const refreshed = jest.fn();
      await act(async () => {
        renderer = TestRenderer.create(
          <FullTrackUpgradeSheet
            visible
            courseId="7"
            courseTitle="تصميم"
            requiredFeature="chat"
            quotaExhausted
            onClose={close}
            onUpgraded={refreshed}
          />,
        );
      });
      expect(
        renderer!.root.findAllByType(CourseSubscriptionSheet),
      ).toHaveLength(0);
      expect(
        renderer!.root.findByProps({accessibilityRole: 'alert'}).props.children,
      ).toContain('لا يوجد اشتراك أعلى');
      expect(refreshed).not.toHaveBeenCalled();
      expect(close).not.toHaveBeenCalled();
      expect(renderer!.root.findAllByType(ActivityIndicator)).toHaveLength(0);
    },
  );

  it.each([false, true])(
    'preserves modal focus ownership across loading, failure, retry and ready when embedded=%s',
    async embedded => {
      const first = deferred<typeof course>();
      const retry = deferred<typeof course>();
      mockCourseDetails
        .mockReturnValueOnce(first.promise)
        .mockReturnValueOnce(retry.promise);
      const close = jest.fn();
      await act(async () => {
        renderer = TestRenderer.create(
          <FullTrackUpgradeSheet
            visible
            courseId="7"
            courseTitle="مونتاج الريلز"
            embedded={embedded}
            onClose={close}
          />,
        );
      });
      const expectFocusOwnership = () => {
        expect(renderer!.root.findAllByType(Modal)).toHaveLength(
          embedded ? 0 : 1,
        );
        expect(
          renderer!.root.findAllByProps({
            accessibilityViewIsModal: !embedded,
          }).length,
        ).toBeGreaterThan(0);
        if (embedded) {
          expect(
            renderer!.root.findAllByProps({accessibilityViewIsModal: true}),
          ).toHaveLength(0);
        }
      };
      expectFocusOwnership();
      expect(
        renderer!.root.findByType(ActivityIndicator).props.accessibilityLabel,
      ).toBe('جارٍ تجهيز الترقية');

      await act(async () => first.reject(new Error('offline')));
      expectFocusOwnership();
      expect(
        renderer!.root.findAllByProps({accessibilityRole: 'alert'}).length,
      ).toBeGreaterThan(0);
      const retryButton = renderer!.root
        .findAllByProps({accessibilityRole: 'button'})
        .find(
          button =>
            typeof button.props.onPress === 'function' &&
            button.props.onPress !== close,
        );
      expect(retryButton).toBeDefined();
      await act(async () => retryButton!.props.onPress());
      expectFocusOwnership();
      expect(mockCourseDetails).toHaveBeenCalledTimes(2);
      expect(
        renderer!.root.findAllByProps({accessibilityRole: 'alert'}),
      ).toHaveLength(0);

      await act(async () => retry.resolve(course));
      expectFocusOwnership();
      expect(renderer!.root.findByType(CourseSubscriptionSheet).props).toEqual(
        expect.objectContaining({embedded, mode: 'upgrade', courseRevision: 9}),
      );
      expect(
        renderer!.root
          .findAllByProps({accessibilityRole: 'radio'})
          .some(button => button.props.accessibilityState.checked),
      ).toBe(true);
      expect(close).not.toHaveBeenCalled();
      if (!embedded) {
        await act(async () =>
          renderer!.root.findByType(Modal).props.onRequestClose(),
        );
        expect(close).toHaveBeenCalledTimes(1);
      }
    },
  );
});
