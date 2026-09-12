jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn(), delete: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {mapCatalogueCoursesPayload} from '../src/services/api/courseCatalogueContract';
import {mapCourseDetailsPayload} from '../src/services/api/courseDetailsContract';
import {
  deleteCourseRating,
  rateCourse,
} from '../src/services/api/courseRatings';

const details = (overrides: Record<string, unknown> = {}) => ({
  id: 52,
  title: 'بيانات اختبار محلية',
  is_coming_soon: true,
  ratings_count: 0,
  average_rating: null,
  metadata: {students_count: 0},
  ...overrides,
});

describe('course social proof uses server numbers without invented fallbacks', () => {
  beforeEach(() => jest.clearAllMocks());

  it.each([false, true])(
    'preserves genuine catalogue counts and averages (search=%s)',
    search => {
      const [course] = mapCatalogueCoursesPayload(
        [
          {
            id: 52,
            course_id: 52,
            title: 'Course',
            ratings_count: '23',
            average_rating: '4.25',
            metadata: {students_count: '640'},
            students_count: '640',
          },
        ],
        search,
      );
      expect(course).toMatchObject({
        ratingsCount: 23,
        ratingAverage: 4.25,
        studentsCount: 640,
      });
    },
  );

  it.each([false, true])(
    'never adds social proof to empty or absent catalogue metrics (search=%s)',
    search => {
      for (const metrics of [
        {},
        {ratings_count: 0, average_rating: null},
        {ratings_count: 0, average_rating: 4.9},
      ]) {
        const [course] = mapCatalogueCoursesPayload(
          [{id: 52, course_id: 52, title: 'Course', ...metrics}],
          search,
        );
        expect(course.ratingsCount).toBe(0);
        expect(course.studentsCount).toBe(0);
        expect(course.ratingAverage).toBeUndefined();
      }
    },
  );

  it.each([
    true,
    false,
    [200],
    {},
    ' ',
    1.5,
    -1,
    Number.POSITIVE_INFINITY,
    Number.MAX_SAFE_INTEGER + 1,
  ])('does not coerce malformed counts %p into students or ratings', value => {
    for (const search of [false, true]) {
      const [course] = mapCatalogueCoursesPayload(
        [
          {
            id: 52,
            course_id: 52,
            title: 'Course',
            ratings_count: value,
            average_rating: 5,
            students_count: value,
            metadata: {students_count: value},
          },
        ],
        search,
      );
      expect(course.ratingsCount).toBe(0);
      expect(course.studentsCount).toBe(0);
      expect(course.ratingAverage).toBeUndefined();
    }
    expect(() =>
      mapCourseDetailsPayload(details({ratings_count: value})),
    ).toThrow('API_CONTRACT_INVALID_COURSE_DETAILS');
    expect(() =>
      mapCourseDetailsPayload(details({metadata: {students_count: value}})),
    ).toThrow('API_CONTRACT_INVALID_COURSE_DETAILS');
  });

  it.each([true, [5], ' ', 0, 5.1, Number.POSITIVE_INFINITY])(
    'does not manufacture a star average from %p',
    average => {
      const [course] = mapCatalogueCoursesPayload(
        [{id: 52, title: 'Course', ratings_count: 2, average_rating: average}],
        false,
      );
      expect(course.ratingAverage).toBeUndefined();
      expect(() =>
        mapCourseDetailsPayload(
          details({ratings_count: 2, average_rating: average}),
        ),
      ).toThrow('API_CONTRACT_INVALID_COURSE_DETAILS');
    },
  );

  it('keeps actual details metrics and genuine zero without inflating them', () => {
    expect(mapCourseDetailsPayload(details())).toMatchObject({
      ratingsCount: 0,
      ratingAverage: null,
      studentsCount: 0,
      userRating: null,
    });
    expect(
      mapCourseDetailsPayload(
        details({
          ratings_count: '8',
          average_rating: '4.5',
          metadata: {students_count: '17'},
          user_rating: {rating: 4},
        }),
      ),
    ).toMatchObject({
      ratingsCount: 8,
      ratingAverage: 4.5,
      studentsCount: 17,
      userRating: 4,
    });
    expect(
      mapCourseDetailsPayload(details({user_rating: {rating: true}}))
        .userRating,
    ).toBeNull();
    expect(
      mapCourseDetailsPayload(details({user_rating: {rating: 1.5}})).userRating,
    ).toBeNull();
  });

  it('does not synthesize rating aggregates after an acknowledgement or deletion', async () => {
    (publicRequest.post as jest.Mock).mockResolvedValue({
      data: {
        data: {rating: 5, version: 1, ratings_count: 8, average_rating: 4.5},
      },
    });
    await expect(rateCourse('52', 5, 0)).resolves.toMatchObject({
      rating: 5,
      ratingsCount: 8,
      averageRating: 4.5,
    });
    (publicRequest.delete as jest.Mock).mockResolvedValue({
      data: {
        data: {
          rating: null,
          version: 2,
          ratings_count: 0,
          average_rating: null,
        },
      },
    });
    await expect(deleteCourseRating('52', 1)).resolves.toMatchObject({
      rating: null,
      ratingsCount: 0,
      averageRating: null,
    });
  });

  it.each([
    {ratings_count: true},
    {average_rating: [5]},
    {average_rating: 9},
    {rating: true},
    {version: true},
  ])(
    'rejects malformed rating response %p instead of displaying a made-up aggregate',
    async overrides => {
      (publicRequest.post as jest.Mock).mockResolvedValue({
        data: {
          data: {
            rating: 1,
            version: 1,
            ratings_count: 1,
            average_rating: 1,
            ...overrides,
          },
        },
      });
      await expect(rateCourse('52', 1, 0)).rejects.toThrow(
        'COURSE_RATING_CONTRACT_INVALID',
      );
    },
  );
});
