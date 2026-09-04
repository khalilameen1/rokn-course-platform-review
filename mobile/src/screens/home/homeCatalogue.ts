import {normalizeText} from '../../utils/searchText';
import type {Course} from '../../types/Course';
import {recommendCourses} from '../../services/courseRecommendations';

export type HomeCourseSection = {
  id: string;
  title: string;
  data: Course[];
};

const byHomeOrder = (first: Course, second: Course) =>
  (first.homeSortOrder ?? 100) - (second.homeSortOrder ?? 100) ||
  first.title.localeCompare(second.title, 'ar') ||
  String(first.id).localeCompare(String(second.id), 'en', {numeric: true});

export const buildHomeSections = ({
  catalogue,
}: {
  catalogue: Course[];
}): HomeCourseSection[] => {
  const ownedCourses = catalogue
    .filter(course => course.owned && course.published !== false)
    .sort((first, second) => {
      const progressOrder =
        Number(second.progress || 0) - Number(first.progress || 0);
      return progressOrder || byHomeOrder(first, second);
    });
  const continueCourses = ownedCourses.filter(
    course =>
      course.started === true &&
      Number(course.progress || 0) < 100,
  );
  const rowMap = new Map<
    string,
    {id: string; title: string; order: number; data: Course[]}
  >();

  catalogue.forEach(course => {
    if (course.published === false) return;
    course.homeRows?.forEach(row => {
      const current = rowMap.get(row.id) ?? {
        id: `classification-${row.id}`,
        title: row.title,
        order: row.order,
        data: [],
      };
      if (!current.data.some(item => item.id === course.id)) {
        current.data.push(course);
      }
      current.order = Math.min(current.order, row.order);
      rowMap.set(row.id, current);
    });
  });

  const configuredRows = [...rowMap.values()]
    .map(row => ({...row, data: row.data.sort(byHomeOrder)}))
    .sort(
      (first, second) =>
        first.order - second.order ||
        first.title.localeCompare(second.title, 'ar'),
    );
  const unassigned = catalogue.filter(course => !course.homeRows?.length);
  const unassignedPublished = unassigned
    .filter(course => course.published !== false)
    .sort(byHomeOrder);
  const upcoming = catalogue
    .filter(course => course.published === false)
    .sort(byHomeOrder);

  return [
    continueCourses.length
      ? {
          id: 'continue-learning',
          title: 'أكمل من مكانك',
          data: continueCourses,
        }
      : null,
    ...configuredRows,
    unassignedPublished.length
      ? {id: 'published', title: 'كورسات مختارة لك', data: unassignedPublished}
      : null,
    upcoming.length
      ? {id: 'upcoming', title: 'قريبًا في ركن', data: upcoming}
      : null,
  ].filter((section): section is HomeCourseSection => Boolean(section));
};

export const selectHeroCourses = (catalogue: Course[]): Course[] =>
  [
    catalogue.find(
      course => course.published !== false && course.isMainCourse === true,
    ) ?? catalogue.find(course => course.published !== false),
  ].filter((course): course is Course => Boolean(course));

export const selectHomeRecommendations = (
  catalogue: Course[],
  heroCourses: Course[],
): Course[] => {
  const preferredCategories = catalogue
    .filter(course => course.owned === true || Number(course.progress || 0) > 0)
    .map(course => course.category);

  return recommendCourses(catalogue, {
    excludedCourseIds: heroCourses.map(course => course.id),
    preferredCategories,
  });
};

export const buildQuickSearches = (
  catalogue: Course[],
  defaults: string[],
): string[] =>
  Array.from(
    new Set([
      ...catalogue.flatMap(course =>
        (course.homeRows || []).map(row => row.title),
      ),
      ...defaults,
    ]),
  )
    .filter(Boolean)
    .slice(0, 6);

export const searchHomeCatalogue = ({
  catalogue,
  remoteCourses,
  searchQuery,
  loadedSearchQuery,
}: {
  catalogue: Course[];
  remoteCourses: Course[] | null;
  searchQuery: string;
  loadedSearchQuery: string;
}): Course[] => {
  const query = normalizeText(searchQuery);
  if (!query) return [];
  const resultQuery = normalizeText(loadedSearchQuery);
  const remoteBelongsToCurrentQuery =
    remoteCourses !== null && resultQuery === query;

  // A server search may match keywords and descriptions that are deliberately
  // absent from the compact card. Filtering that result again on the phone
  // creates false empty states. Once the response arrives, it is authoritative.
  if (remoteBelongsToCurrentQuery) {
    return Array.from(
      new Map(catalogue.map(course => [course.id, course])).values(),
    );
  }
  return [];
};
