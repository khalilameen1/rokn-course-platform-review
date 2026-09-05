import {normalizeText} from '../../utils/searchText';
import type {Course} from '../../types/Course';

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
  const rowMap = new Map<
    string,
    {id: string; title: string; order: number; data: Course[]}
  >();

  catalogue.forEach(course => {
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

  return configuredRows;
};

export const selectHeroCourses = (catalogue: Course[]): Course[] =>
  catalogue.filter(
    course => course.published !== false && course.isMainCourse === true,
  ).slice(0, 1);

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
