import {
  pollCourseAssistantTurn,
  type CourseAssistantTurnResponse,
} from '../courseLearning/assistant';

export const COURSE_CHAT_DEFAULT_POLL_WINDOW_MS = 32_000;
const COURSE_CHAT_MIN_POLL_WINDOW_MS = 10_000;
// The server includes queue/provider settlement time in this window. A local
// 45-second cutoff can strand a healthy answer that is still being generated.
export const COURSE_CHAT_MAX_POLL_WINDOW_MS = 110_000;
const COURSE_CHAT_MAX_STATUS_PROBES = Math.ceil(
  COURSE_CHAT_MAX_POLL_WINDOW_MS / 700,
);

const pollWindowMs = (response: CourseAssistantTurnResponse) => {
  const serverWindowMs = Number(response.pollWindowSeconds) * 1000;
  if (!Number.isFinite(serverWindowMs) || serverWindowMs <= 0) {
    return COURSE_CHAT_DEFAULT_POLL_WINDOW_MS;
  }
  return Math.max(
    COURSE_CHAT_MIN_POLL_WINDOW_MS,
    Math.min(COURSE_CHAT_MAX_POLL_WINDOW_MS, serverWindowMs),
  );
};

const jitteredWait = (
  response: CourseAssistantTurnResponse,
  recoveryAttempts: number,
  clientRequestId: string,
) => {
  const baseWaitMs = Math.max(
    response.partial ? 700 : 1000,
    (response.retryAfterSeconds || 3) * 1000,
  );
  const backoffMs = Math.max(
    baseWaitMs,
    Math.min(2500, baseWaitMs * Math.pow(1.25, recoveryAttempts)),
  );
  const jitterSeed = Array.from(clientRequestId).reduce(
    (sum, character) => sum + character.charCodeAt(0),
    0,
  );
  return Math.round(backoffMs * (1 + (jitterSeed % 16) / 100));
};

export const pollAcceptedCourseChatTurn = async ({
  clientRequestId,
  initialResponse,
  attemptStartedAt = Date.now(),
  isActive,
  onPartial,
  onStatus,
}: {
  clientRequestId: string;
  initialResponse: CourseAssistantTurnResponse;
  attemptStartedAt?: number;
  isActive: () => boolean;
  onPartial: (text: string) => void;
  onStatus: (response: CourseAssistantTurnResponse) => void;
}) => {
  let response = initialResponse;
  let consecutiveNoProgress = 0;
  let statusProbes = 0;
  const startedAt = attemptStartedAt;
  let deadlineAt = startedAt + pollWindowMs(initialResponse);
  let lastReachableAt = initialResponse.offline ? startedAt : Date.now();
  const currentDeadline = () =>
    response.offline
      ? Math.min(
          deadlineAt,
          lastReachableAt + COURSE_CHAT_DEFAULT_POLL_WINDOW_MS,
        )
      : deadlineAt;
  let latestPartialText =
    response.partial && response.text ? response.text : '';
  let observedPartialLength = latestPartialText.length;

  while (
    response.code === 'chat_answer_in_progress' &&
    Date.now() < currentDeadline() &&
    statusProbes < COURSE_CHAT_MAX_STATUS_PROBES &&
    isActive()
  ) {
    onStatus(response);
    const remainingMs = Math.max(0, currentDeadline() - Date.now());
    const waitMs = Math.min(
      remainingMs,
      jitteredWait(response, consecutiveNoProgress, clientRequestId),
    );
    if (waitMs <= 0) break;
    await new Promise<void>(resolve => setTimeout(resolve, waitMs));
    consecutiveNoProgress += 1;
    statusProbes += 1;
    if (!isActive() || Date.now() >= currentDeadline()) break;
    response = await pollCourseAssistantTurn(clientRequestId);
    if (!isActive()) break;
    if (!response.offline) lastReachableAt = Date.now();
    // A lost send response has no server window yet. Adopt it when the first
    // status read succeeds, always relative to this attempt's original start.
    deadlineAt = Math.max(deadlineAt, startedAt + pollWindowMs(response));
    if (response.partial && response.text) {
      const partialLength = response.text.length;
      if (partialLength > observedPartialLength) {
        observedPartialLength = partialLength;
        latestPartialText = response.text;
        consecutiveNoProgress = 0;
        onPartial(response.text);
      }
    }
  }

  const foregroundWaitExpired =
    response.code === 'chat_answer_in_progress' &&
    isActive() &&
    (Date.now() >= currentDeadline() ||
      statusProbes >= COURSE_CHAT_MAX_STATUS_PROBES);

  if (response.code === 'chat_answer_in_progress') {
    response = {
      ...response,
      text: latestPartialText
        ? latestPartialText
        : foregroundWaitExpired
        ? 'الرد مستمر\nاستعده بعد قليل'
        : '',
      unavailable: true,
      turnStatus: response.turnStatus || 'queued',
    };
  }

  return {foregroundWaitExpired, response};
};
