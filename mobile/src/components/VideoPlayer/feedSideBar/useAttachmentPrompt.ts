import {useCallback, useEffect, useRef} from 'react';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {peekSecureSession} from '../../../services/secureSession';
import {
  hasSeenAttachmentPrompt,
  markAttachmentPromptSeen,
} from '../attachmentPrompt';
import type {CourseLearningData} from '../types';

// A bottom sheet can take longer than one animation frame to acquire the
// modal host on older Android devices. Do not mistake that delay for a failed
// presentation and enqueue the same prompt again.
const PROMPT_PRESENTATION_GRACE_MS = 5_000;

export function useAttachmentPrompt({
  course,
  currentTime,
  present,
}: {
  course: CourseLearningData;
  currentTime: number;
  present: () => void;
}) {
  const checkedScopeRef = useRef('');
  const checkFlightRef = useRef<symbol | null>(null);
  const scopeGenerationRef = useRef(0);
  const requestedScopeRef = useRef('');
  const visibleScopeRef = useRef('');
  const presentationOwnerRef = useRef<AccountSessionBoundary | null>(null);
  const retryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const attachments = course.attachments;
  const scope = 'course';

  const sessionEpoch = peekSecureSession().epoch;
  const scopeKey = scope ? `${sessionEpoch}:${course.id}:${scope}` : '';
  const promptDue = Boolean(
    course.attachmentPrompt?.enabled &&
      attachments.length &&
      currentTime >= course.attachmentPrompt.atSeconds,
  );

  const requestPresentation = useCallback(
    (owner: AccountSessionBoundary) => {
      if (!scopeKey || !attachments.length) return;
      if (owner.epoch !== sessionEpoch) return;
      try {
        assertAccountSessionBoundary(owner);
      } catch {
        return;
      }
      presentationOwnerRef.current = owner;
      requestedScopeRef.current = scopeKey;
      if (retryTimerRef.current) clearTimeout(retryTimerRef.current);
      retryTimerRef.current = setTimeout(() => {
        retryTimerRef.current = null;
        if (
          requestedScopeRef.current === scopeKey &&
          visibleScopeRef.current !== scopeKey
        ) {
          // Another sheet may have owned the modal host. Let playback progress
          // trigger one fresh attempt after that overlay closes.
          requestedScopeRef.current = '';
          checkedScopeRef.current = '';
          presentationOwnerRef.current = null;
        }
      }, PROMPT_PRESENTATION_GRACE_MS);
      present();
    },
    [attachments.length, present, scopeKey, sessionEpoch],
  );

  const openAttachments = useCallback(() => {
    if (!scope || !attachments.length) return;
    const generation = scopeGenerationRef.current;
    void captureAccountSessionBoundary()
      .then(owner => {
        if (generation === scopeGenerationRef.current)
          requestPresentation(owner);
      })
      .catch(() => undefined);
  }, [attachments.length, requestPresentation, scope]);

  const markAttachmentsVisible = useCallback(() => {
    if (!scope || !scopeKey || visibleScopeRef.current === scopeKey) return;
    const owner = presentationOwnerRef.current;
    if (!owner || owner.epoch !== sessionEpoch) return;
    try {
      assertAccountSessionBoundary(owner);
    } catch {
      return;
    }
    visibleScopeRef.current = scopeKey;
    requestedScopeRef.current = '';
    presentationOwnerRef.current = null;
    if (retryTimerRef.current) {
      clearTimeout(retryTimerRef.current);
      retryTimerRef.current = null;
    }
    // "Seen" means the sheet actually became visible, not merely that a
    // present() call was attempted while another modal owned the host.
    void markAttachmentPromptSeen(course.id, scope, owner).catch(
      () => undefined,
    );
  }, [course.id, scope, scopeKey, sessionEpoch]);

  useEffect(
    () => () => {
      scopeGenerationRef.current += 1;
      checkFlightRef.current = null;
      checkedScopeRef.current = '';
      requestedScopeRef.current = '';
      presentationOwnerRef.current = null;
      if (retryTimerRef.current) clearTimeout(retryTimerRef.current);
      retryTimerRef.current = null;
    },
    [requestPresentation, scopeKey],
  );

  // Leaving the trigger window cancels an unfinished automatic check, but
  // ordinary playback ticks must not retire that same storage read.
  useEffect(
    () => () => {
      checkFlightRef.current = null;
    },
    [promptDue],
  );

  useEffect(() => {
    if (!promptDue || !scope) return;

    const checkId = scopeKey;
    if (checkedScopeRef.current === checkId || checkFlightRef.current) return;
    const flight = Symbol('attachment-prompt-check');
    checkFlightRef.current = flight;
    const ownsCheck = () => checkFlightRef.current === flight;
    void captureAccountSessionBoundary()
      .then(async owner => {
        if (!ownsCheck() || owner.epoch !== sessionEpoch) return;
        assertAccountSessionBoundary(owner);
        const seen = await hasSeenAttachmentPrompt(course.id, scope, owner);
        if (!ownsCheck()) return;
        checkedScopeRef.current = checkId;
        if (
          !seen &&
          requestedScopeRef.current !== checkId &&
          visibleScopeRef.current !== checkId
        ) {
          requestPresentation(owner);
        }
      })
      .catch(() => {
        if (ownsCheck() && checkedScopeRef.current === checkId) {
          checkedScopeRef.current = '';
        }
      })
      .finally(() => {
        if (ownsCheck()) checkFlightRef.current = null;
      });
  }, [
    course.id,
    currentTime,
    promptDue,
    requestPresentation,
    scope,
    scopeKey,
    sessionEpoch,
  ]);

  return {attachments, markAttachmentsVisible, openAttachments};
}
