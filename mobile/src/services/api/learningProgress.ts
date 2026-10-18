import {publicRequest} from '../../constants/api';

/** Learning progress writes. Delivery and retry live in their outbox adapters. */
export const completeCourseSection = (courseId: string, sectionId: string) =>
  publicRequest.post(`courses/${courseId}/sections/${sectionId}/complete`);
