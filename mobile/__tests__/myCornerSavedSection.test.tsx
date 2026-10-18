import React from 'react';
import {RefreshControl, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {createTestQueryClient, withQueryClient} from './helpers/queryClient';

let queryTestClient = createTestQueryClient();
beforeEach(() => {
  queryTestClient = createTestQueryClient();
});
import type {SavedFolderOption} from '../src/components/VideoPlayer/courseLearning/savedFolderIndex';
import type {LearningDashboard} from '../src/services/roknApi';

const mockNavigate = jest.fn();
const mockGet = jest.fn();
const mockFolders = jest.fn();
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
  getLearningDashboard: () => mockGet(),
}));
jest.mock(
  '../src/components/VideoPlayer/courseLearning/savedFolderIndex',
  () => ({
    getSavedFolderOptions: (...args: unknown[]) => mockFolders(...args),
  }),
);
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/ui/RasterImage', () => ({
  RasterImage: () => null,
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

import MyCorner from '../src/screens/MyCorner';
import {SectionHeading} from '../src/components/ui/PremiumUI';
import {CourseArtwork} from '../src/components/ui/CourseArtwork';
import {CourseShelf} from '../src/screens/myCorner/CourseShelf';
import {SavedPreview} from '../src/screens/myCorner/SavedPreview';

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
const folder = (
  id: string,
  name: string,
  lessonsCount?: number,
  imageUrl?: string,
): SavedFolderOption => ({id, name, lessonsCount, imageUrl});
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
// One node per card: the composite that owns the button props.
const cards = (renderer: TestRenderer.ReactTestRenderer) =>
  renderer.root
    .findByType(SavedPreview)
    .findAll(
      node =>
        typeof node.type !== 'string' &&
        node.props.accessibilityRole === 'button' &&
        typeof node.props.accessibilityLabel === 'string' &&
        typeof node.props.onPress === 'function',
      {deep: false},
    );
const cardLabels = (renderer: TestRenderer.ReactTestRenderer) =>
  cards(renderer).map(card => card.props.accessibilityLabel);

describe('ركني saved collections', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  beforeEach(() => {
    mockNavigate.mockReset();
    mockGet.mockReset().mockResolvedValue(dashboardWith([course]));
    mockFolders.mockReset();
    mockAuthenticated = true;
    mockBoundary = {scope: 'learner', epoch: 1};
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer.unmount());
  });

  it('shows watch later first, then the learner lists, with counts and covers', async () => {
    mockFolders.mockResolvedValue([
      folder('8', 'أفكار تصميم', 3, 'https://cdn.example/cover.jpg'),
      folder('5', 'المشاهدة لاحقًا', 1),
      folder(
        '9',
        'مراجعة',
        12,
        'https://rokn.example/images/default-folder.png',
      ),
      folder('4', 'قائمة فاضية', 0),
    ]);
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });

    expect(mockFolders).toHaveBeenCalledTimes(1);
    // Directly under the courses, above the later progress sections.
    expect(headings(renderer)).toEqual([
      'استكمل من مكانك',
      'محفوظاتك',
      'نشاطك هذا الأسبوع',
    ]);
    expect(cardLabels(renderer)).toEqual([
      'المشاهدة لاحقًا، درس واحد',
      'أفكار تصميم، ٣ دروس',
      'مراجعة، ١٢ درس',
      'قائمة فاضية، مفيش دروس',
    ]);
    expect(hasText(renderer, '٣ دروس')).toBe(true);
    // Only a real lesson thumbnail is a cover; the server placeholder and
    // empty lists get the folder tile.
    const covers = renderer.root
      .findByType(SavedPreview)
      .findAllByType(CourseArtwork);
    expect(covers).toHaveLength(1);
    expect(covers[0].props.source).toEqual({
      uri: 'https://cdn.example/cover.jpg',
    });
  });

  it('opens the library on the tapped list and keeps an unfiltered entry', async () => {
    mockFolders.mockResolvedValue([
      folder('5', 'المشاهدة لاحقًا', 2),
      folder('8', 'أفكار تصميم', 3),
    ]);
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });

    await act(async () => cards(renderer)[1].props.onPress());
    expect(mockNavigate).toHaveBeenLastCalledWith('SavedLibrary', {
      folderId: '8',
    });
    await act(async () => cards(renderer)[0].props.onPress());
    expect(mockNavigate).toHaveBeenLastCalledWith('SavedLibrary', {
      folderId: '5',
    });

    const heading = savedHeading(renderer);
    expect(heading?.props.actionLabel).toBe('عرض الكل');
    await act(async () => heading?.props.onAction());
    expect(mockNavigate).toHaveBeenLastCalledWith('SavedLibrary');
  });

  it('omits a count the cache no longer knows instead of showing zero', async () => {
    mockFolders.mockResolvedValue([folder('8', 'أفكار تصميم')]);
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });
    expect(cardLabels(renderer)).toEqual(['أفكار تصميم']);
  });

  it('renders nothing for the section until the first read settles', async () => {
    const read = deferred<SavedFolderOption[]>();
    mockFolders.mockReturnValue(read.promise);
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });
    expect(renderer.root.findAllByType(CourseShelf)).toHaveLength(1);
    expect(savedHeading(renderer)).toBeUndefined();

    await act(async () => read.resolve([folder('5', 'المشاهدة لاحقًا', 1)]));
    expect(savedHeading(renderer)).toBeDefined();
    expect(cardLabels(renderer)).toEqual(['المشاهدة لاحقًا، درس واحد']);
  });

  it.each([
    ['no lists', []],
    ['only an empty watch later list', [folder('5', 'المشاهدة لاحقًا', 0)]],
  ])(
    'shows a one-line hint when the learner has courses and %s',
    async (_case, folders) => {
      mockFolders.mockResolvedValue(folders);
      await act(async () => {
        renderer = TestRenderer.create(
          withQueryClient(<MyCorner />, queryTestClient),
        );
      });
      expect(savedHeading(renderer)?.props.actionLabel).toBe('عرض الكل');
      expect(hasText(renderer, 'احفظ أي درس وهتلاقيه هنا')).toBe(true);
      expect(cards(renderer)).toHaveLength(0);
    },
  );

  it('hides the section when there are no courses and nothing saved', async () => {
    mockGet.mockResolvedValue(dashboardWith([]));
    mockFolders.mockResolvedValue([]);
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });
    expect(savedHeading(renderer)).toBeUndefined();
    expect(hasText(renderer, 'احفظ أي درس وهتلاقيه هنا')).toBe(false);
  });

  it('keeps the full-library entry when the first read fails', async () => {
    mockFolders.mockRejectedValue(new Error('offline'));
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });
    expect(savedHeading(renderer)?.props.actionLabel).toBe('عرض الكل');
    expect(hasText(renderer, 'احفظ أي درس وهتلاقيه هنا')).toBe(false);
    expect(cards(renderer)).toHaveLength(0);
  });

  it('does not read or show saved lists while signed out', async () => {
    mockAuthenticated = false;
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });
    expect(mockFolders).not.toHaveBeenCalled();
    expect(renderer.root.findAllByType(SavedPreview)).toHaveLength(0);
  });

  it('drops a read that finishes after the account changed', async () => {
    const read = deferred<SavedFolderOption[]>();
    mockFolders.mockReturnValue(read.promise);
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });
    mockBoundary = {scope: 'learner', epoch: 2};
    await act(async () => read.resolve([folder('5', 'المشاهدة لاحقًا', 1)]));
    expect(cards(renderer)).toHaveLength(0);
  });

  it('refreshes the lists with pull to refresh', async () => {
    mockFolders.mockResolvedValue([folder('5', 'المشاهدة لاحقًا', 1)]);
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<MyCorner />, queryTestClient),
      );
    });
    mockFolders.mockResolvedValue([
      folder('5', 'المشاهدة لاحقًا', 2),
      folder('8', 'أفكار تصميم', 1),
    ]);
    await act(async () => {
      renderer.root.findByType(RefreshControl).props.onRefresh();
    });
    expect(mockFolders).toHaveBeenCalledTimes(2);
    expect(cardLabels(renderer)).toEqual([
      'المشاهدة لاحقًا، درسين',
      'أفكار تصميم، درس واحد',
    ]);
  });
});
