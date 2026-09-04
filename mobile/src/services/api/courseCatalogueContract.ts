import type {Course} from '../../types/Course';
import {isApiRecord, valueAsBoolean} from './common';
import {courseCategory, usableCourseTags} from './courseContractShared';
import type {CourseDto} from './courseContractTypes';
import {
  catalogueMetric,
  displayImageUrl,
  displayText,
  nonNegativeNumberOr,
} from './courseFields';

const COURSE_COVER_FALLBACK = require('../../assets/images/courseSlider.jpg');

type CatalogueCourseRecord = {
  id: string;
  title: string;
  description: string;
  instructor: string;
  imageUrl?: string;
  badgeLabel: string;
  badgeTone: string;
  isMainCourse: boolean;
  isComingSoon: boolean;
  homeSortOrder: number;
  homeRows: Array<{id: string; title: string; order: number}>;
  coinPrice?: number;
  durationMinutes?: number;
  ratingAverage?: number;
  ratingsCount: number;
  studentsCount: number;
  category: Course['category'];
};

const publicCatalogueRecord = (item: CourseDto): CatalogueCourseRecord => {
  const tags = usableCourseTags(item.tags);
  const metadata = isApiRecord(item.metadata) ? item.metadata : {};
  const badge = isApiRecord(item.catalog_badge) ? item.catalog_badge : {};
  const teachers = Array.isArray(item.teachers) ? item.teachers : [];
  const price = catalogueMetric(item.price);
  const duration = catalogueMetric(metadata.duration_minutes);
  const ratingAverage = catalogueMetric(item.average_rating);
  return {
    id: String(item.id).trim(),
    title: displayText(item.title),
    description: displayText(item.description),
    instructor: displayText(teachers[0]?.name),
    imageUrl: displayImageUrl(item.image),
    badgeLabel: displayText(badge.label),
    badgeTone: displayText(badge.tone) || 'blue',
    isMainCourse: valueAsBoolean(item.is_main_course),
    isComingSoon: valueAsBoolean(item.is_coming_soon),
    homeSortOrder: nonNegativeNumberOr(item.home_sort_order, 100),
    homeRows: tags
      .filter(
        tag =>
          valueAsBoolean(tag.show_on_home) && /^\d+$/.test(displayText(tag.id)),
      )
      .map(tag => ({
        id: displayText(tag.id),
        title: displayText(tag.name_ar) || displayText(tag.name_en),
        order: nonNegativeNumberOr(tag.home_order, 100),
      }))
      .filter(row => row.title.length > 0),
    ...(price === undefined ? {} : {coinPrice: price}),
    ...(duration === undefined ? {} : {durationMinutes: duration}),
    ...(ratingAverage === undefined ? {} : {ratingAverage}),
    ratingsCount: catalogueMetric(item.ratings_count) ?? 0,
    studentsCount: catalogueMetric(metadata.students_count) ?? 0,
    category: courseCategory({...item, tags}),
  };
};

const searchCatalogueRecord = (item: CourseDto): CatalogueCourseRecord => {
  const duration = catalogueMetric(item.duration_minutes);
  const ratingAverage = catalogueMetric(item.average_rating);
  return {
    id: String(item.course_id).trim(),
    title: displayText(item.title),
    description: '',
    instructor: displayText(item.teacher_name),
    imageUrl: displayImageUrl(item.image),
    badgeLabel: displayText(item.badge),
    badgeTone: displayText(item.badge_tone) || 'neutral',
    isMainCourse: false,
    isComingSoon: valueAsBoolean(item.is_coming_soon),
    homeSortOrder: 100,
    homeRows: [],
    ...(duration === undefined ? {} : {durationMinutes: duration}),
    ...(ratingAverage === undefined ? {} : {ratingAverage}),
    ratingsCount: catalogueMetric(item.ratings_count) ?? 0,
    studentsCount: catalogueMetric(item.students_count) ?? 0,
    category: 'freelance',
  };
};

const mapCourse = (item: CatalogueCourseRecord): Course => ({
  id: item.id,
  title: item.title,
  description: item.description,
  instructor: item.instructor,
  image: item.imageUrl ? {uri: item.imageUrl} : COURSE_COVER_FALLBACK,
  label:
    item.badgeLabel ||
    (item.isComingSoon ? 'قريبًا' : item.isMainCourse ? 'مختار لك' : undefined),
  labelTone: item.badgeLabel
    ? item.badgeTone === 'green'
      ? 'success'
      : item.badgeTone === 'gold'
      ? 'coin'
      : item.badgeTone === 'neutral'
      ? 'neutral'
      : 'primary'
    : item.isComingSoon
    ? 'neutral'
    : 'primary',
  isMainCourse: item.isMainCourse,
  homeSortOrder: item.homeSortOrder,
  homeRows: item.homeRows,
  coinPrice: item.coinPrice,
  durationMinutes: item.durationMinutes,
  ratingAverage: item.ratingAverage,
  ratingsCount: item.ratingsCount,
  studentsCount: item.studentsCount,
  category: item.category,
  owned: false,
  published: !item.isComingSoon,
});

export const mapCatalogueCoursesPayload = (
  rawItems: unknown[],
  search: boolean,
): Course[] => {
  if (rawItems.some(item => !isApiRecord(item))) {
    throw new Error('COURSE_CATALOGUE_CONTRACT_INVALID');
  }
  const records = (rawItems as CourseDto[]).map(item =>
    search ? searchCatalogueRecord(item) : publicCatalogueRecord(item),
  );
  const seenCourseIds = new Set<string>();
  for (const item of records) {
    if (!/^\d+$/.test(item.id) || seenCourseIds.has(item.id) || !item.title) {
      throw new Error('COURSE_CATALOGUE_CONTRACT_INVALID');
    }
    seenCourseIds.add(item.id);
  }
  return records.map(mapCourse);
};
