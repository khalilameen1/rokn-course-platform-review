import type {Course} from '../../types/Course';
import type {ApiRecord} from './common';

export type CourseTagDto = {
  id?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  show_on_home?: unknown;
  home_order?: unknown;
};

export type CourseResumeDto = {
  available?: unknown;
  lesson_id?: unknown;
  lesson_title?: unknown;
  position_seconds?: unknown;
  duration_seconds?: unknown;
  progress_percentage?: unknown;
  watched_at?: unknown;
};

export type CourseSectionDto = ApiRecord & {content?: ApiRecord};
export type CourseModuleDto = ApiRecord & {sections?: CourseSectionDto[]};
export type CourseAccessPlanDto = ApiRecord;
export type CourseTeacherDto = {
  name?: unknown;
  bio?: unknown;
  job_title?: unknown;
  image?: unknown;
};

export type CourseDto = ApiRecord & {
  access_type?: unknown;
  tags?: CourseTagDto[];
  resume?: CourseResumeDto;
  next_section?: ApiRecord | null;
  modules?: CourseModuleDto[];
  teachers?: CourseTeacherDto[];
  access_plans?: CourseAccessPlanDto[];
  metadata?: ApiRecord;
  catalog_badge?: ApiRecord;
  rating_eligibility?: ApiRecord;
  user_rating?: ApiRecord;
};

export type PublishedCoursesPage = {
  courses: Course[];
  page: number;
  hasMore: boolean;
  total: number;
  fromCache: boolean;
  revision: number;
  reset?: boolean;
};

export type CourseModulePreview = {
  id: string;
  title: string;
  reelCount: number;
  projectCount: number;
  previewReelCount: number;
  items: Array<{
    id: string;
    title: string;
    type: 'reel' | 'project' | 'other';
    isPreview: boolean;
    reelNumber?: number;
    reelId?: string;
  }>;
};

export type CourseAccessPlan = {
  code: 'basic' | 'guided' | 'mentor' | string;
  name: string;
  priceCoins: number;
  minimumPaidCoins?: number;
  chatEnabled: boolean;
  chatMessageLimit: number;
  projectFeedbackLevel: 'pass_only' | 'report' | 'enhanced' | string;
  projectReportEnabled: boolean;
  projectFollowupEnabled?: boolean;
  projectFollowupMessageLimit?: number;
  projectFollowupTokenBudget?: number;
  projectOutputEnabled: boolean;
  certificateEnabled: boolean;
};

export type CourseDetails = {
  id: string;
  publishedRevision: number;
  title: string;
  description: string;
  imageUrl?: string;
  price: number | null;
  instructor: string;
  instructorBio: string;
  instructorImage?: string;
  owned: boolean;
  started: boolean;
  modules: CourseModulePreview[];
  reelCount: number;
  projectCount: number;
  previewReelCount: number;
  ratingAverage: number | null;
  ratingsCount: number;
  userRating: number | null;
  ratingVersion?: number;
  ratingEligible?: boolean;
  ratingEligibilityReason?: string;
  studentsCount: number;
  durationMinutes: number | null;
  accessPlans: CourseAccessPlan[];
  fromCache?: boolean;
};

export type CourseProgress = {
  id: string;
  title: string;
  started: boolean;
  imageUrl?: string;
  progress: number;
  completedSections: number;
  totalSections: number;
  category: Course['category'];
  accessType?: string;
  chatAvailable: boolean;
  certificateAvailable: boolean;
  lastLessonId?: string;
  lastLessonTitle?: string;
  resumePositionSeconds?: number;
  resumeDurationSeconds?: number;
  nextSectionId?: string;
  nextSectionTitle?: string;
  nextSectionType?: string;
  lastWatchedAt?: string;
};
