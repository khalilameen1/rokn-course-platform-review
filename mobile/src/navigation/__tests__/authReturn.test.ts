import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  acknowledgePendingLoginReturnTo,
  claimPendingLoginReturnTo,
  loginReturnResetState,
  resolveLoginReturnDestination,
  safeLoginReturnToFromRoute,
  savePendingLoginReturnTo,
  shouldPreserveVisibleJourneyAcrossSessionChange,
} from '../authReturn';

beforeEach(async () => {
  await AsyncStorage.clear();
});

describe('safeLoginReturnToFromRoute', () => {
  it('preserves the exact course position without copying arbitrary params', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'Reels',
        params: {
          courseId: ' 42 ',
          reelId: ' 9 ',
          preview: false,
          secret: 'must-not-leak',
        },
      }),
    ).toEqual({
      name: 'Reels',
      params: {
        courseId: '42',
        reelId: '9',
        lessonId: undefined,
        projectId: undefined,
        preview: false,
        openCourseChatUpgrade: false,
        previewCount: undefined,
      },
    });
  });

  it('preserves a reviewed project through social login', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'Reels',
        params: {courseId: '42', projectId: '17'},
      }),
    ).toEqual({
      name: 'Reels',
      params: {
        courseId: '42',
        reelId: undefined,
        lessonId: undefined,
        projectId: '17',
        preview: false,
        openCourseChatUpgrade: false,
        previewCount: undefined,
      },
    });
  });

  it('preserves course detail intent without forcing purchase', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'CourseDetails',
        params: {courseId: '7', resumeReelId: '21'},
      }),
    ).toEqual({
      name: 'CourseDetails',
      params: {
        courseId: '7',
        openCodeRedemption: false,
        openFullTrackUpgrade: false,
        openPurchase: false,
        resumeAfterPreview: false,
        resumeReelId: '21',
      },
    });
  });

  it('preserves only the explicit course-code redemption intent', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'CourseDetails',
        params: {
          courseId: '7',
          openCodeRedemption: true,
          purchaseUrl: 'https://example.test/checkout',
        },
      }),
    ).toEqual({
      name: 'CourseDetails',
      params: {
        courseId: '7',
        openCodeRedemption: true,
        openFullTrackUpgrade: false,
        openPurchase: false,
        resumeAfterPreview: false,
        resumeReelId: undefined,
      },
    });
  });

  it('rejects unsupported or incomplete routes', () => {
    expect(
      safeLoginReturnToFromRoute({
        name: 'NotAllowed',
        params: {redirect: 'Wallet'},
      }),
    ).toBeUndefined();
    expect(
      safeLoginReturnToFromRoute({name: 'Reels', params: {courseId: ' '}}),
    ).toBeUndefined();
  });

  it.each(['Wallet', 'MyCorner', 'Profile', 'Settings'])(
    'keeps the safe parameterless destination %s and drops arbitrary params',
    name => {
      expect(
        safeLoginReturnToFromRoute({
          name,
          params: {secret: 'must-not-leak'},
        }),
      ).toEqual({name});
    },
  );
});

describe('login return navigation policy', () => {
  it('preserves a visible journey only while a guest session becomes authenticated', () => {
    expect(
      shouldPreserveVisibleJourneyAcrossSessionChange('guest', 'user:42'),
    ).toBe(true);
    expect(
      shouldPreserveVisibleJourneyAcrossSessionChange('user:7', 'user:42'),
    ).toBe(false);
    expect(
      shouldPreserveVisibleJourneyAcrossSessionChange('user:42', 'guest'),
    ).toBe(false);
  });

  it('preserves the authenticated destination exactly once', () => {
    const returnTo = safeLoginReturnToFromRoute({
      name: 'CourseDetails',
      params: {courseId: '42', openPurchase: true},
    });

    expect(loginReturnResetState(returnTo, 'authenticated')).toEqual({
      index: 1,
      routes: [{name: 'Home'}, returnTo],
    });
  });

  it('keeps a guest in public course context without purchase or chat loops', () => {
    expect(
      resolveLoginReturnDestination(
        {
          name: 'CourseDetails',
          params: {
            courseId: '42',
            openPurchase: true,
            openCodeRedemption: true,
            purchasePlanCode: 'advanced',
          },
        },
        'guest',
      ),
    ).toEqual({
      name: 'CourseDetails',
      params: {courseId: '42', resumeReelId: undefined},
    });
    expect(
      resolveLoginReturnDestination(
        {
          name: 'Reels',
          params: {courseId: '42', reelId: '7', openCourseChatUpgrade: true},
        },
        'guest',
      ),
    ).toMatchObject({
      name: 'Reels',
      params: {courseId: '42', reelId: '7', preview: true},
    });
  });

  it.each(['EditAccount', 'DeviceSessions', 'Notifications'] as const)(
    'does not return a guest to protected route %s',
    name => {
      expect(resolveLoginReturnDestination({name}, 'guest')).toEqual({
        name: 'Home',
      });
    },
  );
});

describe('durable login return hand-off', () => {
  it('keeps the route until navigation acknowledges the exact receipt', async () => {
    await savePendingLoginReturnTo({
      name: 'CourseDetails',
      params: {courseId: '42', openPurchase: true},
    });
    const claim = await claimPendingLoginReturnTo();
    expect(claim?.returnTo).toMatchObject({
      name: 'CourseDetails',
      params: {courseId: '42', openPurchase: true},
    });
    expect((await claimPendingLoginReturnTo())?.receipt).toBe(claim?.receipt);

    await savePendingLoginReturnTo({name: 'Wallet'});
    expect(await acknowledgePendingLoginReturnTo(claim?.receipt || '')).toBe(
      false,
    );
    expect((await claimPendingLoginReturnTo())?.returnTo).toEqual({
      name: 'Wallet',
    });
  });
});
