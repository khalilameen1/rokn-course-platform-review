type RetryableProjectFeedbackMessage = {
  errorCode?: string;
  canRetry?: boolean;
  attachments?: ReadonlyArray<{serverId?: string}>;
};

export const projectFeedbackThreadIsPending = (
  messages: ReadonlyArray<{status: string}>,
): boolean =>
  messages.some(message =>
    ['queued', 'sent', 'streaming'].includes(message.status),
  );

export const projectFeedbackMessageCanRetry = (
  message: RetryableProjectFeedbackMessage,
): boolean =>
  message.canRetry === true &&
  (message.attachments || []).every(file => Boolean(file.serverId));

export const projectFeedbackMessageRequiresFreshAttachments = (
  message: RetryableProjectFeedbackMessage,
): boolean =>
  message.canRetry === true &&
  Boolean(message.attachments?.some(file => !file.serverId));

export const projectFeedbackFailureText = (
  code?: string,
  canRetry?: boolean,
): string => {
  const normalized = String(code || '')
    .trim()
    .toLowerCase();
  if (normalized === 'plan_limit_reached') {
    return 'اكتملت رسائل متابعة المشروع في هذه الفئة';
  }
  if (normalized === 'provider_outcome_unknown') {
    return 'تعذّر تأكيد الرد الآن';
  }
  if (canRetry === false) return 'تعذّر الرد الآن';
  return 'تعذّر الرد الآن\nأرسل رسالتك مرة أخرى';
};
