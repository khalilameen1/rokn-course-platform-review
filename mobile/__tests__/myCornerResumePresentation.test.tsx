import React from 'react';
import {ScrollView, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {CourseShelf} from '../src/screens/myCorner/CourseShelf';
import {styles} from '../src/screens/myCorner/styles';
import {buildMyCornerModel} from '../src/screens/myCorner/model';
import type {LearningCourse, LearningDashboard} from '../src/services/roknApi';
import {cleanUnicodeText} from '../src/utils/unicodeText';
import {Spacing} from '../src/constants/designSystem';

let mockDimensions = {width: 390, height: 844, scale: 2, fontScale: 1};
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({
  __esModule: true,
  default: () => mockDimensions,
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

const lessonCourse: LearningCourse = {
  id: '52',
  title: 'تصوير بالموبايل',
  progress: 20,
  started: true,
  completedSections: 1,
  totalSections: 5,
  category: 'freelance',
  accessType: 'paid',
  chatAvailable: true,
  certificateAvailable: false,
  nextSectionId: '71',
  nextSectionType: 'lesson',
  lastLessonId: '71',
  resumePositionSeconds: 34,
};
const projectCourse: LearningCourse = {
  ...lessonCourse,
  id: '53',
  title: 'صناعة المحتوى',
  nextSectionId: '72',
  nextSectionType: 'project',
};

describe('MyCorner continuation presentation', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const onOpenCourse = jest.fn();
  const onResume = jest.fn();
  const renderShelf = (
    overrides: Partial<React.ComponentProps<typeof CourseShelf>> = {},
  ) => {
    act(() => {
      renderer = TestRenderer.create(
        <CourseShelf
          error=""
          largeText={false}
          learningOwnershipFresh
          onOpenCourse={onOpenCourse}
          onResume={onResume}
          onRetry={jest.fn()}
          orderedCourses={[lessonCourse, projectCourse]}
          {...overrides}
        />,
      );
    });
  };
  const actionButtons = () =>
    renderer.root
      .findAll(node => typeof node.props.style === 'function')
      .filter(node =>
        node
          .findAllByType(Text)
          .some(text =>
            ['استكمل', 'عرض الكورس', 'راجع الكورس'].includes(
              text.props.children,
            ),
          ),
      );
  const resumeButtons = () =>
    actionButtons().filter(node =>
      cleanUnicodeText(node.props.accessibilityLabel).startsWith('استكمال '),
    );

  beforeEach(() => {
    onOpenCourse.mockReset();
    onResume.mockReset();
    mockDimensions = {width: 390, height: 844, scale: 2, fontScale: 1};
  });
  afterEach(() => {
    act(() => renderer?.unmount());
  });

  it.each([false, true])(
    'uses the same filled action, spacing, typography, and pressed state for both courses (large text: %s)',
    largeText => {
      renderShelf({largeText});
      const buttons = resumeButtons();
      expect(buttons).toHaveLength(2);
      for (const button of buttons) {
        expect(button.parent?.props.style).toBe(styles.resumeAction);
        expect(
          StyleSheet.flatten(button.props.style({pressed: false})),
        ).toEqual(StyleSheet.flatten(styles.resumeButton));
        expect(StyleSheet.flatten(button.props.style({pressed: true}))).toEqual(
          StyleSheet.flatten([styles.resumeButton, styles.resumeButtonPressed]),
        );
        const label = button.findByType(Text);
        expect(label.props.children).toBe('استكمل');
        expect(label.props.style).toBe(styles.resumeButtonText);
      }
      expect(
        StyleSheet.flatten(styles.resumeButton).minHeight,
      ).toBeGreaterThanOrEqual(48);
    },
  );

  it.each([
    [320, 1],
    [320, 1.3],
    [360, 1.3],
    [390, 1],
    [430, 1.3],
  ])(
    'shows every course in one compact rail with a next-card peek at %idp / font scale %s',
    (width, fontScale) => {
      mockDimensions = {...mockDimensions, width, fontScale};
      renderShelf({largeText: fontScale >= 1.3});
      const rail = renderer.root.findByType(ScrollView);
      const cards = renderer.root.findAll(
        node =>
          Array.isArray(node.props.style) &&
          node.props.style[0] === styles.courseCard,
        {deep: false},
      );
      expect(cards).toHaveLength(2);
      const cardWidth = StyleSheet.flatten(cards[0].props.style)
        .width as number;
      const gutter = Math.min(18, Math.max(12, width * 0.05));
      expect(cardWidth).toBeGreaterThanOrEqual(width * 0.55);
      expect(cardWidth).toBeLessThanOrEqual(width * 0.65);
      expect(width - gutter * 2 - cardWidth - Spacing.sm).toBeGreaterThan(70);
      expect(cards.map(card => StyleSheet.flatten(card.props.style))).toEqual([
        {...styles.courseCard, width: cardWidth},
        {...styles.courseCard, width: cardWidth},
      ]);
      expect(rail.props.horizontal).toBe(true);
      expect(rail.props.nestedScrollEnabled).toBe(true);
      expect(rail.props.snapToInterval).toBe(cardWidth + Spacing.sm);
      expect(
        StyleSheet.flatten(rail.props.contentContainerStyle),
      ).toMatchObject({
        direction: 'rtl',
        flexDirection: 'row',
        alignItems: 'stretch',
      });
    },
  );

  it('keeps lesson position and project continuation independent from details', () => {
    renderShelf();
    const buttons = resumeButtons();
    act(() => buttons[0].props.onPress());
    act(() => buttons[1].props.onPress());
    expect(onResume.mock.calls).toEqual([
      [{courseId: '52', lessonId: '71', initialPositionSeconds: 34}],
      [{courseId: '53', projectId: '72'}],
    ]);
    expect(onOpenCourse).not.toHaveBeenCalled();

    const details = renderer.root
      .findAll(node => typeof node.props.style === 'function')
      .find(node =>
        cleanUnicodeText(node.props.accessibilityLabel).startsWith(
          'عرض تفاصيل ',
        ),
      );
    act(() => details!.props.onPress());
    expect(onOpenCourse).toHaveBeenCalledWith('52');
    expect(onResume).toHaveBeenCalledTimes(2);
  });

  it('hides continuation until learning ownership is fresh', () => {
    renderShelf({learningOwnershipFresh: false});
    expect(resumeButtons()).toHaveLength(0);
    expect(onResume).not.toHaveBeenCalled();
    const detailsActions = actionButtons();
    expect(detailsActions).toHaveLength(2);
    act(() => detailsActions[0].props.onPress());
    expect(onOpenCourse).toHaveBeenCalledWith('52');
  });

  it.each([
    {started: false, progress: 0},
    {progress: 100},
    {nextSectionId: undefined},
    {nextSectionType: undefined},
  ])('does not add a continuation action for ineligible courses: %o', state => {
    renderShelf({orderedCourses: [{...lessonCourse, ...state}]});
    expect(resumeButtons()).toHaveLength(0);
    expect(onResume).not.toHaveBeenCalled();
  });

  it('keeps not-started and completed courses visible with details/review actions', () => {
    renderShelf({
      orderedCourses: [
        {...lessonCourse, started: false, progress: 0},
        {...projectCourse, progress: 100},
      ],
    });
    const buttons = actionButtons();
    expect(
      buttons.map(button => button.findByType(Text).props.children),
    ).toEqual(['عرض الكورس', 'راجع الكورس']);
    act(() => buttons[0].props.onPress());
    act(() => buttons[1].props.onPress());
    expect(onOpenCourse.mock.calls).toEqual([['52'], ['53']]);
    expect(onResume).not.toHaveBeenCalled();
  });

  it('orders by real activity date across completion states and preserves server order for equal or missing dates', () => {
    const courses: LearningCourse[] = [
      {...lessonCourse, id: '1', lastWatchedAt: '2026-09-08T12:00:00Z'},
      {
        ...lessonCourse,
        id: '2',
        progress: 100,
        lastWatchedAt: '2026-09-10T12:00:00Z',
      },
      {...lessonCourse, id: '3', lastWatchedAt: undefined},
      {
        ...lessonCourse,
        id: '4',
        progress: 0,
        started: false,
        lastWatchedAt: undefined,
      },
      {...lessonCourse, id: '5', lastWatchedAt: '2026-09-08T12:00:00Z'},
      {...lessonCourse, id: '6', lastWatchedAt: 'invalid-date'},
    ];
    const dashboard: LearningDashboard = {
      courses,
      paths: [],
      badges: [],
      activityDays: [],
      currentStreakDays: 0,
    };
    const model = buildMyCornerModel({
      dashboard,
      selectedPathId: null,
      signedIn: true,
    });
    expect(model.orderedCourses.map(course => course.id)).toEqual([
      '2',
      '1',
      '5',
      '3',
      '4',
      '6',
    ]);
    expect(courses.map(course => course.id)).toEqual([
      '1',
      '2',
      '3',
      '4',
      '5',
      '6',
    ]);
  });
});
