import {formatArabicDisplayText} from '../../constants/arabicFormatting';
import type {Course} from '../../types/Course';

/** Discovery cards show one public label, never the learner's progress. */
export const courseCatalogueLabel = (
  course: Pick<Course, 'published' | 'coinPrice' | 'label'>,
  sectionTitle?: string,
): string => {
  const label = formatArabicDisplayText(
    course.published === false
      ? 'قريبًا'
      : course.coinPrice === 0
      ? 'مجاني'
      : course.label,
  );
  return label === formatArabicDisplayText(sectionTitle) ? '' : label;
};
