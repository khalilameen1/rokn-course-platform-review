import type {Course} from '../../types/Course';
import {isApiRecord} from './common';
import {displayText} from './courseFields';
import type {CourseDto, CourseTagDto} from './courseContractTypes';

export const usableCourseTags = (value: unknown): CourseTagDto[] =>
  Array.isArray(value)
    ? value.filter(
        (tag): tag is CourseTagDto =>
          isApiRecord(tag) &&
          Boolean(displayText(tag.name_ar) || displayText(tag.name_en)),
      )
    : [];

export const courseCategory = (course: CourseDto): Course['category'] => {
  const labels = usableCourseTags(course.tags)
    .flatMap(tag => [displayText(tag.name_ar), displayText(tag.name_en)])
    .filter(Boolean)
    .join(' ')
    .toLowerCase();
  if (labels.includes('لغة') || labels.includes('language')) return 'language';
  if (
    labels.includes('دين') ||
    labels.includes('قرآن') ||
    labels.includes('relig')
  ) {
    return 'religious';
  }
  return 'freelance';
};
