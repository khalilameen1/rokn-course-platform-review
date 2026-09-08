import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockSnapshot = jest.fn();
const mockRate = jest.fn();
const mockDelete = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getCourseDetailsSnapshot: (...args: unknown[]) => mockSnapshot(...args),
  getWallet: async () => ({}),
  getCoinPackages: async () => [],
  hasSession: async () => true,
  isCourseUnavailableError: () => false,
  rateCourse: (...args: unknown[]) => mockRate(...args),
  deleteCourseRating: (...args: unknown[]) => mockDelete(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'learner', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/services/coinCheckout', () => ({
  subscribeCoinCheckoutCredits: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  mapCoursePayload: () => ({}),
  applyLocalLearningState: async (value: unknown) => value,
}));

import type {CourseDetails} from '../src/services/roknApi';
import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';
import {useCourseRating} from '../src/screens/CourseDetails/details/useCourseRating';

const course = (overrides: Partial<CourseDetails> = {}) =>
  ({
    id: '3',
    title: 'الكورس',
    owned: true,
    price: 0,
    accessPlans: [],
    userRating: 2,
    ratingVersion: 1,
    ratingEligible: true,
    ratingAverage: 3,
    ratingsCount: 2,
    ...overrides,
  } as CourseDetails);
const snapshot = (details: CourseDetails) => ({
  course: details,
  responsePayload: {},
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {resolve, promise};
};

describe('rating acknowledgements and course refresh share one current version', () => {
  let data!: ReturnType<typeof useCourseDetailsData>;
  let rating!: ReturnType<typeof useCourseRating>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const notice = jest.fn();
  const Harness = ({
    courseId = '3',
    identityKey = 'learner',
  }: {
    courseId?: string;
    identityKey?: string;
  }) => {
    data = useCourseDetailsData({courseId, identityKey});
    rating = useCourseRating({
      course: data.course.value,
      courseId,
      identityKey,
      owned: data.course.value?.owned === true,
      serverSession: data.course.session,
      reload: data.course.reload,
      setCourse: data.course.setValue,
      setNotice: notice,
    });
    return null;
  };
  const mount = async (initial = course()) => {
    mockSnapshot.mockResolvedValue(snapshot(initial));
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockSnapshot.mockReset();
    mockRate.mockReset();
    mockDelete.mockReset();
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
  });

  it.each(['create', 'edit', 'delete'] as const)(
    'keeps acknowledged %s over an older foreground refresh while accepting other current course fields',
    async operation => {
      const initial = course(
        operation === 'create' ? {userRating: null, ratingVersion: 0} : {},
      );
      await mount(initial);
      const oldRead = deferred<ReturnType<typeof snapshot>>();
      mockSnapshot.mockReturnValueOnce(oldRead.promise);
      await act(async () => {
        data.course.reload();
      });
      expect(data.course.loading).toBe(false);
      const result = {
        rating: operation === 'delete' ? null : 5,
        version: (initial.ratingVersion ?? 0) + 1,
        averageRating: operation === 'delete' ? 4 : 4.5,
        ratingsCount: operation === 'delete' ? 1 : 2,
      };
      mockRate.mockResolvedValueOnce(result);
      mockDelete.mockResolvedValueOnce(result);
      await act(async () => {
        await (operation === 'delete' ? rating.remove() : rating.submit(5));
      });
      expect(rating.rating).toBe(result.rating);

      await act(async () => {
        oldRead.resolve(
          snapshot({
            ...initial,
            title: 'العنوان المنشور الآن',
            ratingEligible: false,
            ratingEligibilityReason: 'course_access_required',
          }),
        );
      });
      expect(rating.rating).toBe(result.rating);
      expect(data.course.value).toMatchObject({
        userRating: result.rating,
        ratingVersion: result.version,
        ratingAverage: result.averageRating,
        ratingsCount: result.ratingsCount,
        title: 'العنوان المنشور الآن',
        ratingEligible: false,
      });
      expect(mockRate.mock.calls.length + mockDelete.mock.calls.length).toBe(1);
    },
  );

  it.each(['edit', 'delete'] as const)(
    'does not replace a newer rating read with an older %s acknowledgement',
    async operation => {
      await mount();
      const mutation = deferred<{
        rating: number | null;
        version: number;
        averageRating: number;
        ratingsCount: number;
      }>();
      mockRate.mockReturnValueOnce(mutation.promise);
      mockDelete.mockReturnValueOnce(mutation.promise);
      let pending!: Promise<void>;
      await act(async () => {
        pending = operation === 'delete' ? rating.remove() : rating.submit(5);
      });
      mockSnapshot.mockResolvedValueOnce(
        snapshot(
          course({
            userRating: 4,
            ratingVersion: 3,
            ratingAverage: 4,
            ratingsCount: 3,
          }),
        ),
      );
      await act(async () => {
        data.course.reload();
      });
      expect(rating.rating).toBe(4);
      await act(async () => {
        mutation.resolve({
          rating: operation === 'delete' ? null : 5,
          version: 2,
          averageRating: 4.5,
          ratingsCount: 2,
        });
        await pending;
      });
      expect(rating.rating).toBe(4);
      expect(data.course.value).toMatchObject({
        userRating: 4,
        ratingVersion: 3,
        ratingAverage: 4,
        ratingsCount: 3,
      });
      expect(rating.busy).toBe(false);
    },
  );

  it("does not carry a previous student's rating version into the next student's view", async () => {
    await mount(course({userRating: 5, ratingVersion: 9}));
    mockSnapshot.mockResolvedValue(
      snapshot(course({userRating: null, ratingVersion: 0})),
    );
    await act(async () => {
      renderer!.update(<Harness identityKey="second-learner" />);
    });
    expect(rating.rating).toBeNull();
    expect(data.course.value?.ratingVersion).toBe(0);
    mockRate.mockResolvedValueOnce({
      rating: 3,
      version: 1,
      averageRating: 4,
      ratingsCount: 2,
    });
    await act(async () => {
      await rating.submit(3);
    });
    expect(mockRate).toHaveBeenLastCalledWith('3', 3, 0);
    expect(rating.rating).toBe(3);
  });

  it('accepts aggregate changes from other students at the same personal version and later deletion', async () => {
    await mount();
    mockSnapshot.mockResolvedValueOnce(
      snapshot(course({ratingAverage: 4.2, ratingsCount: 12})),
    );
    await act(async () => {
      data.course.reload();
    });
    expect(data.course.value).toMatchObject({
      userRating: 2,
      ratingVersion: 1,
      ratingAverage: 4.2,
      ratingsCount: 12,
    });
    mockSnapshot.mockResolvedValueOnce(
      snapshot(
        course({
          userRating: null,
          ratingVersion: 2,
          ratingAverage: 4.4,
          ratingsCount: 11,
        }),
      ),
    );
    await act(async () => {
      data.course.reload();
    });
    expect(rating.rating).toBeNull();
    expect(data.course.value?.ratingVersion).toBe(2);
  });

  it('keeps aggregates from a read that already observed the mutation over its delayed acknowledgement', async () => {
    await mount();
    const mutation = deferred<{
      rating: number;
      version: number;
      averageRating: number;
      ratingsCount: number;
    }>();
    mockRate.mockReturnValueOnce(mutation.promise);
    let pending!: Promise<void>;
    await act(async () => {
      pending = rating.submit(5);
    });
    mockSnapshot.mockResolvedValueOnce(
      snapshot(
        course({
          userRating: 5,
          ratingVersion: 2,
          ratingAverage: 4.2,
          ratingsCount: 12,
        }),
      ),
    );
    await act(async () => {
      data.course.reload();
    });
    await act(async () => {
      mutation.resolve({
        rating: 5,
        version: 2,
        averageRating: 4.5,
        ratingsCount: 2,
      });
      await pending;
    });
    expect(data.course.value).toMatchObject({
      userRating: 5,
      ratingVersion: 2,
      ratingAverage: 4.2,
      ratingsCount: 12,
    });
  });

  it('ignores an old course mutation after navigation and admits only one tap while pending', async () => {
    await mount();
    const mutation = deferred<{
      rating: number;
      version: number;
      averageRating: number;
      ratingsCount: number;
    }>();
    mockRate.mockReturnValueOnce(mutation.promise);
    let pending!: Promise<void>;
    await act(async () => {
      pending = rating.submit(5);
      void rating.submit(4);
    });
    expect(mockRate).toHaveBeenCalledTimes(1);
    mockSnapshot.mockResolvedValue(
      snapshot(course({id: '4', userRating: 3, ratingVersion: 1})),
    );
    await act(async () => {
      renderer!.update(<Harness courseId="4" />);
    });
    await act(async () => {
      mutation.resolve({
        rating: 5,
        version: 2,
        averageRating: 5,
        ratingsCount: 1,
      });
      await pending;
    });
    expect(data.course.value).toMatchObject({
      id: '4',
      userRating: 3,
      ratingVersion: 1,
    });
    expect(rating.rating).toBe(3);
  });
});
