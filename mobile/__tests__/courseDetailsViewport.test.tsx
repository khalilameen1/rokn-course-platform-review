import React from 'react';
import {ScrollView, StyleSheet} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockInsets = {top: 24, bottom: 16, left: 0, right: 0};
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => mockInsets,
}));
jest.mock('@react-navigation/native', () => ({
  useRoute: () => ({params: {courseId: '7'}}),
  useNavigation: () => ({navigate: jest.fn(), setParams: jest.fn()}),
  useIsFocused: () => true,
  useFocusEffect: jest.fn(),
}));
jest.mock('react-redux', () => ({
  useSelector: (select: (state: unknown) => unknown) =>
    select({auth: {userData: null}}),
}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => 'guest',
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  goBackOrHome: jest.fn(),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/screens/CourseDetails/details/useCourseDetailsData', () => ({
  useCourseDetailsData: () => ({
    course: {
      value: null,
      error: '',
      reload: jest.fn(),
      prepareLearningNavigation: jest.fn(),
    },
  }),
}));
jest.mock('../src/screens/CourseDetails/details/useCoursePurchase', () => ({
  useCoursePurchase: () => ({
    presentation: {courseTitle: 'الكورس', pageReady: false},
    dialog: {dialogStep: null},
    retention: {},
  }),
}));
jest.mock('../src/screens/CourseDetails/details/useCourseRating', () => ({
  useCourseRating: () => ({rating: null}),
}));
jest.mock('../src/screens/CourseDetails/details/PurchaseDialogs', () => ({
  CoursePurchaseDialog: 'CoursePurchaseDialog',
  CourseRetentionDialog: 'CourseRetentionDialog',
}));
jest.mock('../src/screens/CourseDetails/details/sections', () => ({
  CourseActionBar: 'CourseActionBar',
  CourseBody: 'CourseBody',
  CourseHero: 'CourseHero',
  CourseIntro: 'CourseIntro',
  CourseRatingAction: 'CourseRatingAction',
}));

import CourseDetails from '../src/screens/CourseDetails';

// This checks layout ownership, not native pixels. Scrolled native acceptance
// must still be repeated on the rebuilt artifact with its verified hash.
describe('course details fixed safe-area viewport', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  afterEach(() => act(() => renderer?.unmount()));

  it.each([0, 24, 59])(
    'keeps the top inset outside scrolling content (%s)',
    top => {
      mockInsets.top = top;
      act(() => {
        renderer = TestRenderer.create(<CourseDetails />);
      });
      const scroll = renderer.root.findByType(ScrollView);
      expect(StyleSheet.flatten(scroll.parent!.props.style).paddingTop).toBe(
        top,
      );
      expect(StyleSheet.flatten(scroll.props.style).paddingTop).toBeUndefined();
      expect(
        StyleSheet.flatten(scroll.props.contentContainerStyle).paddingTop,
      ).toBeUndefined();
      expect(
        renderer.root.findByType('CourseHero' as never).props.topInset,
      ).toBeUndefined();
      expect(
        renderer.root.findByType('CourseActionBar' as never).props.bottomInset,
      ).toBe(16);
      const before = scroll;
      mockInsets.top = 32;
      act(() => {
        renderer.update(<CourseDetails />);
      });
      expect(renderer.root.findByType(ScrollView)).toBe(before);
      expect(StyleSheet.flatten(scroll.parent!.props.style).paddingTop).toBe(
        32,
      );
    },
  );
});
