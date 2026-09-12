import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  formatArabicRatings,
  formatArabicStudents,
} from '../src/constants/arabicFormatting';

jest.mock('../src/screens/CourseDetails/Lessons', () => () => null);
jest.mock('../src/components/ui/Skeleton', () => ({
  CourseDetailsSkeleton: () => null,
}));
jest.mock('../src/components/ui/PremiumUI', () => ({StatusView: () => null}));
jest.mock('../src/components/ui/CourseArtwork', () => ({
  CourseArtwork: () => null,
}));

import {CourseIntro} from '../src/screens/CourseDetails/details/sections';

describe('course intro renders only real, valid social proof', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
  });

  const render = async (
    metrics: {
      ratingAverage: number | null;
      ratingsCount: number;
      studentsCount: number;
    },
    ready = true,
  ) => {
    await act(async () => {
      renderer = TestRenderer.create(
        <CourseIntro
          courseTitle="بيانات اختبار محلية"
          durationMinutes={null}
          onPreview={jest.fn()}
          pageReady={ready}
          remoteCourse={null}
          remoteError=""
          showSecondaryPreview={false}
          {...metrics}
        />,
      );
    });
    return JSON.stringify(renderer!.toJSON());
  };

  it('shows an honest zero-rating state without invented students or stars', async () => {
    const output = await render({
      ratingAverage: null,
      ratingsCount: 0,
      studentsCount: 0,
    });
    expect(output).toContain('لا توجد تقييمات');
    expect(output).not.toContain('★');
    expect(output).not.toMatch(/طلاب|طالب/);
  });

  it('renders genuine server counts and average without adding social proof', async () => {
    const output = await render({
      ratingAverage: 4.5,
      ratingsCount: 23,
      studentsCount: 640,
    });
    expect(output).toContain(formatArabicRatings(23));
    expect(output).toContain(formatArabicStudents(640));
    expect(output).toContain('التقييم ٤٫٥ من ٥');
    expect(output).not.toContain('لا توجد تقييمات');
  });

  it('does not display invalid cached values as one student or a nine-star rating', async () => {
    const output = await render({
      ratingAverage: 9,
      ratingsCount: 1,
      studentsCount: true as unknown as number,
    });
    expect(output).toContain('التقييمات غير متاحة');
    expect(output).not.toContain('★');
    expect(output).not.toMatch(/طلاب|طالب/);
  });

  it('does not show catalogue guesses while the actual details are unavailable', async () => {
    const output = await render(
      {ratingAverage: 4.5, ratingsCount: 23, studentsCount: 640},
      false,
    );
    expect(output).not.toContain(formatArabicRatings(23));
    expect(output).not.toContain(formatArabicStudents(640));
    expect(output).not.toContain('★');
  });
});
