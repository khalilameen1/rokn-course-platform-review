import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();
jest.mock('../src/constants/api', () => ({
  DEFAULT_READ_RECOVERY_BUDGET_MS: 12_000,
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'guest-test', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
  accountScopedStorageKey: async (key: string) => `${key}:guest-test`,
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/courseCatalogue'),
  ...jest.requireActual('../src/services/api/courseAvailability'),
}));

import {getCourseDetails} from '../src/services/api/courseDetails';
import {
  getCachedPublishedCourses,
  getPublishedCoursesPage,
} from '../src/services/api/courseCatalogue';
import {usePublishedCourseCatalogue} from '../src/screens/home/usePublishedCourseCatalogue';

const rawGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const rawSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const rawMultiRemove = jest
  .mocked(AsyncStorage.multiRemove)
  .getMockImplementation()!;
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

describe('catalogue after a course is confirmed unavailable', () => {
  let current!: ReturnType<typeof usePublishedCourseCatalogue>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = () => {
    current = usePublishedCourseCatalogue({
      active: true,
      appIsActive: true,
      searchQuery: '',
    });
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  beforeEach(async () => {
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.getItem).mockImplementation(rawGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(rawSet);
    jest.mocked(AsyncStorage.multiRemove).mockImplementation(rawMultiRemove);
    await AsyncStorage.clear();
    mockGet.mockImplementation(async (route: string) => {
      if (route === 'courses/52/details') throw {status: 404};
      return response();
    });
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    await getCachedPublishedCourses();
    jest.useRealTimers();
  });

  it.each([404, 410])(
    'does not revive a known %s course when Home reopens offline',
    async status => {
      await getPublishedCoursesPage();
      expect(
        (await getCachedPublishedCourses()).map(course => course.id),
      ).toEqual(['52', '53']);
      mockGet.mockRejectedValueOnce({status});
      await expect(getCourseDetails('52')).rejects.toMatchObject({status});
      mockGet.mockRejectedValue({status: 503});

      await mount();

      expect(current.browseCourses.map(course => course.id)).not.toContain(
        '52',
      );
      expect(
        (await getCachedPublishedCourses()).map(course => course.id),
      ).not.toContain('52');
    },
  );

  it('does not restore an unavailable card from an earlier refresh response', async () => {
    await mount();
    await getCachedPublishedCourses();
    const oldRead = deferred<ReturnType<typeof response>>();
    mockGet.mockImplementationOnce(() => oldRead.promise);
    let refresh!: Promise<void>;
    await act(async () => {
      refresh = current.refresh();
    });
    await act(async () => {
      await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
    });
    expect(current.browseCourses.map(course => course.id)).toEqual(['53']);
    mockGet.mockResolvedValue(response([53], 2));

    await act(async () => {
      oldRead.resolve(response());
      await refresh;
    });

    expect(current.browseCourses.map(course => course.id)).toEqual(['53']);
    expect(
      (await getCachedPublishedCourses()).map(course => course.id),
    ).not.toContain('52');
  });

  it('does not remove public discovery for an account-specific 403', async () => {
    await getPublishedCoursesPage();
    await getCachedPublishedCourses();
    mockGet.mockRejectedValueOnce({status: 403});
    await expect(getCourseDetails('52')).rejects.toMatchObject({status: 403});
    expect(
      (await getCachedPublishedCourses()).map(course => course.id),
    ).toEqual(['52', '53']);
  });

  it('keeps invalidated disk data unusable after failed deletion until a fresh write succeeds', async () => {
    await getPublishedCoursesPage();
    await getCachedPublishedCourses();
    jest
      .mocked(AsyncStorage.multiRemove)
      .mockRejectedValueOnce(new Error('disk locked'));
    await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
    expect(await getCachedPublishedCourses()).toEqual([]);
    mockGet.mockRejectedValueOnce({status: 503});
    await expect(getPublishedCoursesPage()).rejects.toMatchObject({
      status: 503,
    });

    // There is no permanent tombstone: a new authoritative publication may
    // legitimately put the same course back into public discovery.
    mockGet.mockResolvedValueOnce(response([52, 53], 2));
    await expect(getPublishedCoursesPage()).resolves.toMatchObject({
      fromCache: false,
    });
    expect(
      (await getCachedPublishedCourses()).map(course => course.id),
    ).toEqual(['52', '53']);
  });

  it('bounds an offline queued read while an earlier native write and invalidation are stalled', async () => {
    await getPublishedCoursesPage();
    await getCachedPublishedCourses();
    jest.useFakeTimers();
    const storage = deferred<void>();
    jest
      .mocked(AsyncStorage.setItem)
      .mockImplementationOnce(async (key, value) => {
        await storage.promise;
        return rawSet(key, value);
      });
    await getPublishedCoursesPage();
    const unavailable = getCourseDetails('52').catch(error => error);
    try {
      await jest.advanceTimersByTimeAsync(1000);
      expect(await unavailable).toMatchObject({status: 404});
      let cached: unknown;
      const read = getCachedPublishedCourses().then(value => {
        cached = value;
      });
      await jest.advanceTimersByTimeAsync(1000);
      expect(cached).toEqual([]);
      await read;
    } finally {
      storage.resolve();
      await unavailable;
      await jest.advanceTimersByTimeAsync(1000);
    }
    expect(await getCachedPublishedCourses()).toEqual([]);
  });

  it('bounds the native cache read itself, not only the preceding write queue', async () => {
    await getPublishedCoursesPage();
    await getCachedPublishedCourses();
    jest.useFakeTimers();
    const storage = deferred<void>();
    jest.mocked(AsyncStorage.getItem).mockImplementationOnce(async key => {
      await storage.promise;
      return rawGet(key);
    });
    let cached: unknown;
    const read = getCachedPublishedCourses().then(value => {
      cached = value;
    });
    try {
      await jest.advanceTimersByTimeAsync(1000);
      expect(cached).toEqual([]);
      await read;
    } finally {
      storage.resolve();
      await jest.advanceTimersByTimeAsync(1000);
    }
  });

  it('does not return a cache read captured before definitive invalidation', async () => {
    await getPublishedCoursesPage();
    await getCachedPublishedCourses();
    const storage = deferred<void>();
    const started = deferred<void>();
    jest.mocked(AsyncStorage.getItem).mockImplementationOnce(async key => {
      const captured = await rawGet(key);
      started.resolve();
      await storage.promise;
      return captured;
    });
    const oldRead = getCachedPublishedCourses();
    await started.promise;
    await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
    storage.resolve();
    expect(await oldRead).toEqual([]);
  });

  it.each(['browse page two', 'search'] as const)(
    'refreshes an overtaken %s through the existing single revision retry',
    async kind => {
      const oldRead = deferred<ReturnType<typeof response>>();
      mockGet.mockImplementationOnce(() => oldRead.promise);
      const query =
        kind === 'search' ? {search: 'مهارة'} : {page: 2, revision: 1};
      const read = getPublishedCoursesPage(query);
      await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
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
      oldRead.resolve(response());
      expect(await read).toMatchObject({
        courses: [expect.objectContaining({id: '53'})],
        page: 1,
        ...(kind === 'search' ? {} : {reset: true}),
      });
      expect(mockGet).toHaveBeenCalledTimes(3);
      expect(mockGet).toHaveBeenLastCalledWith(
        kind === 'search' ? 'search/courses' : 'courses/list',
        expect.objectContaining({
          skipAuthorization: true,
          params: expect.objectContaining({page: 1}),
        }),
      );
    },
  );

  it('does not start an unbounded retry if the replacement read is invalidated too', async () => {
    const first = deferred<ReturnType<typeof response>>();
    mockGet.mockImplementationOnce(() => first.promise);
    const read = getPublishedCoursesPage();
    await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
    mockGet.mockImplementationOnce(async () => {
      await expect(getCourseDetails('52')).rejects.toMatchObject({status: 404});
      return response([53], 2);
    });
    first.resolve(response());
    await expect(read).rejects.toMatchObject({code: 'catalogue_changed'});
    expect(
      mockGet.mock.calls.filter(([route]) => route === 'courses/list'),
    ).toHaveLength(2);
    expect(await getCachedPublishedCourses()).toEqual([]);
  });
});
