import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockPage = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getPublishedCoursesPage: (...args: unknown[]) => mockPage(...args),
  getCachedPublishedCourses: jest.fn(async () => []),
  subscribeToUnavailableCourses: jest.fn(() => () => undefined),
}));

import {usePublishedCourseCatalogue} from '../src/screens/home/usePublishedCourseCatalogue';
import {searchHomeCatalogue} from '../src/screens/home/homeCatalogue';

type Catalogue = ReturnType<typeof usePublishedCourseCatalogue>;
type Page = Awaited<
  ReturnType<
    typeof import('../src/services/roknApi').getPublishedCoursesPage
  >
>;

const page = (id: string, currentPage = 1): Page => ({
  courses: [{id, title: id} as Page['courses'][number]],
  page: currentPage,
  hasMore: true,
  total: 90,
  revision: 12,
  reset: false,
  fromCache: false,
});

const deferred = () => {
  let resolve!: (result: Page) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<Page>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};

describe('home catalogue query ownership', () => {
  let current: Catalogue;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = ({query}: {query: string}) => {
    current = usePublishedCourseCatalogue({
      active: true,
      appIsActive: true,
      searchQuery: query,
    });
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness query="" />);
    });
  };
  const change = async (query: string, runDebounce = true) => {
    await act(async () => renderer!.update(<Harness query={query} />));
    if (runDebounce) {
      await act(async () => jest.advanceTimersByTime(350));
    }
  };
  const resultIds = (query: string) =>
    searchHomeCatalogue({
      catalogue: current.browseCourses,
      remoteCourses: current.courses,
      loadedSearchQuery: current.loadedSearchQuery,
      searchQuery: query,
    }).map(course => course.id);

  beforeEach(() => {
    jest.useFakeTimers();
    mockPage.mockReset().mockResolvedValue(page('home'));
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it.each(['fulfilled', 'rejected'] as const)(
    'keeps restored search results when the abandoned query is %s',
    async outcome => {
      await mount();
      mockPage.mockResolvedValueOnce(page('design'));
      await change('تصميم');
      const abandoned = deferred();
      mockPage.mockReturnValueOnce(abandoned.promise);
      await change('برمجة');
      const signal = mockPage.mock.calls.at(-1)![0].signal as AbortSignal;

      await change('تصميم');
      expect(signal.aborted).toBe(true);
      expect(current.loading).toBe(false);
      expect(resultIds('تصميم')).toEqual(['design']);
      await act(async () => {
        if (outcome === 'fulfilled') abandoned.resolve(page('programming'));
        else abandoned.reject(new Error('offline'));
      });
      expect(resultIds('تصميم')).toEqual(['design']);
      expect(current.error).toBe('');

      mockPage.mockResolvedValueOnce(page('design-next', 2));
      await act(async () => current.loadMore());
      expect(mockPage).toHaveBeenLastCalledWith(
        expect.objectContaining({search: 'تصميم', page: 2, revision: 12}),
      );
      expect(resultIds('تصميم')).toEqual(['design', 'design-next']);
    },
  );

  it('cancels the debounce when returning to the loaded query without a new request', async () => {
    await mount();
    mockPage.mockResolvedValueOnce(page('design'));
    await change('تصميم');
    await change('برمجة', false);
    await change('تصميم');
    expect(mockPage).toHaveBeenCalledTimes(2);
    expect(current.loading).toBe(false);
    expect(resultIds('تصميم')).toEqual(['design']);
  });

  it('does not confuse an empty query with a successfully loaded home page', async () => {
    const initial = deferred();
    mockPage.mockReturnValueOnce(initial.promise);
    await mount();
    const abandoned = deferred();
    mockPage.mockReturnValueOnce(abandoned.promise);
    await change('تصميم');
    mockPage.mockResolvedValueOnce(page('home-fresh'));
    await change('');
    expect(mockPage).toHaveBeenCalledTimes(3);
    expect(current.browseCourses.map(course => course.id)).toEqual(['home-fresh']);
    await act(async () => {
      initial.resolve(page('old-home'));
      abandoned.resolve(page('design'));
    });
    expect(current.browseCourses.map(course => course.id)).toEqual(['home-fresh']);
  });

  it('does not append the previous search while the next search is debouncing', async () => {
    await mount();
    mockPage.mockResolvedValueOnce(page('design'));
    await change('تصميم');
    await change('برمجة', false);
    await act(async () => current.loadMore());
    expect(mockPage).toHaveBeenCalledTimes(2);
    mockPage.mockResolvedValueOnce(page('programming'));
    await act(async () => jest.advanceTimersByTime(350));
    expect(resultIds('برمجة')).toEqual(['programming']);
  });

  it('keeps loaded search results even when the home page never loaded', async () => {
    mockPage.mockRejectedValueOnce(new Error('offline'));
    await mount();
    mockPage.mockResolvedValueOnce(page('design'));
    await change('تصميم');
    const abandoned = deferred();
    mockPage.mockReturnValueOnce(abandoned.promise);
    await change('برمجة');
    expect(resultIds('برمجة')).toEqual([]);
    await change('تصميم');
    expect(resultIds('تصميم')).toEqual(['design']);
    expect(current.loading).toBe(false);
    await act(async () => abandoned.resolve(page('programming')));
    expect(resultIds('تصميم')).toEqual(['design']);
  });

  it('retains pagination for loaded results when another search fails', async () => {
    await mount();
    mockPage.mockResolvedValueOnce(page('design'));
    await change('تصميم');
    mockPage.mockResolvedValueOnce(page('design-second', 2));
    await act(async () => current.loadMore());
    mockPage.mockRejectedValueOnce(new Error('offline'));
    await change('برمجة');
    expect(current.error).not.toBe('');
    await change('تصميم');
    expect(current.error).toBe('');
    mockPage.mockResolvedValueOnce(page('design-third', 3));
    await act(async () => current.loadMore());
    expect(mockPage).toHaveBeenLastCalledWith(
      expect.objectContaining({search: 'تصميم', page: 3, revision: 12}),
    );
    expect(resultIds('تصميم')).toEqual(['design', 'design-second', 'design-third']);
  });
});
