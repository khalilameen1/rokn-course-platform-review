import {
  socialAuthFailureCode,
  socialAuthMessage,
} from '../src/services/socialAuthErrors';

describe('social auth failure presentation', () => {
  it('preserves only the backend diagnostic code', () => {
    expect(
      socialAuthFailureCode({
        response: {
          status: 422,
          data: {
            code: 'social_login_pkce_mismatch',
            message: 'free-form provider response must stay private',
          },
        },
      }),
    ).toBe('SOCIAL_LOGIN_PKCE_MISMATCH');

    expect(
      socialAuthFailureCode({
        status: 410,
        data: {code: 'social_login_expired'},
      }),
    ).toBe('SOCIAL_LOGIN_EXPIRED');
  });

  it('distinguishes network and provider failures', () => {
    expect(socialAuthFailureCode({code: 'ERR_NETWORK'})).toBe(
      'NETWORK_UNAVAILABLE',
    );
    expect(socialAuthFailureCode({response: {status: 503, data: {}}})).toBe(
      'PROVIDER_UNAVAILABLE',
    );
    expect(socialAuthFailureCode({code: 'ETIMEDOUT'})).toBe('NETWORK_TIMEOUT');
    expect(socialAuthMessage('NETWORK_TIMEOUT')).toBe(
      'الاتصال بطيء\nحاول مرة أخرى',
    );
    expect(
      socialAuthFailureCode(
        new Error('SESSION_STORAGE_UNAVAILABLE_DEADLINE'),
      ),
    ).toBe('SESSION_STORAGE_UNAVAILABLE_DEADLINE');
    expect(socialAuthMessage('SESSION_STORAGE_UNAVAILABLE_DEADLINE')).toBe(
      'تعذّر حفظ تسجيل الدخول\nأغلق التطبيق وافتحه ثم حاول',
    );
  });

  it('keeps learner copy short and hides cancelled attempts', () => {
    expect(socialAuthMessage('LOGIN_CANCELLED')).toBe('');
    expect(socialAuthMessage('SOCIAL_LOGIN_EXPIRED')).toBe(
      'انتهت محاولة الدخول\nحاول مرة أخرى',
    );
    expect(socialAuthMessage('LOGIN_FAILED')).toBe(
      'لم يكتمل تسجيل الدخول\nحاول مرة أخرى',
    );
    expect(socialAuthMessage('LOGIN_BROWSER_UNAVAILABLE')).toBe(
      'تعذّر فتح صفحة الدخول\nحاول مرة أخرى',
    );
    expect(socialAuthMessage('SOCIAL_PROVIDER_UNAVAILABLE')).toBe(
      'تعذّر الوصول إلى الحساب\nحاول مرة أخرى',
    );
  });
});
