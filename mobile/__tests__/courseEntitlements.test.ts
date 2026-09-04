import {
  includesCourseAssistant,
  includesCourseCertificate,
  isGrantCourseAccess,
} from '../src/components/VideoPlayer/courseEntitlements';

describe('course AI entitlement', () => {
  it.each(['scholarship', 'institutional_grant'])(
    'keeps %s access out of variable-cost chat',
    accessType => {
      expect(isGrantCourseAccess(accessType)).toBe(true);
      expect(includesCourseAssistant({accessType, chatAvailable: true})).toBe(
        false,
      );
    },
  );

  it('keeps an explicitly granted full-access course code distinct from a scholarship', () => {
    expect(isGrantCourseAccess('course_code')).toBe(false);
    expect(
      includesCourseAssistant({accessType: 'course_code', chatAvailable: true}),
    ).toBe(true);
  });

  it('requires an explicit server grant for a paid course', () => {
    expect(
      includesCourseAssistant({accessType: 'paid', chatAvailable: true}),
    ).toBe(true);
    expect(includesCourseAssistant({accessType: 'paid'})).toBe(false);
  });

  it('requires explicit server capability instead of local fixture flags', () => {
    expect(includesCourseAssistant({})).toBe(false);
  });

  it('keeps scholarship certificates locked until a full-track upgrade', () => {
    expect(
      includesCourseCertificate({
        accessType: 'scholarship',
        certificateAvailable: false,
      }),
    ).toBe(false);
    expect(
      includesCourseCertificate({
        accessType: 'paid',
        certificateAvailable: true,
      }),
    ).toBe(true);
  });
});
