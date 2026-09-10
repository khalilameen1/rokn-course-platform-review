import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {CourseDetails} from '../src/services/roknApi';

jest.mock('../src/screens/CourseDetails/Lessons', () => () => null);
jest.mock('../src/components/ui/Skeleton', () => ({
  CourseDetailsSkeleton: () => null,
}));
jest.mock('../src/components/ui/PremiumUI', () => ({StatusView: () => null}));
jest.mock('../src/components/ui/CourseArtwork', () => ({
  CourseArtwork: () => null,
}));

import {CourseIntro} from '../src/screens/CourseDetails/details/sections';

const course: CourseDetails = {
  id: 'preview-fixture',
  publishedRevision: 1,
  title: 'التصوير بالموبايل',
  description: 'بيانات اختبار محلية',
  price: 400,
  instructor: 'مدرب ركن',
  instructorBio: '',
  owned: false,
  started: false,
  modules: [],
  reelCount: 10,
  projectCount: 0,
  previewReelCount: 2,
  ratingAverage: null,
  ratingsCount: 0,
  userRating: null,
  studentsCount: 0,
  durationMinutes: null,
  accessPlans: [],
};

describe('CourseIntro preview availability', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const onPreview = jest.fn();
  const intro = (remoteError = '', showSecondaryPreview = true) => (
    <CourseIntro
      courseTitle={course.title}
      durationMinutes={null}
      onPreview={onPreview}
      pageReady
      ratingAverage={null}
      ratingsCount={0}
      remoteCourse={course}
      remoteError={remoteError}
      showSecondaryPreview={showSecondaryPreview}
      studentsCount={0}
    />
  );
  const previewButtons = () =>
    renderer!.root.findAll(
      node =>
        node.props.accessibilityLabel === 'شاهد مجانًا' &&
        typeof node.props.onPress === 'function',
    );

  beforeEach(() => onPreview.mockClear());
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
  });

  it('removes the preview on a refresh error even while previous details remain ready', async () => {
    await act(async () => {
      renderer = TestRenderer.create(intro());
    });
    expect(previewButtons()).toHaveLength(1);
    await act(async () => {
      renderer!.update(intro('تعذّر تجهيز تفاصيل الكورس'));
    });
    expect(previewButtons()).toHaveLength(0);
    expect(onPreview).not.toHaveBeenCalled();

    await act(async () => renderer!.update(intro()));
    expect(previewButtons()).toHaveLength(1);
    await act(async () => previewButtons()[0].props.onPress());
    expect(onPreview).toHaveBeenCalledTimes(1);
  });

  it('does not create a preview action when the presentation disallows it', async () => {
    await act(async () => {
      renderer = TestRenderer.create(intro('', false));
    });
    expect(previewButtons()).toHaveLength(0);
  });
});
