import React from 'react';
import {FlatList, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockPage = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getPublishedCoursesPage: (...args: unknown[]) => mockPage(...args),
  getCachedPublishedCourses: jest.fn(async () => []),
  subscribeToUnavailableCourses: jest.fn(() => () => undefined),
}));
jest.mock('../src/components/view/CourseCarousel', () => () => null);
jest.mock('../src/components/view/CourseCard', () => () => null);
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Path: 'Path',
  Rect: 'Rect',
}));

import HomeCatalogueFeed from '../src/screens/home/HomeCatalogueFeed';
import CoursesSection from '../src/components/view/CoursesSection';
import {usePublishedCourseCatalogue} from '../src/screens/home/usePublishedCourseCatalogue';
import {buildHomeSections} from '../src/screens/home/homeCatalogue';
import type {Course} from '../src/types/Course';

type Page = Awaited<
  ReturnType<typeof import('../src/services/roknApi').getPublishedCoursesPage>
>;
const course = (index: number): Course => ({
  id: String(index),
  title: `كورس ${index}`,
  description: '',
  instructor: 'مدرب',
  image: {uri: 'https://example.test/course.jpg'},
  category: 'skills',
  homeRows: [{id: 'one-row', title: 'مهارات', order: 1}],
});
const page = (courses: Course[], currentPage = 1, hasMore = true): Page => ({
  courses,
  page: currentPage,
  hasMore,
  total: 50,
  revision: 12,
  reset: false,
  fromCache: false,
});

describe('home catalogue visible pagination continuation', () => {
  let current!: ReturnType<typeof usePublishedCourseCatalogue>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = ({query}: {query: string}) => {
    current = usePublishedCourseCatalogue({
      active: true,
      appIsActive: true,
      searchQuery: query,
    });
    return (
      <HomeCatalogueFeed
        active
        error={current.error}
        hasMore={current.hasMore}
        hasSearchQuery={Boolean(query)}
        heroCourses={[]}
        loadMoreError={current.loadMoreError}
        loading={current.loading}
        loadingMore={current.loadingMore}
        searchMatches={query ? current.courses ?? [] : []}
        sections={
          query ? [] : buildHomeSections({catalogue: current.browseCourses})
        }
        staleNotice={current.staleNotice}
        onLoadMore={current.loadMore}
        onOpenCourse={jest.fn()}
        onRefresh={current.refresh}
      />
    );
  };
  const button = (label: string) =>
    renderer!.root
      .findAll(
        node =>
          node.props.accessibilityRole === 'button' &&
          typeof node.props.onPress === 'function',
      )
      .find(node =>
        node.findAllByType(Text).some(text => text.props.children === label),
      );
  const mount = async (query: string) => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness query={query} />);
    });
  };

  beforeEach(() => {
    jest.useFakeTimers();
    mockPage.mockReset();
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it.each([
    {label: 'one search row with twenty matches', query: 'تصميم', count: 20},
    {label: 'one short browsing row', query: '', count: 1},
  ])(
    'loads the next page of $label without vertical scrolling',
    async ({query, count}) => {
      const first = Array.from({length: count}, (_, index) =>
        course(index + 1),
      );
      mockPage.mockResolvedValueOnce(page(first));
      mockPage.mockResolvedValueOnce(page([course(40)], 2, false));
      await mount(query);

      // Rendering a short row must not automatically drain global pages.
      expect(mockPage).toHaveBeenCalledTimes(1);
      expect(current.courses).toHaveLength(count);
      expect(button('عرض المزيد')).toBeDefined();
      await act(async () => button('عرض المزيد')!.props.onPress());

      expect(mockPage).toHaveBeenCalledTimes(2);
      expect(mockPage).toHaveBeenLastCalledWith(
        expect.objectContaining({page: 2, search: query, revision: 12}),
      );
      expect(current.courses?.map(item => item.id)).toEqual([
        ...first.map(item => item.id),
        '40',
      ]);
      expect(button('عرض المزيد')).toBeUndefined();
    },
  );

  it('deduplicates repeated presses during an outstanding next-page read', async () => {
    let finish!: (result: Page) => void;
    mockPage.mockResolvedValueOnce(page([course(1)]));
    mockPage.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finish = resolve;
        }),
    );
    await mount('');
    const press = button('عرض المزيد')!.props.onPress;
    await act(async () => {
      press();
      press();
    });
    expect(mockPage).toHaveBeenCalledTimes(2);
    expect(current.loadingMore).toBe(true);
    const remainingAction = button('عرض المزيد');
    if (remainingAction) expect(remainingAction.props.disabled).toBe(true);
    await act(async () => finish(page([course(2)], 2, false)));
    expect(current.courses?.map(item => item.id)).toEqual(['1', '2']);
  });

  it('keeps the loaded row on next-page failure and retries that same page explicitly', async () => {
    mockPage.mockResolvedValueOnce(page([course(1)]));
    mockPage.mockRejectedValueOnce(new Error('offline'));
    mockPage.mockResolvedValueOnce(page([course(2)], 2, false));
    await mount('تصميم');
    await act(async () => button('عرض المزيد')!.props.onPress());
    expect(current.courses?.map(item => item.id)).toEqual(['1']);
    expect(current.loadMoreError).not.toBe('');
    expect(button('إعادة المحاولة')).toBeDefined();
    await act(async () => jest.advanceTimersByTime(500));
    expect(mockPage).toHaveBeenCalledTimes(2);
    await act(async () => button('إعادة المحاولة')!.props.onPress());
    expect(mockPage).toHaveBeenLastCalledWith(
      expect.objectContaining({page: 2, search: 'تصميم', revision: 12}),
    );
    expect(current.courses?.map(item => item.id)).toEqual(['1', '2']);
    expect(current.loadMoreError).toBe('');
  });

  it('connects the search list end to the next global search page', async () => {
    mockPage.mockResolvedValueOnce(
      page(Array.from({length: 20}, (_, i) => course(i + 1))),
    );
    mockPage.mockResolvedValueOnce(page([course(21)], 2, false));
    await mount('تصميم');
    const list = renderer!.root.findByType(FlatList);
    expect(list.props.horizontal).toBe(true);
    expect(typeof list.props.onEndReached).toBe('function');
    await act(async () => list.props.onEndReached({distanceFromEnd: 0}));
    expect(mockPage).toHaveBeenCalledTimes(2);
    expect(current.courses).toHaveLength(21);
  });

  it('does not treat the end of a curated row as the end of the global catalogue', async () => {
    mockPage.mockResolvedValueOnce(page([course(1)]));
    await mount('');
    expect(
      renderer!.root.findByType(FlatList).props.onEndReached,
    ).toBeUndefined();
    expect(button('عرض المزيد')).toBeDefined();
    expect(mockPage).toHaveBeenCalledTimes(1);
  });

  it('updates the native end callback when only pagination ownership changes', async () => {
    const data = [course(1)];
    const open = jest.fn();
    const previous = jest.fn();
    const next = jest.fn();
    await act(async () => {
      renderer = TestRenderer.create(
        <CoursesSection
          data={data}
          onCoursePress={open}
          onLoadMore={previous}
        />,
      );
    });
    await act(async () => {
      renderer!.update(
        <CoursesSection data={data} onCoursePress={open} onLoadMore={next} />,
      );
    });
    await act(async () => {
      renderer!.root
        .findByType(FlatList)
        .props.onEndReached({distanceFromEnd: 0});
    });
    expect(next).toHaveBeenCalledTimes(1);
    expect(previous).not.toHaveBeenCalled();
  });
});
