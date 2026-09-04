import {asRecord} from '../courseLearning/shared';

export type CourseChatTurnPhase =
  | 'submitting'
  | 'checking'
  | 'waiting'
  | 'streaming'
  | 'interrupted'
  | 'completed'
  | 'failed'
  | 'cancelled';

export const courseChatTurnPhase = (status?: string): CourseChatTurnPhase => {
  switch (
    String(status || '')
      .trim()
      .toLowerCase()
  ) {
    case 'submitting':
      return 'submitting';
    case 'checking':
      return 'checking';
    case 'queued':
    case 'sent':
      return 'waiting';
    case 'streaming':
      return 'streaming';
    case 'interrupted':
      return 'interrupted';
    case 'failed':
      return 'failed';
    case 'cancelled':
      return 'cancelled';
    default:
      return 'completed';
  }
};

export const courseChatTurnIsPolling = (status?: string): boolean =>
  ['waiting', 'streaming'].includes(courseChatTurnPhase(status));

export const courseChatTurnShowsActivity = (status?: string): boolean =>
  ['submitting', 'checking', 'waiting', 'streaming'].includes(
    courseChatTurnPhase(status),
  );

export const courseChatTurnIsUnresolved = (status?: string): boolean =>
  ['submitting', 'checking', 'waiting', 'streaming', 'interrupted'].includes(
    courseChatTurnPhase(status),
  );

// A queued turn has reached Rokn but the provider has not started producing
// text yet. Calling that state "typing" is misleading and made an unhealthy
// queue look like a slow human response.
export const courseChatTurnIsActuallyStreaming = (status?: string): boolean =>
  courseChatTurnPhase(status) === 'streaming';

export const courseChatErrorCode = (error: unknown): string => {
  const failure = asRecord(error);
  const response = asRecord(failure.response);
  return String(
    asRecord(failure.data).code || asRecord(response.data).code || '',
  );
};

// Only the backend can know whether a terminal provider attempt is safe to
// replace. Keeping a second mobile allow-list here made new provider failures
// either dead-end or start a duplicate paid turn depending on which side was
// deployed first.
export const courseChatFailureCanStartFreshTurn = (
  canRetry?: boolean,
): boolean => canRetry === true;

export const courseChatFailureHasRetryAction = (
  code?: string,
  canRetry?: boolean,
): boolean => {
  const normalized = String(code || '')
    .trim()
    .toLowerCase();
  return (
    courseChatFailureCanStartFreshTurn(canRetry) ||
    ['chat_answer_in_progress', 'interrupted_turn'].includes(normalized)
  );
};

export const courseChatTurnHasRetryAction = (
  status?: string,
  code?: string,
  canRetry?: boolean,
): boolean => {
  const phase = courseChatTurnPhase(status);
  if (phase === 'interrupted') return true;
  return phase === 'failed' && courseChatFailureHasRetryAction(code, canRetry);
};
