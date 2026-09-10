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

  it('keeps one public catalogue label while learner ownership and progress stay out of discovery', () => {
    const courseCard = source('src/components/view/CourseCard.tsx');
    const carouselCard = source('src/components/view/CarouselItem.tsx');

    for (const card of [courseCard, carouselCard]) {
      expect(card).not.toContain('CoinAmount');
      expect(card).not.toContain("from '../ui/RoknCoin'");
      expect(card).not.toMatch(/value=\{(?:item|course)\.coinPrice/);
      expect(card).not.toMatch(/\$\{(?:item|course)\.coinPrice\}/);
      expect(card).toContain('courseCatalogueLabel');
      expect(card).not.toMatch(/(?:item|course)\.(owned|started|progress)/);
      expect(card).not.toMatch(
        /ضمن كورساتك|قيد التعلّم|راجع الكورس|progressTrack/,
      );
    }
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
    expect(carouselCard).toContain('onPress={onButtonPress}');
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

  it('keeps home pagination at the feed boundary and opts search into rail pagination', () => {
    const homeFeed = source('src/screens/home/HomeCatalogueFeed.tsx');
    const section = source('src/components/view/CoursesSection.tsx');
    const catalogueHook = source(
      'src/screens/home/usePublishedCourseCatalogue.ts',
    );

    const homeRows = homeFeed.match(
      /\{sections\.map\(section => \([\s\S]*?\)\)\}/,
    )?.[0];
    expect(homeRows).toBeDefined();
    expect(homeRows).not.toContain('onLoadMore');
    expect(section).toContain('onEndReached={onLoadMore}');
    expect(homeFeed).toContain('data={searchMatches}');
    expect(homeFeed).toContain(
      'active && hasMore && !loadingMore && !loadMoreError',
    );
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
