const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

const normalizedCode = (value: unknown): string => {
  if (typeof value !== 'string') return '';
  const code = value
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64);
  return /^[A-Z0-9][A-Z0-9._-]{0,63}$/.test(code) ? code : '';
};

/**
 * Convert provider, Axios and app failures to a closed diagnostic code.
 * Provider tokens, callback URLs and free-form server messages never escape.
 */
export const socialAuthFailureCode = (error: unknown): string => {
  const root = asRecord(error);
  // The shared Axios interceptor intentionally rejects with response itself
  // when one exists, while transport errors keep Axios' nested shape.
  const response =
    asRecord(root?.response) ??
    (root && ('status' in root || 'data' in root) ? root : null);
  const responseData = asRecord(response?.data);
  const message = error instanceof Error ? error.message : '';
  const messageCode = normalizedCode(message);

  // App-owned failures are already closed diagnostic codes. Classify them
  // before inspecting English fragments such as "timeout" so a keychain
  // deadline is not presented as a network outage.
  if (
    messageCode &&
    (messageCode.startsWith('LOGIN_') ||
      messageCode.startsWith('PROVIDER_') ||
      messageCode.startsWith('SESSION_') ||
      messageCode.startsWith('SOCIAL_LOGIN_') ||
      messageCode.startsWith('NETWORK_'))
  ) {
    return messageCode;
  }

  if (
    root?.code === 'ECONNABORTED' ||
    root?.code === 'ETIMEDOUT' ||
    /timeout/i.test(message)
  ) {
    return 'NETWORK_TIMEOUT';
  }
  if (
    root?.code === 'ERR_NETWORK' ||
    /network|internet|connection/i.test(message)
  ) {
    return 'NETWORK_UNAVAILABLE';
  }

  const backendCode = normalizedCode(
    responseData?.code ?? responseData?.error ?? root?.code,
  );
  if (backendCode) return backendCode;

  const status = Number(response?.status);
  if (status >= 500) return 'PROVIDER_UNAVAILABLE';
  return 'LOGIN_FAILED';
};

export const socialAuthMessage = (code: string): string => {
  if (code === 'LOGIN_CANCELLED' || code === 'LOGIN_RESUMING') return '';
  if (code === 'PROVIDER_NOT_CONFIGURED') {
    return 'طريقة الدخول غير متاحة الآن\nاختر طريقة أخرى';
  }
  if (
    code === 'PROVIDER_UNAVAILABLE' ||
    code === 'SOCIAL_PROVIDER_UNAVAILABLE' ||
    code === 'LOGIN_UNAVAILABLE' ||
    code === 'SOCIAL_LOGIN_UNAVAILABLE'
  ) {
    return 'تعذّر الوصول إلى الحساب\nحاول مرة أخرى';
  }
  if (code === 'LOGIN_BROWSER_UNAVAILABLE') {
    return 'تعذّر فتح صفحة الدخول\nحاول مرة أخرى';
  }
  if (code === 'SOCIAL_LOGIN_IN_PROGRESS') {
    return 'جارٍ إكمال تسجيل الدخول\nحاول بعد قليل';
  }
  if (code === 'SOCIAL_ACCOUNT_CONFLICT') {
    return 'الحساب مرتبط بطريقة دخول أخرى\nتواصل مع الدعم';
  }
  if (code === 'SOCIAL_IDENTITY_VERIFICATION_FAILED') {
    return 'تعذّر التحقق من الحساب\nحاول مرة أخرى';
  }
  if (code === 'DEVICE_LOGIN_DENIED') {
    return 'الحساب مرتبط بجهاز آخر\nتواصل مع الدعم';
  }
  if (code === 'ACCOUNT_DISABLED') {
    return 'الحساب متوقف\nتواصل مع الدعم';
  }
  if (
    code === 'LOGIN_CODE_MISSING' ||
    code === 'LOGIN_URL_INVALID' ||
    code === 'LOGIN_SECURE_FLOW_UNAVAILABLE' ||
    code === 'SOCIAL_LOGIN_EXPIRED' ||
    code === 'SOCIAL_LOGIN_PKCE_REQUIRED' ||
    code === 'SOCIAL_LOGIN_PKCE_MISMATCH'
  ) {
    return 'انتهت محاولة الدخول\nحاول مرة أخرى';
  }
  if (code === 'LOGIN_SESSION_INVALID') {
    return 'لم تكتمل بيانات الحساب\nحاول مرة أخرى';
  }
  if (code.startsWith('SESSION_STORAGE_UNAVAILABLE')) {
    return 'تعذّر حفظ تسجيل الدخول\nأغلق التطبيق وافتحه ثم حاول';
  }
  if (code === 'NETWORK_UNAVAILABLE') {
    return 'تحقق من الاتصال\nثم حاول مرة أخرى';
  }
  if (code === 'NETWORK_TIMEOUT' || code === 'LOGIN_TIMEOUT') {
    return 'الاتصال بطيء\nحاول مرة أخرى';
  }
  return 'لم يكتمل تسجيل الدخول\nحاول مرة أخرى';
};
