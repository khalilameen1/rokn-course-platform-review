import {
  isExternalWebLink,
  parseRoknDestination,
  resolveInitialUrlWithinDeadline,
} from '../src/navigation/deepLinks';

describe('Rokn deep links', () => {
  it('opens only the canonical course link', () => {
    expect(parseRoknDestination('rokn://course/42')).toEqual({
      name: 'CourseDetails',
      params: {courseId: '42'},
    });
    expect(parseRoknDestination('/courses/42')).toBeNull();
  });

  it('restores an exact learning step from a notification', () => {
    expect(parseRoknDestination('https://rokn.app/course/42/watch/7')).toEqual({
      name: 'Reels',
      params: {courseId: '42', reelId: '7'},
    });
    expect(parseRoknDestination('rokn://course/42/watch?lesson_id=9')).toEqual({
      name: 'Reels',
      params: {courseId: '42', lessonId: '9'},
    });
    expect(
      parseRoknDestination(
        'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/course/42',
      ),
    ).toBeNull();
  });

  it('opens a reviewed project at its exact course position', () => {
    expect(parseRoknDestination('rokn://course/42/project/17')).toEqual({
      name: 'Reels',
      params: {courseId: '42', projectId: '17'},
    });
    expect(
      parseRoknDestination('rokn://course/42/project/not-an-id'),
    ).toBeNull();
  });

  it('rejects unpublished course-detail aliases', () => {
    expect(parseRoknDestination('rokn://course-details/42')).toBeNull();
    expect(
      parseRoknDestination('https://rokn.app/api/courses/42/details'),
    ).toBeNull();
  });

  it('opens a non-enumerable support case without putting its access token in the link', () => {
    expect(
      parseRoknDestination('rokn://support/01JY7M7QW9WQQRF4S9V4Z0X7GA'),
    ).toEqual({
      name: 'Feedback',
      params: {caseId: '01JY7M7QW9WQQRF4S9V4Z0X7GA'},
    });
    expect(parseRoknDestination('rokn://support/42')).toBeNull();
    expect(
      parseRoknDestination(
        'rokn://support/01JY7M7QW9WQQRF4S9V4Z0X7GA/anything',
      ),
    ).toBeNull();
  });

  it('opens certificate notifications at the certificate tab', () => {
    expect(parseRoknDestination('rokn://profile/certificates')).toEqual({
      name: 'Profile',
      params: {tab: 'certificates'},
    });
    expect(parseRoknDestination('rokn://profile/unknown')).toBeNull();
  });

  it('rejects incomplete internal links instead of navigating silently', () => {
    expect(parseRoknDestination('/course')).toBeNull();
    expect(parseRoknDestination('rokn://unknown')).toBeNull();
    expect(parseRoknDestination('rokn://course/%2e%2e%2fwallet')).toBeNull();
    expect(parseRoknDestination('rokn://course/42/watch/%2fadmin')).toBeNull();
    expect(parseRoknDestination(`rokn://course/${'a'.repeat(129)}`)).toBeNull();
  });

  it('distinguishes external web destinations from Rokn routes', () => {
    expect(isExternalWebLink('https://support.example.org/help')).toBe(true);
    expect(isExternalWebLink('https://rokn.app/course/42')).toBe(false);
    expect(isExternalWebLink('https://rokn.com/course/42')).toBe(true);
    expect(
      isExternalWebLink(
        'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/course/42',
      ),
    ).toBe(true);
    expect(isExternalWebLink('http://support.example.org/help')).toBe(false);
    expect(isExternalWebLink('tel:+201000000000')).toBe(false);
  });

  it('delivers a cold-start link that resolves after the navigation deadline', async () => {
    jest.useFakeTimers();
    let settle!: (value: string | null) => void;
    const nativeInitialUrl = new Promise<string | null>(resolve => {
      settle = resolve;
    });
    const late = jest.fn();
    const initial = resolveInitialUrlWithinDeadline(
      nativeInitialUrl,
      late,
      1_500,
    );

    jest.advanceTimersByTime(1_500);
    await expect(initial).resolves.toBeNull();
    settle('rokn://course/42');
    await Promise.resolve();

    expect(late).toHaveBeenCalledTimes(1);
    expect(late).toHaveBeenCalledWith('rokn://course/42');
    jest.useRealTimers();
  });

  it('does not redeliver an initial link that resolves inside the deadline', async () => {
    jest.useFakeTimers();
    const late = jest.fn();
    await expect(
      resolveInitialUrlWithinDeadline(
        Promise.resolve('rokn://wallet'),
        late,
        1_500,
      ),
    ).resolves.toBe('rokn://wallet');
    expect(late).not.toHaveBeenCalled();
    jest.useRealTimers();
  });
});
