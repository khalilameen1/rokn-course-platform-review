import type {ImageSourcePropType} from 'react-native';

export type CourseBadgeTone = 'neutral' | 'primary' | 'coin' | 'success';

/** Catalogue course presented by the production API. */
export interface Course {
  id: string;
  title: string;
  description: string;
  instructor: string;
  image: ImageSourcePropType;
  label?: string;
  labelTone?: CourseBadgeTone;
  isMainCourse?: boolean;
  homeSortOrder?: number;
  homeRows?: Array<{id: string; title: string; order: number}>;
  coinPrice?: number;
  durationMinutes?: number;
  ratingAverage?: number;
  ratingsCount?: number;
  studentsCount?: number;
  progress?: number;
  started?: boolean;
  category: 'freelance' | 'skills' | 'language' | 'values' | 'religious';
  owned?: boolean;
  published?: boolean;
}
