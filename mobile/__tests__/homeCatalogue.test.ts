import type {Course} from '../src/types/Course';
import {
  buildHomeSections,
  buildQuickSearches,
  searchHomeCatalogue,
  selectHeroCourses,
} from '../src/screens/home/homeCatalogue';

const course = (
  id: string,
  overrides: Partial<Course> = {},
): Course => ({
  id,
  title: id,
  description: `وصف ${id}`,
  instructor: 'مدرب',
  image: 1,
  category: 'skills',
  published: true,
  ...overrides,
});

describe('home catalogue presentation', () => {
  test('builds configured, continuation, and upcoming rows', () => {
    const learning = course('learning', {
      owned: true,
      progress: 35,
      started: true,
    });
    const classified = course('classified', {
      homeRows: [{id: 'design', title: 'التصميم', order: 2}],
    });
    const upcoming = course('upcoming', {published: false});

    const rows = buildHomeSections({
      catalogue: [upcoming, classified, learning],
    });

    expect(rows.map(row => row.id)).toEqual([
      'continue-learning',
      'classification-design',
      'published',
      'upcoming',
    ]);
    expect(rows[0].data).toEqual([learning]);
  });

  test('does not invent continuation from ownership or progress without canonical start', () => {
    const staleProgress = course('stale-progress', {
      owned: true,
      progress: 35,
      started: false,
    });
    const ownedNotStarted = course('owned', {
      owned: true,
      progress: 0,
      started: false,
    });

    expect(
      buildHomeSections({catalogue: [staleProgress, ownedNotStarted]}).some(
        row => row.id === 'continue-learning',
      ),
    ).toBe(false);
  });

  test('selects a published hero and searches normalized Arabic text', () => {
    const hidden = course('hidden', {published: false, isMainCourse: true});
    const hero = course('hero', {
      title: 'صناعة المحتوى',
      isMainCourse: true,
    });

    expect(selectHeroCourses([hidden, hero])).toEqual([hero]);
    expect(
      searchHomeCatalogue({
        catalogue: [hero],
        remoteCourses: [hero],
        searchQuery: 'المحتوي',
        loadedSearchQuery: 'المحتوي',
      }),
    ).toEqual([hero]);
  });

  test('deduplicates server row names before building quick searches', () => {
    const first = course('first', {
      homeRows: [{id: 'skills', title: 'المهارات', order: 1}],
    });
    const second = course('second', {
      homeRows: [{id: 'skills', title: 'المهارات', order: 1}],
    });

    expect(buildQuickSearches([first, second], ['العمل الحر'])).toEqual([
      'المهارات',
      'العمل الحر',
    ]);
  });

  test('does not present local or older results as an authoritative search', () => {
    const oldResult = course('old', {title: 'التسويق'});

    expect(
      searchHomeCatalogue({
        catalogue: [oldResult],
        remoteCourses: [oldResult],
        searchQuery: 'احمد',
        loadedSearchQuery: 'التسويق',
      }),
    ).toEqual([]);
  });
});
