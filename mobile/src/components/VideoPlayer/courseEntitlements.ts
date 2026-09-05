export type CourseAssistantEntitlement = {
  accessType?: string;
  chatAvailable?: boolean;
  certificateAvailable?: boolean;
  certificateIncluded?: boolean;
};

const GRANT_ACCESS_TYPES = new Set([
  'scholarship',
  'grant',
  'institutional',
  'institutional_grant',
]);

const LEARNING_ACCESS_TYPES = new Set([
  'paid',
  'scholarship',
  'grant',
  'institutional',
  'institutional_grant',
  'course_code',
  'free',
]);

export const normalizeCourseAccessType = (value: unknown) =>
  String(value || '')
    .trim()
    .toLowerCase();

export const isGrantCourseAccess = (value: unknown) =>
  GRANT_ACCESS_TYPES.has(normalizeCourseAccessType(value));

/** A purchased or granted course may expose the assistant entry point. */
export const hasCourseLearningAccess = (value: unknown) =>
  LEARNING_ACCESS_TYPES.has(normalizeCourseAccessType(value));

/**
 * Default closed: the backend must explicitly grant this variable-cost feature.
 * That prevents an old or partially deployed response from exposing a composer
 * that will only fail after the learner has typed a question.
 */
export const includesCourseAssistant = ({
  accessType,
  chatAvailable,
}: CourseAssistantEntitlement) =>
  chatAvailable === true && !isGrantCourseAccess(accessType);

export type CourseAssistantEntryMode =
  | 'included'
  | 'upgrade'
  | 'course_access'
  | 'unavailable';

/**
 * The player always exposes the enquiries entry point, while this server-fed
 * entitlement snapshot decides what opening it may do. A public sample must
 * return to course access, and a wholly free course must not invent a paid
 * upgrade when chat was not included by the backend.
 */
export const courseAssistantEntryMode = (
  entitlement: CourseAssistantEntitlement,
): CourseAssistantEntryMode => {
  if (!hasCourseLearningAccess(entitlement.accessType)) return 'course_access';
  if (includesCourseAssistant(entitlement)) return 'included';
  return normalizeCourseAccessType(entitlement.accessType) === 'free'
    ? 'unavailable'
    : 'upgrade';
};

export const includesCourseCertificate = ({
  accessType,
  certificateAvailable,
  certificateIncluded,
}: CourseAssistantEntitlement) =>
  (certificateIncluded ?? certificateAvailable) === true &&
  !isGrantCourseAccess(accessType);
