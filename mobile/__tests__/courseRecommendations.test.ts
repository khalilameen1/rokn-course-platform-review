import type {Course} from '../src/types/Course';
import {recommendCourses} from '../src/services/courseRecommendations';

const course = (
  id: string,
  category: Course['category'],
  overrides: Partial<Course> = {},
): Course => ({
  id,
  category,
  description: `Description ${id}`,
  image: 1,
  instructor: 'Rokn',
  published: true,
  title: `Course ${id}`,
  ...overrides,
});

describe('recommendCourses', () => {
  it('does not expose a thin catalogue as a recommendations shelf', () => {
    expect(
      recommendCourses([course('1', 'skills'), course('2', 'freelance')], {
        minimumResults: 3,
      }),
    ).toEqual([]);
  });

  it('excludes unavailable, owned, started, current, and duplicate courses', () => {
    const result = recommendCourses(
      [
        course('current', 'skills'),
        course('owned', 'skills', {owned: true}),
        course('started', 'skills', {progress: 12}),
        course('soon', 'skills', {published: false}),
        course('one', 'skills'),
        course('one', 'freelance'),
        course('two', 'freelance'),
        course('three', 'language'),
      ],
      {excludedCourseIds: ['current'], minimumResults: 3},
    );

    expect(result.map(item => item.id)).toEqual(['one', 'two', 'three']);
  });

  it('is stable while keeping the first suggestions category-diverse', () => {
    const catalogue = [
      course('skills-1', 'skills', {homeSortOrder: 1}),
      course('skills-2', 'skills', {homeSortOrder: 2}),
      course('freelance-1', 'freelance', {homeSortOrder: 3}),
      course('language-1', 'language', {homeSortOrder: 4}),
      course('values-1', 'values', {homeSortOrder: 5}),
    ];
    const first = recommendCourses(catalogue, {minimumResults: 4, limit: 4});
    const second = recommendCourses(catalogue, {minimumResults: 4, limit: 4});

    expect(second.map(item => item.id)).toEqual(first.map(item => item.id));
    expect(new Set(first.slice(0, 3).map(item => item.category)).size).toBe(3);
  });

  it('keeps zero as the highest configured home priority', () => {
    const result = recommendCourses(
      [
        course('priority-zero', 'skills', {homeSortOrder: 0}),
        course('priority-one', 'skills', {homeSortOrder: 1}),
        course('priority-two', 'skills', {homeSortOrder: 2}),
        course('priority-three', 'skills', {homeSortOrder: 3}),
      ],
      {minimumResults: 4, limit: 4},
    );

    expect(result[0].id).toBe('priority-zero');
  });
});
