import React, {useState} from 'react';
import TestRenderer, {act} from 'react-test-renderer';
jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/screens/CourseDetails/details/CourseCodeEntry', () => ({
  CourseCodeEntry: () => null,
}));
const mockOffer = jest.fn();
jest.mock('../src/services/api/engagement', () => ({
  getCoursePurchaseExitOffer: (...args: unknown[]) => mockOffer(...args),
}));
const mockCheckout = {
  quote: {
    id: 'restored',
    status: 'pending_payment',
    courseId: 'recovery-course',
    planCode: 'basic',
    originalPrice: 400,
    finalPrice: 400,
    rewardCoins: 0,
    deficit: 400,
    channel: 'direct',
    remainingPaidCoins: 0,
  },
  pending: true,
  blockedByPreviousCheckout: false,
  busy: false,
  loading: false,
  notice: '',
  coinPackage: {id: '1', displayPrice: '٤ جنيه', coins: 400},
  confirm: jest.fn(),
  retry: jest.fn(),
  cancelPending: jest.fn(),
};
let mockRecoveryCallback: (() => void) | undefined;
jest.mock('../src/hooks/useCourseSubscriptionCheckout', () => ({
  useCourseSubscriptionCheckout: (args: {onPaymentRecovery?: () => void}) => {
    mockRecoveryCallback = args.onPaymentRecovery;
    return mockCheckout;
  },
}));
import {usePurchaseEntry} from '../src/screens/CourseDetails/details/usePurchaseEntry';
import {CoursePurchaseDialog} from '../src/screens/CourseDetails/details/PurchaseDialogs';
import type {DialogStep} from '../src/screens/CourseDetails/details/useCoursePurchaseFlow';
type Params = Parameters<typeof usePurchaseEntry>[0];
const plan: Params['accessPlans'][number] = {
  code: 'basic',
  name: 'Basic',
  priceCoins: 400,
  chatEnabled: false,
  chatMessageLimit: 0,
  projectFeedbackLevel: 'pass_only',
  projectReportEnabled: false,
  projectOutputEnabled: false,
  certificateEnabled: false,
};

it.each(['pending_cancel', 'early_resume'])(
  'restored payment (%s) → requote → close never becomes a no-attempt exit',
  async recovery => {
    jest.useFakeTimers();
    mockOffer.mockReset();
    mockCheckout.cancelPending.mockReset();
    mockCheckout.confirm.mockReset();
    mockCheckout.pending = recovery === 'pending_cancel';
    mockCheckout.quote = {
      ...mockCheckout.quote,
      status: mockCheckout.pending ? 'pending_payment' : 'quoted',
    };
    let entry!: ReturnType<typeof usePurchaseEntry>;
    function Harness() {
      const [step, setStep] = useState<DialogStep>('plans');
      entry = usePurchaseEntry({
        accessPlans: [plan],
        courseId: 'recovery-course',
        identityKey: `fresh-recovery-process-${recovery}`,
        dialogStep: step,
        owned: false,
        pageReady: true,
        remoteSession: true,
        navigation: {setParams: jest.fn()} as unknown as Params['navigation'],
        routeParams: {courseId: 'recovery-course'},
        primaryAction: {kind: 'choose_plan', label: 'اختر الاشتراك'},
        selectedPlanCode: 'basic',
        purchasePrice: 400,
        spendableBalance: 0,
        closePurchase: () => setStep(null),
        openForTerms: jest.fn(),
        showPlans: jest.fn(),
        setNotice: jest.fn(),
      });
      return (
        <CoursePurchaseDialog
          courseId="recovery-course"
          courseTitle="كورس"
          dialogStep={step}
          accessPlans={[plan]}
          selectedPlan={plan}
          notice=""
          onSubscribed={jest.fn()}
          onSelectPlan={jest.fn()}
          onSuccessStart={jest.fn()}
          onClose={entry.closeDialog}
          onPaymentAttempt={entry.paymentAttempted}
        />
      );
    }
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      // This assertion fails if the Sheet stops forwarding early recovery,
      // independently of its pending-state effect or the cancel button.
      expect(mockRecoveryCallback).toBe(entry.paymentAttempted);
      mockCheckout.cancelPending.mockImplementation(async () => {
        mockCheckout.pending = false;
        mockCheckout.quote = {...mockCheckout.quote, status: 'quoted'};
      });
      if (recovery === 'pending_cancel') {
        await act(async () => {
          await renderer.root
            .findByProps({accessibilityLabel: 'إلغاء طلب الاشتراك'})
            .props.onPress();
        });
      } else {
        // Hook detected pending before resume, but resumed/requoted state was
        // already quoted. No pending effect or cancel control can guard this.
        await act(async () => {
          mockRecoveryCallback?.();
        });
      }
      await act(async () => {
        renderer.update(<Harness />);
      });
      await act(async () => {
        renderer.root
          .findByProps({accessibilityLabel: 'إغلاق الاشتراكات'})
          .props.onPress();
      });
      await act(async () => {
        jest.advanceTimersByTime(180);
      });
      expect(mockCheckout.cancelPending).toHaveBeenCalledTimes(
        recovery === 'pending_cancel' ? 1 : 0,
      );
      expect(mockCheckout.confirm).not.toHaveBeenCalled();
      expect(mockOffer).not.toHaveBeenCalled();
      expect(entry.retention.visible).toBe(false);
    } finally {
      if (renderer) await act(async () => renderer.unmount());
      jest.useRealTimers();
    }
  },
);
