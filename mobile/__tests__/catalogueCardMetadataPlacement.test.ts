import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('public course metadata placement', () => {
  it('does not render duration, students, or ratings on home catalogue cards', () => {
    const courseCard = source('src/components/view/CourseCard.tsx');
    const carouselCard = source('src/components/view/CarouselItem.tsx');

    expect(courseCard).not.toMatch(
      /item\.(durationMinutes|ratingAverage|ratingsCount|studentsCount)/,
    );
    expect(carouselCard).not.toMatch(
      /course\.(durationMinutes|ratingAverage|ratingsCount|studentsCount)/,
    );
  });

  it('keeps the catalogue decision surface to price or access state', () => {
    const courseCard = source('src/components/view/CourseCard.tsx');
    const carouselCard = source('src/components/view/CarouselItem.tsx');

    expect(courseCard).toContain('value={item.coinPrice!}');
    expect(carouselCard).toContain('value={course.coinPrice}');
    expect(courseCard).toContain('استكمل من مكانك');
    expect(carouselCard).toContain('استكمل من مكانك');
  });

  it('keeps those decision metrics on the course details surface', () => {
    const courseDetails = source('src/screens/CourseDetails/index.tsx');

    expect(courseDetails).toContain('durationMinutes={durationMinutes}');
    expect(courseDetails).toContain('ratingAverage={ratingAverage}');
    expect(courseDetails).toContain('ratingsCount={ratingsCount}');
    expect(courseDetails).toContain('studentsCount={studentsCount}');
  });

  it('opens catalogue cards through details even when the course is owned', () => {
    const courseCard = source('src/components/view/CourseCard.tsx');
    const carouselCard = source('src/components/view/CarouselItem.tsx');

    expect(courseCard).toContain('onPress={() => onPress(item)}');
    expect(courseCard).not.toMatch(/opensLearning\s*\?\s*'Reels'/);
    expect(carouselCard).toContain('title="عرض الكورس"');
    expect(carouselCard).not.toMatch(/course\.owned\s*\?/);
  });

  it('does not keep stale entitlement decoration while Home refreshes', () => {
    const catalogueHook = source('src/screens/home/useHomeCatalogue.ts');
    const publicCatalogue = source(
      'src/screens/home/usePublishedCourseCatalogue.ts',
    );
    const accessOverlay = source('src/screens/home/useCourseAccessOverlay.ts');

    expect(catalogueHook).toContain('usePublishedCourseCatalogue({');
    expect(catalogueHook).toContain('useCourseAccessOverlay({');
    expect(publicCatalogue).not.toContain('hasSession');
    expect(publicCatalogue).not.toContain('identityKey');
    expect(accessOverlay).toContain('setCourses([]);');
    expect(accessOverlay).toContain('ownerRef.current = identityKey;');
    expect(accessOverlay).toContain('ownerRef.current === identityKey');
  });

  it('loads the next catalogue page once from the vertical feed boundary', () => {
    const homeFeed = source('src/screens/home/HomeCatalogueFeed.tsx');
    const section = source('src/components/view/CoursesSection.tsx');
    const catalogueHook = source(
      'src/screens/home/usePublishedCourseCatalogue.ts',
    );

    expect(section).not.toContain('onEndReached');
    expect(homeFeed).not.toContain('onEndReached=');
    expect(catalogueHook).toContain('handleScroll');
    expect(catalogueHook).toContain('(!manualRetry && loadMoreError)');
  });

  it('shows search loading and failures instead of local fallback results', () => {
    const homeFeed = source('src/screens/home/HomeCatalogueFeed.tsx');
    const catalogue = source('src/screens/home/homeCatalogue.ts');

    expect(homeFeed).toContain('loading ? (');
    expect(homeFeed).toContain('description={error}');
    expect(homeFeed).not.toContain('هذه نتائج محفوظة على جهازك');
    expect(catalogue).toContain('if (remoteBelongsToCurrentQuery)');
    expect(catalogue).toContain('return [];');
  });
});
