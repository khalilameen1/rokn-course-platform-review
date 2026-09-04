import {
  pollCourseAssistantTurn,
  type CourseAssistantTurnResponse,
} from '../courseLearning/assistant';

export const COURSE_CHAT_DEFAULT_POLL_WINDOW_MS = 32_000;
const COURSE_CHAT_MIN_POLL_WINDOW_MS = 10_000;
export const COURSE_CHAT_MAX_POLL_WINDOW_MS = 45_000;
const COURSE_CHAT_MAX_STATUS_PROBES = 36;

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
  const backoffMs = Math.min(
    2500,
    baseWaitMs * Math.pow(1.25, recoveryAttempts),
  );
  const jitterSeed = Array.from(clientRequestId).reduce(
    (sum, character) => sum + character.charCodeAt(0),
    0,
  );
  return Math.round(backoffMs * (0.85 + (jitterSeed % 31) / 100));
};

export const pollAcceptedCourseChatTurn = async ({
  clientRequestId,
  initialResponse,
  isActive,
  onPartial,
  onStatus,
}: {
  clientRequestId: string;
  initialResponse: CourseAssistantTurnResponse;
  isActive: () => boolean;
  onPartial: (text: string) => void;
  onStatus: (response: CourseAssistantTurnResponse) => void;
}) => {
  let response = initialResponse;
  let consecutiveNoProgress = 0;
  let statusProbes = 0;
  const deadlineAt = Date.now() + pollWindowMs(initialResponse);
  let latestPartialText =
    response.partial && response.text ? response.text : '';
  let observedPartialLength = latestPartialText.length;

  while (
    response.code === 'chat_answer_in_progress' &&
    Date.now() < deadlineAt &&
    statusProbes < COURSE_CHAT_MAX_STATUS_PROBES &&
    isActive()
  ) {
    onStatus(response);
    const remainingMs = Math.max(0, deadlineAt - Date.now());
    const waitMs = Math.min(
      remainingMs,
      jitteredWait(response, consecutiveNoProgress, clientRequestId),
    );
    if (waitMs <= 0) break;
    await new Promise<void>(resolve => setTimeout(resolve, waitMs));
    consecutiveNoProgress += 1;
    statusProbes += 1;
    if (!isActive()) break;
    response = await pollCourseAssistantTurn(clientRequestId);
    if (!isActive()) break;
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
    (Date.now() >= deadlineAt || statusProbes >= COURSE_CHAT_MAX_STATUS_PROBES);

  if (response.code === 'chat_answer_in_progress') {
    response = {
      ...response,
      text: latestPartialText
        ? latestPartialText
        : foregroundWaitExpired
        ? 'الرد مستمر\nاستعده بعد قليل'
        : 'الرد قيد التجهيز\nسيظهر عند فتح الشات',
      unavailable: true,
      turnStatus: response.turnStatus || 'queued',
    };
  }

  return {foregroundWaitExpired, response};
};
