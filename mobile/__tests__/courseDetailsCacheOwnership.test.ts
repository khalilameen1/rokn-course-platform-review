import AsyncStorage from '@react-native-async-storage/async-storage';
import type {CourseDetails} from '../src/services/api/courseContractTypes';

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string) => `${key}:guest-test`,
}));

import {
  COURSE_DETAILS_CACHE_KEY,
  cacheCourseDetails,
  readCourseDetailsCache,
  removeCourseDetailsCache,
  touchCourseDetailsCache,
} from '../src/services/api/courseDetailsCache';

const key = (scope: string) => `${COURSE_DETAILS_CACHE_KEY}:${scope}`;
const course = (id: string): CourseDetails => ({
  id,
  publishedRevision: 1,
  title: `كورس ${id}`,
  description: '',
  price: 100,
  instructor: 'رُكن',
  instructorBio: '',
  owned: false,
  started: false,
  modules: [],
  reelCount: 0,
  projectCount: 0,
  previewReelCount: 0,
  ratingAverage: null,
  ratingsCount: 0,
  userRating: null,
  studentsCount: 0,
  durationMinutes: null,
  accessPlans: [],
});
const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const flush = () => new Promise<void>(done => setImmediate(done));

describe('course details cache owns one index and write queue per account', () => {
  const disk = new Map<string, string>();
  const nativeGet = jest.mocked(AsyncStorage.getItem);
  const nativeSet = jest.mocked(AsyncStorage.setItem);
  beforeEach(() => {
    jest.clearAllMocks();
    disk.clear();
    nativeGet.mockImplementation(
      async storageKey => disk.get(storageKey) ?? null,
    );
    nativeSet.mockImplementation(async (storageKey, value) => {
      disk.set(storageKey, value);
    });
    jest
      .mocked(AsyncStorage.removeItem)
      .mockImplementation(async storageKey => {
        disk.delete(storageKey);
      });
    jest.mocked(AsyncStorage.multiRemove).mockImplementation(async keys => {
      keys.forEach(storageKey => disk.delete(storageKey));
    });
  });

  it('persists another account while an older account index read remains suspended', async () => {
    const blocked = deferred<string | null>();
    nativeGet.mockReturnValueOnce(blocked.promise);
    const oldSave = cacheCourseDetails('1', course('1'), key('account-a'));
    await flush();
    const newSave = cacheCourseDetails('2', course('2'), key('account-b'));
    try {
      await flush();
      expect(disk.has(`${key('account-b')}:2`)).toBe(true);
      await expect(
        readCourseDetailsCache('2', key('account-b')),
      ).resolves.toMatchObject({id: '2', fromCache: true});
    } finally {
      blocked.resolve(null);
      await Promise.all([oldSave, newSave]);
    }
    expect(JSON.parse(disk.get(`${key('account-a')}:index`)!)).toEqual(['1']);
    expect(JSON.parse(disk.get(`${key('account-b')}:index`)!)).toEqual(['2']);
  });

  it('serializes saves and enforces the eight-course limit without evicting another account', async () => {
    await cacheCourseDetails('1', course('1'), key('account-b'));
    await Promise.all(
      Array.from({length: 9}, (_, index) => {
        const id = String(index + 1);
        return cacheCourseDetails(id, course(id), key('account-a'));
      }),
    );
    expect(JSON.parse(disk.get(`${key('account-a')}:index`)!)).toEqual([
      '9',
      '8',
      '7',
      '6',
      '5',
      '4',
      '3',
      '2',
    ]);
    expect(disk.has(`${key('account-a')}:1`)).toBe(false);
    await expect(
      readCourseDetailsCache('1', key('account-b')),
    ).resolves.toMatchObject({id: '1'});
    for (let id = 2; id <= 9; id++) {
      await expect(
        readCourseDetailsCache(String(id), key('account-a')),
      ).resolves.toMatchObject({id: String(id)});
    }
  });

  it('orders a touch and removal behind an unfinished write on the same account index', async () => {
    const blocked = deferred<void>();
    nativeSet.mockImplementationOnce(async (storageKey, value) => {
      await blocked.promise;
      disk.set(storageKey, value);
    });
    const save = cacheCourseDetails('1', course('1'), key('account-a'));
    const touch = touchCourseDetailsCache('1', key('account-a'));
    const remove = removeCourseDetailsCache('1', key('account-a'));
    try {
      await flush();
      expect(nativeSet).toHaveBeenCalledTimes(1);
      expect(AsyncStorage.removeItem).not.toHaveBeenCalled();
    } finally {
      blocked.resolve();
      await Promise.all([save, touch, remove]);
    }
    expect(disk.has(`${key('account-a')}:1`)).toBe(false);
    expect(JSON.parse(disk.get(`${key('account-a')}:index`)!)).toEqual([]);
  });

  it('does not wedge later same-account saves after a failed native write', async () => {
    nativeSet.mockRejectedValueOnce(new Error('disk unavailable'));
    const failed = cacheCourseDetails('1', course('1'), key('account-a'));
    const next = cacheCourseDetails('2', course('2'), key('account-a'));
    await expect(failed).rejects.toThrow('disk unavailable');
    await next;
    expect(JSON.parse(disk.get(`${key('account-a')}:index`)!)).toEqual(['2']);
    await expect(
      readCourseDetailsCache('2', key('account-a')),
    ).resolves.toMatchObject({id: '2'});
  });

  it('retains optional guest key resolution and the existing persisted version', async () => {
    await cacheCourseDetails('1', course('1'), key('guest-test'));
    expect(JSON.parse(disk.get(`${key('guest-test')}:1`)!)).toMatchObject({
      version: 5,
      course: {id: '1'},
    });
    await expect(readCourseDetailsCache('1')).resolves.toMatchObject({id: '1'});
    await removeCourseDetailsCache('1');
    await expect(readCourseDetailsCache('1')).resolves.toBeNull();
  });
});
