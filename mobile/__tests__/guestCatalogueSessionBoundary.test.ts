import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));

import {
  getCachedPublishedCourses,
  getPublishedCoursesPage,
  hasSession,
} from '../src/services/api/courses';
import {resetSecureSessionForTests} from '../src/services/secureSession';

describe('guest catalogue session boundary', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    resetSecureSessionForTests();
    await AsyncStorage.clear();
  });

  it('loads the public catalogue without starting secure-session hydration', async () => {
    mockGet.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          courses: [],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 0},
        },
      },
    });

    await expect(hasSession()).resolves.toBe(false);
    await expect(getPublishedCoursesPage()).resolves.toMatchObject({
      courses: [],
      page: 1,
      hasMore: false,
      fromCache: false,
    });
    expect(mockGet).toHaveBeenCalledWith(
      'courses/list',
      expect.objectContaining({skipAuthorization: true}),
    );
    expect(mockGet.mock.calls[0][1]).not.toHaveProperty(
      'optionalAuthorization',
    );
    expect(mockGet).toHaveBeenCalledTimes(1);
  });

  it('maps the current search contract through the same card model', async () => {
    mockGet.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          items: [
            {
              course_id: 52,
              title: 'صناعة المحتوى',
              teacher_name: 'مدرب ركن',
              image: null,
              badge: 'مختار لك',
              badge_tone: 'gold',
              is_coming_soon: false,
              duration_minutes: 62,
              ratings_count: 4,
              average_rating: 4.5,
              students_count: 18,
            },
          ],
          catalogue_revision: 3,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    });

    await expect(
      getPublishedCoursesPage({search: 'محتوى'}),
    ).resolves.toMatchObject({
      courses: [
        expect.objectContaining({
          id: '52',
          title: 'صناعة المحتوى',
          instructor: 'مدرب ركن',
          label: 'مختار لك',
          durationMinutes: 62,
          owned: false,
        }),
      ],
      revision: 3,
    });
    expect(mockGet).toHaveBeenCalledWith(
      'search/courses',
      expect.objectContaining({
        params: expect.objectContaining({q: 'محتوي'}),
      }),
    );
  });

  it('requires the catalogue generation declared by the current API', async () => {
    mockGet.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          courses: [],
          pagination: {current_page: 1, last_page: 1, total: 0},
        },
      },
    });

    await expect(getPublishedCoursesPage()).rejects.toThrow(
      'COURSE_CATALOGUE_CONTRACT_INVALID',
    );
  });

  it('isolates malformed nested catalogue fields instead of losing valid courses', async () => {
    mockGet.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          courses: [
            {
              id: 7,
              title: 'كورس صالح',
              tags: [null, {id: 3, name_ar: 'مهارة', show_on_home: true}],
              modules: {invalid: true},
            },
          ],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    });

    await expect(getPublishedCoursesPage()).resolves.toMatchObject({
      courses: [
        expect.objectContaining({
          id: '7',
          title: 'كورس صالح',
          homeRows: [expect.objectContaining({id: '3', title: 'مهارة'})],
        }),
      ],
      page: 1,
      fromCache: false,
    });
  });

  it('uses cached catalogue only for availability failures, not an invalid 200 contract', async () => {
    const valid = {
      data: {
        status: 200,
        success: true,
        data: {
          courses: [{id: 52, title: 'كورس ركن'}],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    };
    mockGet.mockResolvedValueOnce(valid);
    await expect(getPublishedCoursesPage()).resolves.toMatchObject({
      fromCache: false,
    });

    mockGet.mockRejectedValueOnce({status: 503});
    await expect(getPublishedCoursesPage()).resolves.toMatchObject({
      fromCache: true,
      courses: [expect.objectContaining({id: '52'})],
    });

    mockGet.mockResolvedValueOnce({
      data: {
        status: 200,
        success: true,
        data: {
          courses: [{id: null, title: ''}],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    });
    await expect(getPublishedCoursesPage()).rejects.toThrow(
      'COURSE_CATALOGUE_CONTRACT_INVALID',
    );

    expect(
      (await AsyncStorage.getAllKeys()).some(key =>
        key.includes(
          encodeURIComponent(
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
          ),
        ),
      ),
    ).toBe(true);
  });

  it('does not revive an expired catalogue after an availability failure', async () => {
    mockGet.mockResolvedValueOnce({
      data: {
        data: {
          courses: [{id: 52, title: 'كورس ركن'}],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    });
    await getPublishedCoursesPage();
    await getCachedPublishedCourses();

    const cacheKey = (await AsyncStorage.getAllKeys()).find(key =>
      key.includes('@rokn/catalogue-page/v6:'),
    );
    expect(cacheKey).toBeTruthy();
    const cached = JSON.parse((await AsyncStorage.getItem(cacheKey!))!);
    cached.savedAt = Date.now() - 3 * 60 * 60 * 1000;
    await AsyncStorage.setItem(cacheKey!, JSON.stringify(cached));

    mockGet.mockRejectedValueOnce({status: 503});
    await expect(getPublishedCoursesPage()).rejects.toEqual({status: 503});
  });
});
