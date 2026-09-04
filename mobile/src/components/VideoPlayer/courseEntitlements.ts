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

export const normalizeCourseAccessType = (value: unknown) =>
  String(value || '')
    .trim()
    .toLowerCase();

export const isGrantCourseAccess = (value: unknown) =>
  GRANT_ACCESS_TYPES.has(normalizeCourseAccessType(value));

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

export const includesCourseCertificate = ({
  accessType,
  certificateAvailable,
  certificateIncluded,
}: CourseAssistantEntitlement) =>
  (certificateIncluded ?? certificateAvailable) === true &&
  !isGrantCourseAccess(accessType);
