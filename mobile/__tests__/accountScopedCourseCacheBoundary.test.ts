import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();
let mockSessionSnapshot: {
  ready: boolean;
  session: unknown;
  epoch: number;
};

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));
jest.mock('../src/constants/api', () => ({
  DEFAULT_READ_RECOVERY_BUDGET_MS: 12_000,
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/services/secureSession', () => {
  const actual = jest.requireActual('../src/services/secureSession');
  return {...actual, peekSecureSession: () => mockSessionSnapshot};
});

import {
  getCachedPublishedCourses,
  getCourseDetails,
  getCourseDetailsSnapshot,
  getPublishedCoursesPage,
} from '../src/services/api/courses';

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return {promise, resolve, reject};
};

describe('account-scoped course cache boundary', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it.each([
    [
      'guest to user',
      {ready: false, session: null, epoch: 1},
      {
        ready: true,
        session: {user: {id: 7}, api_token: 'token-seven'},
        epoch: 2,
      },
    ],
    [
      'user to user',
      {
        ready: true,
        session: {user: {id: 7}, api_token: 'token-seven'},
        epoch: 4,
      },
      {
        ready: true,
        session: {user: {id: 8}, api_token: 'token-eight'},
        epoch: 5,
      },
    ],
  ])(
    'does not return the captured owner cache after a %s switch',
    async (_label, before, after) => {
      mockSessionSnapshot = before;
      const request = deferred<never>();
      let started!: () => void;
      const requestStarted = new Promise<void>(resolve => {
        started = resolve;
      });
      mockGet.mockImplementation(() => {
        started();
        return request.promise;
      });

      const flight = getCourseDetails('52');
      await requestStarted;
      mockSessionSnapshot = after;
      request.reject(new Error('offline'));

      await expect(flight).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    },
  );

  it('keeps the guest course response when slow session restore settles empty', async () => {
    mockSessionSnapshot = {ready: false, session: null, epoch: 10};
    const request = deferred<unknown>();
    let started!: () => void;
    const requestStarted = new Promise<void>(resolve => {
      started = resolve;
    });
    mockGet.mockImplementation(() => {
      started();
      return request.promise;
    });

    const flight = getPublishedCoursesPage();
    await requestStarted;
    mockSessionSnapshot = {ready: true, session: null, epoch: 11};
    request.resolve({
      data: {
        data: {
          courses: [{id: 52, title: 'كورس ركن'}],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    });

    await expect(flight).resolves.toMatchObject({
      courses: [expect.objectContaining({id: '52'})],
    });
  });

  it('keeps ownership out of the shared public catalogue cache', async () => {
    mockSessionSnapshot = {
      ready: true,
      session: {user: {id: 7}, api_token: 'token-seven'},
      epoch: 12,
    };
    mockGet.mockResolvedValue({
      data: {
        data: {
          courses: [
            {
              id: 52,
              title: 'كورس ركن',
              access_type: 'paid',
              progress: 70,
              enrollment: {is_active: true, progress_percentage: 70},
            },
          ],
          catalogue_revision: 1,
          pagination: {current_page: 1, last_page: 1, total: 1},
        },
      },
    });

    await getPublishedCoursesPage();
    mockSessionSnapshot = {
      ready: true,
      session: {user: {id: 8}, api_token: 'token-eight'},
      epoch: 13,
    };
    const cached = await getCachedPublishedCourses();

    expect(cached[0]).toMatchObject({id: '52', owned: false});
    expect(cached[0]).not.toHaveProperty('progress');
  });

  it('accepts a null average for an unrated published course', async () => {
    mockSessionSnapshot = {ready: true, session: null, epoch: 20};
    const plan = (
      code: string,
      feedback: 'pass_only' | 'report' | 'enhanced',
    ) => ({
      code,
      name: code,
      price_coins: 20,
      minimum_paid_coins: 0,
      chat_enabled: false,
      project_report_enabled: false,
      project_thread_reply_enabled: false,
      project_output_enabled: false,
      certificate_enabled: true,
      project_feedback_level: feedback,
    });
    mockGet.mockResolvedValue({
      data: {
        data: {
          id: 1,
          title: 'من الصفر لأول عميل فريلانس',
          is_coming_soon: false,
          ratings_count: 0,
          average_rating: null,
          published_revision: 1,
          metadata: {students_count: 2, duration_minutes: 1},
          access_plans: [
            plan('basic', 'pass_only'),
            plan('guided', 'report'),
            plan('mentor', 'enhanced'),
          ],
          modules: [
            {
              id: 1,
              title: 'وحدة',
              sections: [{id: 1, content_id: 1, title: 'درس', type: 'lesson'}],
            },
          ],
        },
      },
    });
    await expect(getCourseDetails('1')).resolves.toMatchObject({
      id: '1',
      title: 'من الصفر لأول عميل فريلانس',
      ratingAverage: null,
      ratingsCount: 0,
    });
  });

  it.each([false, true])(
    'returns the exact details envelope for a single learning projection (publication changed: %s)',
    async publicationChanged => {
      mockSessionSnapshot = {ready: true, session: null, epoch: 21};
      const plan = (code: string, feedback: string) => ({
        code,
        name: code,
        price_coins: 20,
        minimum_paid_coins: 0,
        chat_enabled: false,
        project_report_enabled: false,
        project_thread_reply_enabled: false,
        project_output_enabled: false,
        certificate_enabled: true,
        project_feedback_level: feedback,
      });
      const responsePayload = {
        data: {
          id: 7,
          title: 'كورس ركن',
          is_coming_soon: false,
          ratings_count: 0,
          average_rating: null,
          published_revision: 1,
          metadata: {students_count: 2, duration_minutes: 1},
          access_plans: [
            plan('basic', 'pass_only'),
            plan('guided', 'report'),
            plan('mentor', 'enhanced'),
          ],
          modules: [
            {
              id: 1,
              title: 'وحدة',
              sections: [{id: 1, content_id: 1, title: 'درس', type: 'lesson'}],
            },
          ],
        },
      };
      if (publicationChanged) {
        mockGet.mockRejectedValueOnce({
          status: 409,
          data: {code: 'course_revision_changed'},
        });
      }
      mockGet.mockResolvedValue({data: responsePayload});

      await expect(getCourseDetailsSnapshot('7')).resolves.toEqual({
        course: expect.objectContaining({id: '7'}),
        responsePayload,
      });
      expect(mockGet).toHaveBeenCalledTimes(publicationChanged ? 2 : 1);
      for (const [, config] of mockGet.mock.calls) {
        expect(config.optionalAuthorization).toBe(true);
        expect(config.roknNetworkRetryDeadlineAt).toBe(
          mockGet.mock.calls[0][1].roknNetworkRetryDeadlineAt,
        );
      }
    },
  );
});
