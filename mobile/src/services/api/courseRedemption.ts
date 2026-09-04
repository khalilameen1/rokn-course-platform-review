import {publicRequest} from '../../constants/api';
import {normalizeHumanIdentifier} from '../../utils/unicodeText';
import {numericCourseId} from './courseAccessValidation';
import {payload, valueAsBoolean} from './common';

type CourseRedemptionDto = {
  code?: unknown;
  type?: unknown;
  access_type?: unknown;
  learning_access?: unknown;
  already_enrolled?: unknown;
  chat_available?: unknown;
  certificate_available?: unknown;
  course?: {id?: unknown; name?: unknown};
};

export const redeemCourseCode = async (
  code: string,
  expectedCourseId?: string,
) => {
  const normalizedCode = normalizeHumanIdentifier(code);
  if (!normalizedCode || normalizedCode.length > 100) {
    throw new Error('INVALID_COURSE_CODE');
  }
  const expectedCourseIdValue = expectedCourseId
    ? numericCourseId(expectedCourseId)
    : undefined;
  const data = payload<CourseRedemptionDto>(
    await publicRequest.post('course-codes/redeem', {
      code: normalizedCode,
      course_id: expectedCourseIdValue,
    }),
  );
  const returnedCourseId =
    data.course?.id === null || data.course?.id === undefined
      ? null
      : String(data.course.id);
  if (
    expectedCourseIdValue !== undefined &&
    returnedCourseId !== String(expectedCourseIdValue)
  ) {
    throw new Error('COURSE_CODE_CONTRACT_MISMATCH');
  }
  return {
    code: normalizeHumanIdentifier(data.code || normalizedCode),
    type: String(data.type || ''),
    accessType: data.access_type ? String(data.access_type) : undefined,
    learningAccess: valueAsBoolean(data.learning_access),
    alreadyEnrolled: valueAsBoolean(data.already_enrolled),
    chatAvailable: valueAsBoolean(data.chat_available),
    certificateAvailable:
      data.certificate_available === undefined
        ? undefined
        : valueAsBoolean(data.certificate_available),
    courseId: returnedCourseId,
    courseName: data.course?.name ? String(data.course.name) : '',
  };
};
