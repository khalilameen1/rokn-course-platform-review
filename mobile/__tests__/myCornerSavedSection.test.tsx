import React from 'react';
import {RefreshControl, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {
  LearningDashboard,
  SavedLesson,
  SavedLessonsPage,
} from '../src/services/roknApi';

const mockNavigate = jest.fn();
const mockGet = jest.fn();
const mockSavedPage = jest.fn();
let mockAuthenticated = true;
let mockBoundary = {scope: 'learner', epoch: 1};

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: mockNavigate}),
  useFocusEffect: (effect: () => void | (() => void)) => {
    require('react').useEffect(() => effect(), [effect]);
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
  hasSession: async () => mockAuthenticated,
  getCachedLearningDashboard: async () => null,
  getLearningDashboard: () => mockGet(),
  getSavedLessonsPage: (...args: unknown[]) => mockSavedPage(...args),
}));
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/ui/RasterImage', () => ({
  RasterImage: () => null,
}));
jest.mock('../src/assets/SVG', () => ({PlayInSavedVideosIcon: () => null}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

import MyCorner from '../src/screens/MyCorner';
import {SectionHeading} from '../src/components/ui/PremiumUI';
import {CourseShelf} from '../src/screens/myCorner/CourseShelf';
import {SavedPreview} from '../src/screens/myCorner/SavedPreview';
import {SavedLessonRow} from '../src/screens/savedLibrary/SavedLessonRow';

const course: LearningDashboard['courses'][number] = {
  id: '3',
  title: 'كورس التصميم',
  category: 'skills',
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
};
const dashboardWith = (
  courses: LearningDashboard['courses'],
): LearningDashboard => ({
  courses,
  paths: [],
  badges: [],
  activityDays: [],
  currentStreakDays: 0,
});
const lesson = (id: string, folderId = '1'): SavedLesson => ({
  id,
  folderId,
  folderName: 'أراجعها',
  courseId: '3',
  title: `درس ${id}`,
  courseTitle: 'كورس التصميم',
  duration: '01:30',
});
const page = (lessons: SavedLesson[]): SavedLessonsPage => ({
  lessons,
  page: 1,
  hasMore: false,
  total: lessons.length,
  fromCache: false,
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(accept => {
    resolve = accept;
  });
  return {promise, resolve};
};
const headings = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root.findAllByType(SectionHeading).map(node => node.props.title);
const savedHeading = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root
    .findAllByType(SectionHeading)
    .find(node => node.props.title === 'محفوظاتك');
const hasText = (renderer: TestRenderer.ReactTestRenderer, text: string) =>
  renderer.root.findAllByType(Text).some(node => node.props.children === text);
const rowIds = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root
    .findAllByType(SavedLessonRow)
    .map(row => (row.props.item as SavedLesson).id);

describe('ركني saved section', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  beforeEach(() => {
    mockNavigate.mockReset();
    mockGet.mockReset().mockResolvedValue(dashboardWith([course]));
    mockSavedPage.mockReset();
    mockAuthenticated = true;
    mockBoundary = {scope: 'learner', epoch: 1};
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer.unmount());
  });

  it('shows the latest three saved lessons right under the courses and opens them', async () => {
    // One lesson saved in two lists arrives as two membership rows.
    mockSavedPage.mockResolvedValue(
      page([
        lesson('11'),
        lesson('11', '2'),
        lesson('12'),
        lesson('13'),
        lesson('14'),
      ]),
    );
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });

    expect(mockSavedPage).toHaveBeenCalledTimes(1);
    expect(mockSavedPage).toHaveBeenCalledWith(1);
    // Directly under the courses, above the later progress sections.
    expect(headings(renderer)).toEqual([
      'استكمل من مكانك',
      'محفوظاتك',
      'نشاطك هذا الأسبوع',
    ]);
    expect(rowIds(renderer)).toEqual(['11', '12', '13']);

    await act(async () =>
      renderer.root.findAllByType(SavedLessonRow)[1].props.onPress(),
    );
    expect(mockNavigate).toHaveBeenLastCalledWith('Reels', {
      courseId: '3',
      lessonId: '12',
    });

    const heading = savedHeading(renderer);
    expect(heading?.props.actionLabel).toBe('عرض الكل');
    await act(async () => heading?.props.onAction());
    expect(mockNavigate).toHaveBeenLastCalledWith('SavedLibrary');
  });

  it('renders nothing for the section until the first saved read settles', async () => {
    const saved = deferred<SavedLessonsPage>();
    mockSavedPage.mockReturnValue(saved.promise);
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    expect(renderer.root.findAllByType(CourseShelf)).toHaveLength(1);
    expect(savedHeading(renderer)).toBeUndefined();

    await act(async () => saved.resolve(page([lesson('11')])));
    expect(savedHeading(renderer)).toBeDefined();
    expect(rowIds(renderer)).toEqual(['11']);
  });

  it('shows a one-line hint when the learner has courses but nothing saved', async () => {
    mockSavedPage.mockResolvedValue(page([]));
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    expect(savedHeading(renderer)?.props.actionLabel).toBe('عرض الكل');
    expect(hasText(renderer, 'احفظ أي درس وهتلاقيه هنا')).toBe(true);
    expect(rowIds(renderer)).toEqual([]);
  });

  it('hides the section when there are no courses and nothing saved', async () => {
    mockGet.mockResolvedValue(dashboardWith([]));
    mockSavedPage.mockResolvedValue(page([]));
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    expect(savedHeading(renderer)).toBeUndefined();
    expect(hasText(renderer, 'احفظ أي درس وهتلاقيه هنا')).toBe(false);
  });

  it('keeps the full-library entry when the first read fails', async () => {
    mockSavedPage.mockRejectedValue(new Error('offline'));
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    expect(savedHeading(renderer)?.props.actionLabel).toBe('عرض الكل');
    expect(hasText(renderer, 'احفظ أي درس وهتلاقيه هنا')).toBe(false);
  });

  it('does not read or show saved lessons while signed out', async () => {
    mockAuthenticated = false;
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    expect(mockSavedPage).not.toHaveBeenCalled();
    expect(renderer.root.findAllByType(SavedPreview)).toHaveLength(0);
  });

  it('drops a saved read that finishes after the account changed', async () => {
    const saved = deferred<SavedLessonsPage>();
    mockSavedPage.mockReturnValue(saved.promise);
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    mockBoundary = {scope: 'learner', epoch: 2};
    await act(async () => saved.resolve(page([lesson('11')])));
    expect(rowIds(renderer)).toEqual([]);
  });

  it('refreshes saved lessons with pull to refresh', async () => {
    mockSavedPage.mockResolvedValue(page([lesson('11')]));
    await act(async () => {
      renderer = TestRenderer.create(<MyCorner />);
    });
    mockSavedPage.mockResolvedValue(page([lesson('15'), lesson('11')]));
    await act(async () => {
      renderer.root.findByType(RefreshControl).props.onRefresh();
    });
    expect(mockSavedPage).toHaveBeenCalledTimes(2);
    expect(rowIds(renderer)).toEqual(['15', '11']);
  });
});
