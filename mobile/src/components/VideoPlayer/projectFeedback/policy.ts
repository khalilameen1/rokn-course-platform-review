const RETRYABLE_PROJECT_FEEDBACK_FAILURES = new Set([
  'provider_unavailable',
  'request_interrupted',
  'attachment_unavailable',
]);

export const projectFeedbackThreadIsPending = (
  messages: ReadonlyArray<{status: string}>,
): boolean =>
  messages.some(message =>
    ['queued', 'sent', 'streaming'].includes(message.status),
  );

export const projectFeedbackFailureHasRetryAction = (code?: string): boolean =>
  RETRYABLE_PROJECT_FEEDBACK_FAILURES.has(
    String(code || '')
      .trim()
      .toLowerCase(),
  );

export const projectFeedbackFailureText = (code?: string): string => {
  const normalized = String(code || '')
    .trim()
    .toLowerCase();
  if (normalized === 'plan_limit_reached') {
    return 'اكتملت رسائل متابعة المشروع في هذه الفئة';
  }
  if (normalized === 'provider_outcome_unknown') {
    return 'تعذّر تأكيد الرد الآن';
  }
  return 'تعذّر الرد الآن\nأرسل رسالتك مرة أخرى';
};
