import {
  courseAssistantEntryMode,
  hasCourseLearningAccess,
  includesCourseAssistant,
  includesCourseCertificate,
  isGrantCourseAccess,
} from '../src/components/VideoPlayer/courseEntitlements';

describe('course AI entitlement', () => {
  it.each(['paid', 'free', 'course_code', 'scholarship'])(
    'keeps the assistant entry visible for %s learning access',
    accessType => {
      expect(hasCourseLearningAccess(accessType)).toBe(true);
    },
  );

  it('distinguishes a guest sample from an owned learning entitlement', () => {
    expect(hasCourseLearningAccess('none')).toBe(false);
    expect(hasCourseLearningAccess('preview')).toBe(false);
    expect(
      courseAssistantEntryMode({accessType: 'preview', chatAvailable: false}),
    ).toBe('course_access');
    expect(
      courseAssistantEntryMode({accessType: 'none', chatAvailable: true}),
    ).toBe('course_access');
  });

  it('does not invent a paid upgrade for a wholly free course', () => {
    expect(
      courseAssistantEntryMode({accessType: 'free', chatAvailable: false}),
    ).toBe('unavailable');
    expect(
      courseAssistantEntryMode({accessType: 'free', chatAvailable: true}),
    ).toBe('included');
  });

  it('uses the backend entitlement to distinguish included chat from an upgrade', () => {
    expect(
      courseAssistantEntryMode({accessType: 'paid', chatAvailable: true}),
    ).toBe('included');
    expect(
      courseAssistantEntryMode({accessType: 'paid', chatAvailable: false}),
    ).toBe('upgrade');
    expect(
      courseAssistantEntryMode({
        accessType: 'scholarship',
        chatAvailable: true,
      }),
    ).toBe('upgrade');
  });

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
