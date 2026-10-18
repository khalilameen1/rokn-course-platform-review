import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type {QueryClient} from '@tanstack/react-query';
import {
  createTestQueryClient,
  flushQueryPersistence,
  withAppQueryProvider,
} from './helpers/queryClient';

const mockGet = jest.fn();
jest.mock('../src/constants/api', () => ({
  DEFAULT_READ_RECOVERY_BUDGET_MS: 12_000,
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'guest-test', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
  sessionIdentityKey: () => 'guest',
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/courseCatalogue'),
}));

import {getCourseDetails} from '../src/services/api/courseDetails';
import {getPublishedCoursesPage} from '../src/services/api/courseCatalogue';
import {PUBLIC_QUERY_CACHE_KEY} from '../src/services/query/queryPersistence';
import {usePublishedCourseCatalogue} from '../src/screens/home/usePublishedCourseCatalogue';

const response = (ids = [52, 53], revision = 1) => ({
  data: {
    data: {
      courses: ids.map(id => ({id, title: `كورس ${id}`})),
      catalogue_revision: revision,
      pagination: {current_page: 1, last_page: 1, total: ids.length},
    },
  },
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

const persistedCourseIds = async () => {
  const raw = await AsyncStorage.getItem(PUBLIC_QUERY_CACHE_KEY);
  if (!raw) return [];
  const snapshot = JSON.parse(raw);
  return snapshot.clientState.queries.flatMap(
    (query: {state: {data: {pages: {courses: {id: string}[]}[]}}}) =>
      query.state.data.pages.flatMap(page =>
        page.courses.map(course => course.id),
      ),
  );
};

describe('catalogue after a course is confirmed unavailable', () => {
  let current!: ReturnType<typeof usePublishedCourseCatalogue>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let client: QueryClient;
  const Harness = () => {
    current = usePublishedCourseCatalogue({
      active: true,
      appIsActive: true,
      searchQuery: '',
    });
    return null;
  };
  /** A cold start: a new client restores the device snapshot. */
  const mount = async () => {
    client = createTestQueryClient();
    await act(async () => {
      renderer = TestRenderer.create(withAppQueryProvider(<Harness />, client));
    });
    await act(flushQueryPersistence);
  };
  const unmount = async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  };
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockGet.mockImplementation(async (route: string) => {
      if (route === 'courses/52/details') throw {status: 404};
      return response();
    });
  });
  afterEach(unmount);

  it.each([404, 410])(
    'does not revive a known %s course when Home reopens offline',
    async status => {
      await mount();
      expect(current.browseCourses.map(course => course.id)).toEqual([
        '52',
        '53',
      ]);
      expect(await persistedCourseIds()).toEqual(['52', '53']);
      mockGet.mockRejectedValueOnce({status});
      await act(async () => {
        await expect(getCourseDetails('52')).rejects.toMatchObject({status});
      });
      await act(flushQueryPersistence);
      expect(current.browseCourses.map(course => course.id)).not.toContain(
        '52',
      );
      await unmount();

      mockGet.mockRejectedValue({status: 503});
      await mount();

      expect(current.browseCourses.map(course => course.id)).toEqual(['53']);
      expect(await persistedCourseIds()).not.toContain('52');
    },
  );

  it('does not restore an unavailable card from an earlier refresh response', async () => {
    await mount();
    const oldRead = deferred<ReturnType<typeof response>>();
    mockGet.mockImplementationOnce(() => oldRead.promise);
    let refresh!: Promise<void>;
    await act(async () => {
      refresh = current.refresh();
    });
    // The server no longer lists the retired course.
    mockGet.mockImplementation(async (route: string) => {
      if (route === 'courses/52/details') throw {status: 404};
      return response([53], 2);
    });
    await act(async () => {
      await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
    });
    expect(current.browseCourses.map(course => course.id)).toEqual(['53']);

    await act(async () => {
      oldRead.resolve(response());
      await refresh;
    });
    await act(flushQueryPersistence);

    expect(current.browseCourses.map(course => course.id)).toEqual(['53']);
    expect(await persistedCourseIds()).not.toContain('52');
  });

  it('does not remove public discovery for an account-specific 403', async () => {
    await mount();
    mockGet.mockRejectedValueOnce({status: 403});
    await act(async () => {
      await expect(getCourseDetails('52')).rejects.toMatchObject({
        status: 403,
      });
    });
    await act(flushQueryPersistence);
    expect(current.browseCourses.map(course => course.id)).toEqual([
      '52',
      '53',
    ]);
    expect(await persistedCourseIds()).toEqual(['52', '53']);
  });

  it('lets a new authoritative publication put the same course back', async () => {
    await mount();
    await act(async () => {
      await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
    });
    expect(current.browseCourses.map(course => course.id)).toEqual(['53']);
    // There is no permanent tombstone.
    mockGet.mockResolvedValueOnce(response([52, 53], 2));
    await act(async () => {
      await current.refresh();
    });
    expect(current.browseCourses.map(course => course.id)).toEqual([
      '52',
      '53',
    ]);
  });

  it('does not hold Home behind a stalled device snapshot read', async () => {
    jest
      .mocked(AsyncStorage.getItem)
      .mockImplementationOnce(() => new Promise(() => undefined));
    jest.useFakeTimers();
    try {
      client = createTestQueryClient();
      await act(async () => {
        renderer = TestRenderer.create(
          withAppQueryProvider(<Harness />, client),
        );
      });
      // The authoritative read starts before the device read settles.
      expect(mockGet).toHaveBeenCalledWith(
        'courses/list',
        expect.objectContaining({skipAuthorization: true}),
      );
      expect(current.browseCourses.map(course => course.id)).toEqual([
        '52',
        '53',
      ]);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1000);
      });
      expect(current.staleNotice).toBe('');
    } finally {
      jest.useRealTimers();
    }
  });

  it.each(['browse page two', 'search'] as const)(
    'restarts a %s overtaken by a catalogue revision once, at page one',
    async kind => {
      mockGet.mockRejectedValueOnce({
        status: 409,
        data: {code: 'catalogue_changed'},
      });
      const query =
        kind === 'search' ? {search: 'مهارة'} : {page: 2, revision: 1};
      const fresh = response([53], 2);
      mockGet.mockResolvedValueOnce(
        kind === 'search'
          ? {
              data: {
                data: {
                  items: [{course_id: 53, title: 'مهارة'}],
                  catalogue_revision: 2,
                  pagination: fresh.data.data.pagination,
                },
              },
            }
          : fresh,
      );
      expect(await getPublishedCoursesPage(query)).toMatchObject({
        courses: [expect.objectContaining({id: '53'})],
        page: 1,
        ...(kind === 'search' ? {} : {reset: true}),
      });
      expect(mockGet).toHaveBeenCalledTimes(2);
      expect(mockGet).toHaveBeenLastCalledWith(
        kind === 'search' ? 'search/courses' : 'courses/list',
        expect.objectContaining({
          skipAuthorization: true,
          params: expect.objectContaining({page: 1}),
        }),
      );
    },
  );

  it('does not start an unbounded retry if the replacement read conflicts too', async () => {
    mockGet.mockRejectedValue({status: 409, data: {code: 'catalogue_changed'}});
    await expect(
      getPublishedCoursesPage({page: 2, revision: 1}),
    ).rejects.toMatchObject({status: 409});
    expect(
      mockGet.mock.calls.filter(([route]) => route === 'courses/list'),
    ).toHaveLength(2);
  });

  it('replaces the shown pages when a later page answers with a new revision', async () => {
    mockGet.mockImplementation(async (_route: string, config: any) =>
      config.params.page === 1
        ? {
            data: {
              data: {
                courses: [{id: 52, title: 'كورس 52'}],
                catalogue_revision: 1,
                pagination: {current_page: 1, last_page: 2, total: 2},
              },
            },
          }
        : (() => {
            throw {status: 409, data: {code: 'catalogue_changed'}};
          })(),
    );
    await mount();
    expect(current.browseCourses.map(course => course.id)).toEqual(['52']);
    mockGet.mockImplementation(async () => ({
      data: {
        data: {
          courses: [{id: 60, title: 'كورس 60'}],
          catalogue_revision: 2,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    }));
    // Page two of revision 1 is refused; page one of revision 2 replaces it.
    mockGet.mockRejectedValueOnce({
      status: 409,
      data: {code: 'catalogue_changed'},
    });
    await act(async () => current.loadMore());
    await act(async () => undefined);
    expect(current.browseCourses.map(course => course.id)).toEqual(['60']);
    expect(current.hasMore).toBe(false);
  });
});
