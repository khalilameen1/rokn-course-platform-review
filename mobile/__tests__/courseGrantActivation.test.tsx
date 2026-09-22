import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {useCourseAccessCode} from '../src/screens/CourseDetails/details/useCourseAccessCode';

const mockRedeem = jest.fn();
const mockTrack = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  redeemCourseCode: (...args: unknown[]) => mockRedeem(...args),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: (...args: unknown[]) => mockTrack(...args),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_REDEEM_COURSE_ACCESS_CODE: true,
}));

describe('grant activation presentation', () => {
  beforeEach(() => jest.clearAllMocks());

  it.each([
    ['scholarship', false, true],
    ['scholarship', true, true],
    ['course_code', true, true],
    ['paid', true, false],
  ])(
    'uses actual access %s on replay %s',
    async (accessType, alreadyEnrolled, grantActivated) => {
      mockRedeem.mockResolvedValue({
        courseId: '3',
        accessType,
        alreadyEnrolled,
      });
      const setOwned = jest.fn();
      const showSuccess = jest.fn();
      let state!: ReturnType<typeof useCourseAccessCode>;
      function Harness() {
        state = useCourseAccessCode({
          checkoutBusy: false,
          courseId: '3',
          identityKey: 'learner-1',
          session: true,
          setOwned,
          showSuccess,
          closePurchase: jest.fn(),
          openLogin: jest.fn(),
          setNotice: jest.fn(),
        });
        return null;
      }
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(() => {
        renderer = TestRenderer.create(<Harness />);
      });
      await act(() => state.setCode('GRANT-REPLAY'));
      await act(() => state.redeem());
      expect(state.grantActivated).toBe(grantActivated);
      expect(setOwned).toHaveBeenCalledWith(true);
      expect(showSuccess).toHaveBeenCalledTimes(1);
      expect(mockTrack).toHaveBeenCalledTimes(alreadyEnrolled ? 0 : 1);
      await act(() => renderer.unmount());
    },
  );
});
