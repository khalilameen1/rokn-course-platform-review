import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type SetStateAction,
} from 'react';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  clearProjectFeedbackDraft,
  loadProjectFeedbackDraft,
  saveProjectFeedbackDraft,
  type ProjectFeedbackDraft,
} from '../../../services/projectFeedbackDraft';
import type {ChatAttachmentDraft} from '../types';

type Snapshot = Omit<ProjectFeedbackDraft, 'updatedAt'>;
const emptySnapshot = (): Snapshot => ({text: '', attachments: []});

/** Owns one editor visit, not the lifetime of a network response or report. */
export const useProjectFeedbackDraftEditor = ({
  projectId,
  threadId,
  active,
  appIsActive,
}: {
  projectId: string;
  threadId?: string;
  active: boolean;
  appIsActive: boolean;
}) => {
  const lifecycle = useMemo(
    () => ({
      projectId,
      threadId,
      boundary: null as AccountSessionBoundary | null,
      ready: false,
      snapshot: emptySnapshot(),
      revision: 0,
      writeSequence: 0,
    }),
    [projectId, threadId],
  );
  const currentLifecycle = useRef(lifecycle);
  currentLifecycle.current = lifecycle;
  const mounted = useRef(true);
  const [view, setView] = useState({lifecycle, snapshot: lifecycle.snapshot});
  const [ready, setReady] = useState(false);
  const [saveError, setSaveError] = useState(false);
  const [restoreError, setRestoreError] = useState(false);
  const [restoreAttempt, setRestoreAttempt] = useState(0);
  const snapshot =
    view.lifecycle === lifecycle ? view.snapshot : lifecycle.snapshot;
  const isCurrent = useCallback(
    () => mounted.current && currentLifecycle.current === lifecycle,
    [lifecycle],
  );
  const assertReady = useCallback(() => {
    if (!isCurrent() || !lifecycle.ready || !lifecycle.boundary || !threadId)
      throw new Error('PROJECT_FEEDBACK_DRAFT_NOT_READY');
    assertAccountSessionBoundary(lifecycle.boundary);
    return lifecycle.boundary;
  }, [isCurrent, lifecycle, threadId]);

  const replace = useCallback(
    (next: Snapshot) => {
      lifecycle.snapshot = next;
      lifecycle.revision += 1;
      setView({lifecycle, snapshot: next});
    },
    [lifecycle],
  );

  // All save paths report the same status. Older completions cannot clear a
  // newer failure, or attach their status to the next editor visit.
  const write = useCallback(
    async (clearFiles?: ChatAttachmentDraft[]) => {
      const boundary = lifecycle.boundary;
      if (!lifecycle.ready || !boundary || !threadId) return;
      const revision = lifecycle.revision;
      const sequence = ++lifecycle.writeSequence;
      const publish = (failed: boolean) => {
        if (
          isCurrent() &&
          sequence === lifecycle.writeSequence &&
          revision === lifecycle.revision
        )
          setSaveError(failed);
      };
      try {
        assertAccountSessionBoundary(boundary);
        if (clearFiles) {
          await clearProjectFeedbackDraft(threadId, clearFiles, boundary);
        } else {
          await saveProjectFeedbackDraft(
            threadId,
            {
              ...lifecycle.snapshot,
              updatedAt: Date.now(),
            },
            boundary,
          );
        }
        assertAccountSessionBoundary(boundary);
        publish(false);
      } catch (error) {
        publish(true);
        throw error;
      }
    },
    [isCurrent, lifecycle, threadId],
  );

  const session = useMemo(
    () => ({
      get boundary() {
        return lifecycle.boundary;
      },
      get ready() {
        return lifecycle.ready;
      },
      get snapshot() {
        return lifecycle.snapshot;
      },
      assertReady,
      // The request identity is part of the durable snapshot. Staging always
      // precedes sending, including when an earlier acknowledgement was lost.
      stage: async (next: Snapshot) => {
        assertReady();
        replace(next);
        await write();
        assertReady();
      },
      consume: (requestId: string, files: ChatAttachmentDraft[]) => {
        assertReady();
        if (lifecycle.snapshot.requestId !== requestId) return;
        replace(emptySnapshot());
        void write(files).catch(() => undefined); // write owns the visible error
      },
    }),
    [assertReady, lifecycle, replace, write],
  );

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  useEffect(
    () => () => {
      // Capture this visit's snapshot, not shared refs from the next render.
      // Storage serialization and account checks remain in the persistence service.
      if (lifecycle.ready) void write().catch(() => undefined);
    },
    [lifecycle, write],
  );

  useEffect(() => {
    let cancelled = false;
    const ownerBoundary = lifecycle.boundary;
    lifecycle.ready = false;
    setReady(false);
    setSaveError(false);
    setRestoreError(false);
    if (!threadId) return;
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (cancelled || !isCurrent()) return null;
        // Retry may renew the same account, but cannot adopt another account.
        if (ownerBoundary && ownerBoundary.scope !== boundary.scope)
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        assertAccountSessionBoundary(boundary);
        lifecycle.boundary = boundary;
        return loadProjectFeedbackDraft(threadId, boundary);
      })
      .then(saved => {
        if (cancelled || !isCurrent() || !lifecycle.boundary) return;
        assertAccountSessionBoundary(lifecycle.boundary);
        replace(saved || emptySnapshot());
        lifecycle.ready = true;
        setReady(true);
      })
      .catch(() => {
        if (!cancelled && isCurrent()) setRestoreError(true);
      });
    return () => {
      cancelled = true;
    };
  }, [isCurrent, lifecycle, replace, restoreAttempt, threadId]);

  useEffect(() => {
    if (!ready || !lifecycle.ready) return;
    // Read at execution time: an upload may have staged a newer snapshot.
    const timer = setTimeout(() => {
      void write().catch(() => undefined);
    }, 250);
    return () => clearTimeout(timer);
  }, [lifecycle, ready, snapshot, write]);

  useEffect(() => {
    if (appIsActive || !ready || !lifecycle.ready) return;
    void write().catch(() => undefined);
  }, [appIsActive, lifecycle, ready, snapshot, write]);

  const updateText = useCallback(
    (text: string) => {
      try {
        assertReady();
      } catch {
        return;
      }
      if (text === lifecycle.snapshot.text) return;
      // Editing creates a different message. An ACK for the previous one must
      // never clear this text merely because it arrived late.
      replace({text, attachments: lifecycle.snapshot.attachments});
    },
    [assertReady, lifecycle, replace],
  );
  const updateAttachments = useCallback(
    (value: SetStateAction<ChatAttachmentDraft[]>) => {
      try {
        assertReady();
      } catch {
        return;
      }
      const attachments =
        typeof value === 'function'
          ? value(lifecycle.snapshot.attachments)
          : value;
      replace({text: lifecycle.snapshot.text, attachments});
    },
    [assertReady, lifecycle, replace],
  );
  const retryRestore = () => {
    if (!restoreError || !active || lifecycle.ready || !isCurrent()) return;
    setRestoreAttempt(attempt => attempt + 1);
  };
  const retrySave = () => {
    if (!active || !isCurrent() || !lifecycle.ready) return;
    void write().catch(() => undefined);
  };

  return {
    session,
    draft: snapshot.text,
    attachments: snapshot.attachments,
    ready: ready && lifecycle.ready,
    setDraft: updateText,
    setAttachments: updateAttachments,
    saveError,
    restoreError,
    retryRestore,
    retrySave,
  };
};
