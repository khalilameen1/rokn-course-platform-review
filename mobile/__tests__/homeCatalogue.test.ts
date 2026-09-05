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
  test('builds only manually configured rows and keeps a course in every selected row', () => {
    const learning = course('learning', {
      owned: true,
      progress: 35,
      started: true,
    });
    const classified = course('classified', {
      homeRows: [
        {id: 'design', title: 'التصميم', order: 2},
        {id: 'popular', title: 'الأكثر طلبًا', order: 1},
      ],
    });
    const upcoming = course('upcoming', {
      published: false,
      homeRows: [{id: 'design', title: 'التصميم', order: 2}],
    });

    const rows = buildHomeSections({
      catalogue: [upcoming, classified, learning],
    });

    expect(rows.map(row => row.id)).toEqual([
      'classification-popular',
      'classification-design',
    ]);
    expect(rows[0].data).toEqual([classified]);
    expect(rows[1].data).toEqual([classified, upcoming]);
    expect(rows.flatMap(row => row.data)).not.toContain(learning);
  });

  test('does not invent home rows from ownership, progress, publication, or coming soon state', () => {
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

    const published = course('published');
    const upcoming = course('upcoming', {published: false});

    expect(
      buildHomeSections({
        catalogue: [staleProgress, ownedNotStarted, published, upcoming],
      }),
    ).toEqual([]);
  });

  test('selects only an explicitly chosen published hero and searches normalized Arabic text', () => {
    const hidden = course('hidden', {published: false, isMainCourse: true});
    const hero = course('hero', {
      title: 'صناعة المحتوى',
      isMainCourse: true,
    });

    expect(selectHeroCourses([hidden, hero])).toEqual([hero]);
    expect(selectHeroCourses([course('ordinary')])).toEqual([]);
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
