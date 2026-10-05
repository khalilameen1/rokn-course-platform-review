import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from '../../services/learnerDraftFiles';
import {
  clearAcceptedProductFeedbackReplyDraft,
  loadProductFeedbackDraftConflicts,
  loadProductFeedbackReplyDraft,
  restoreProductFeedbackDraftConflict,
  saveProductFeedbackReplyDraft,
  type FeedbackAttachment,
  type ProductFeedbackReplyDraft,
} from '../../services/productFeedback';
import {secureRandomUuid} from '../../utils/secureRandom';

type DraftOwner = {
  identityKey: string;
  caseId: string;
  scope: string;
  ready: boolean;
  dirty: boolean;
  sending: boolean;
  epoch: number;
  snapshot: ProductFeedbackReplyDraft;
  discarded: FeedbackAttachment[];
  referenceOwner: string;
  borrowedFiles: Map<string, FeedbackAttachment>;
  saveFlight?: {requestId: string; promise: Promise<boolean>};
};

export type FeedbackReplySubmission = {
  owner: DraftOwner;
  snapshot: ProductFeedbackReplyDraft;
};

const blankReply = (): ProductFeedbackReplyDraft => ({
  message: '',
  clientRequestId: secureRandomUuid(),
});

/** One local draft per account/case, using the existing durable feedback queue. */
export const useFeedbackReplyDraft = (
  identityKey: string,
  caseId: string,
  focused = true,
) => {
  const appActive = useAppForegroundState();
  const mounted = useRef(true);
  const identity = useRef(identityKey);
  const selected = useRef(caseId);
  identity.current = identityKey;
  selected.current = caseId;
  const owner = useRef<DraftOwner | undefined>(undefined);
  const [revision, setRevision] = useState(0);
  const [view, setView] = useState<{
    owner?: DraftOwner;
    snapshot: ProductFeedbackReplyDraft;
    ready: boolean;
    restoreError: boolean;
    saveError: boolean;
  }>(() => ({
    snapshot: blankReply(),
    ready: false,
    restoreError: false,
    saveError: false,
  }));

  const ownsPresentation = useCallback(
    (candidate: DraftOwner) =>
      mounted.current &&
      owner.current === candidate &&
      identity.current === candidate.identityKey &&
      selected.current === candidate.caseId,
    [],
  );

  const publish = useCallback(
    (
      candidate: DraftOwner,
      errors?: {restoreError?: boolean; saveError?: boolean},
    ) => {
      if (!ownsPresentation(candidate)) return;
      setView(previous => ({
        owner: candidate,
        snapshot: candidate.snapshot,
        ready: candidate.ready,
        restoreError: errors?.restoreError ?? previous.restoreError,
        saveError: errors?.saveError ?? previous.saveError,
      }));
    },
    [ownsPresentation],
  );

  const persist = useCallback(
    async (
      candidate: DraftOwner,
      snapshot: ProductFeedbackReplyDraft,
      boundary: AccountSessionBoundary | Promise<AccountSessionBoundary>,
    ) => {
      const discarded = [...candidate.discarded];
      const protectedBoundary = Promise.resolve(boundary).then(
        async captured => {
          assertAccountSessionBoundary(captured);
          if (snapshot.attachment || candidate.borrowedFiles.size > 0)
            await retainLearnerDraftFiles(
              candidate.referenceOwner,
              snapshot.attachment ? [snapshot.attachment] : [],
              captured.scope,
            );
          assertAccountSessionBoundary(captured);
          return captured;
        },
      );
      await saveProductFeedbackReplyDraft(
        candidate.caseId,
        snapshot,
        protectedBoundary,
        discarded,
      );
      candidate.discarded = candidate.discarded.filter(
        file => !discarded.includes(file),
      );
      if (candidate.snapshot.clientRequestId === snapshot.clientRequestId)
        candidate.dirty = false;
    },
    [],
  );

  const flush = useCallback(
    async (candidate = owner.current): Promise<boolean> => {
      if (
        !candidate?.ready ||
        !candidate.dirty ||
        candidate.sending ||
        !candidate.scope ||
        identity.current !== candidate.identityKey
      )
        return true;
      const snapshot = candidate.snapshot;
      if (candidate.saveFlight?.requestId === snapshot.clientRequestId)
        return candidate.saveFlight.promise;
      const epoch = candidate.epoch;
      const stillOwnsSnapshot = () =>
        identity.current === candidate.identityKey &&
        candidate.epoch === epoch &&
        candidate.snapshot.clientRequestId === snapshot.clientRequestId &&
        !candidate.sending;
      // Reserve the existing queue now, before boundary capture resolves. A
      // same-case reopening must restore behind this final write, not before it.
      const boundary = captureAccountSessionBoundary().then(captured => {
        assertAccountSessionBoundary(captured);
        if (!stillOwnsSnapshot()) throw new Error('OBSOLETE_REPLY_DRAFT');
        if (captured.scope !== candidate.scope)
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        return captured;
      });
      const operation = (async () => {
        try {
          await persist(candidate, snapshot, boundary);
          if (stillOwnsSnapshot()) publish(candidate, {saveError: false});
          return true;
        } catch (error) {
          if (
            stillOwnsSnapshot() &&
            !(
              error instanceof Error &&
              error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
            )
          )
            publish(candidate, {saveError: true});
          return false;
        }
      })();
      candidate.saveFlight = {
        requestId: snapshot.clientRequestId,
        promise: operation,
      };
      void operation.then(() => {
        if (candidate.saveFlight?.promise === operation)
          candidate.saveFlight = undefined;
      });
      return operation;
    },
    [persist, publish],
  );

  useEffect(() => {
    let active = true;
    const candidate: DraftOwner = {
      identityKey,
      caseId,
      scope: '',
      ready: false,
      dirty: false,
      sending: false,
      epoch: 0,
      snapshot: blankReply(),
      discarded: [],
      referenceOwner: `feedback-reply-editor:${secureRandomUuid()}`,
      borrowedFiles: new Map(),
    };
    owner.current = candidate;
    publish(candidate, {restoreError: false, saveError: false});
    const current = () => active && ownsPresentation(candidate);
    const restore = async () => {
      try {
        const boundary = await captureAccountSessionBoundary();
        assertAccountSessionBoundary(boundary);
        if (!current()) return;
        candidate.scope = boundary.scope;
        const [saved, conflicts] = await Promise.all([
          loadProductFeedbackReplyDraft(
            caseId,
            boundary,
            candidate.referenceOwner,
          ),
          loadProductFeedbackDraftConflicts(boundary),
        ]);
        assertAccountSessionBoundary(boundary);
        if (!current()) return;
        candidate.snapshot = saved
          ? {
              ...saved,
              clientRequestId: saved.clientRequestId || secureRandomUuid(),
            }
          : blankReply();
        if (saved?.attachment)
          candidate.borrowedFiles.set(saved.attachment.uri, saved.attachment);
        candidate.ready = true;
        publish(candidate);
        const alternative = conflicts.find(
          conflict => conflict.type === 'reply' && conflict.publicId === caseId,
        );
        if (!alternative) return;
        Alert.alert(
          'توجد مسودة رد أخرى',
          'يمكنك استعادة الرد الذي كتبته قبل تسجيل الدخول',
          [
            {text: 'الاحتفاظ بالحالي', style: 'cancel'},
            {
              text: 'استعادة الآخر',
              onPress: () => {
                if (!current() || !candidate.ready || candidate.sending) return;
                const departure = flush(candidate);
                candidate.ready = false;
                publish(candidate);
                void (async () => {
                  try {
                    const savedCurrent = await departure;
                    if (!current()) return;
                    if (!savedCurrent) {
                      candidate.ready = true;
                      publish(candidate, {saveError: true});
                      return;
                    }
                    candidate.epoch += 1;
                    const restoreBoundary =
                      await captureAccountSessionBoundary();
                    assertAccountSessionBoundary(restoreBoundary);
                    if (restoreBoundary.scope !== candidate.scope)
                      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
                    if (!current()) return;
                    if (
                      !(await restoreProductFeedbackDraftConflict(
                        alternative.id,
                        restoreBoundary,
                      ))
                    )
                      throw new Error('DRAFT_RESTORE_UNAVAILABLE');
                    const value = await loadProductFeedbackReplyDraft(
                      caseId,
                      restoreBoundary,
                      candidate.referenceOwner,
                    );
                    assertAccountSessionBoundary(restoreBoundary);
                    if (!current()) return;
                    if (!value) throw new Error('DRAFT_RESTORE_UNAVAILABLE');
                    candidate.snapshot = {
                      ...value,
                      clientRequestId:
                        value.clientRequestId || secureRandomUuid(),
                    };
                    if (value.attachment)
                      candidate.borrowedFiles.set(
                        value.attachment.uri,
                        value.attachment,
                      );
                    else if (candidate.borrowedFiles.size > 0)
                      await retainLearnerDraftFiles(
                        candidate.referenceOwner,
                        [],
                        candidate.scope,
                      );
                    candidate.dirty = false;
                    candidate.ready = true;
                    publish(candidate, {restoreError: false, saveError: false});
                  } catch {
                    if (current()) publish(candidate, {restoreError: true});
                  }
                })();
              },
            },
          ],
        );
      } catch {
        if (current()) publish(candidate, {restoreError: true});
      }
    };
    const restoration = caseId ? restore() : Promise.resolve();
    return () => {
      active = false;
      // Reserve final persistence immediately, then release this instance's
      // lease only when restore and save have finished. Failure retains the
      // existing registry grace instead of deleting an unsaved live image.
      const departure = flush(candidate);
      void Promise.all([restoration, departure])
        .then(async ([, saved]) => {
          if (!saved || !candidate.scope) return;
          if (candidate.ready && candidate.borrowedFiles.size === 0) return;
          await retainLearnerDraftFiles(
            candidate.referenceOwner,
            [],
            candidate.scope,
          );
          await Promise.all(
            [...candidate.borrowedFiles.values()]
              .filter(file => file.uri !== candidate.snapshot.attachment?.uri)
              .map(file => removeLearnerDraftFile(file)),
          );
        })
        .catch(() => undefined);
    };
  }, [identityKey, caseId, revision, flush, ownsPresentation, publish]);

  useEffect(() => {
    const timer = setTimeout(() => void flush(), 300);
    return () => clearTimeout(timer);
  }, [flush, view.snapshot.clientRequestId]);

  useEffect(() => {
    if (!appActive || !focused) void flush();
  }, [appActive, focused, flush, view.ready, view.snapshot.clientRequestId]);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const canEdit = () => {
    const candidate = owner.current;
    return Boolean(
      identity.current === identityKey &&
        selected.current === caseId &&
        candidate &&
        ownsPresentation(candidate) &&
        candidate.ready &&
        !candidate.sending,
    );
  };
  const change = (
    patch: Partial<Pick<ProductFeedbackReplyDraft, 'message' | 'attachment'>>,
  ) => {
    if (!canEdit()) return;
    const candidate = owner.current!;
    const previous = candidate.snapshot.attachment;
    const snapshot = {
      ...candidate.snapshot,
      ...patch,
      clientRequestId: secureRandomUuid(),
    };
    if (previous && previous.uri !== snapshot.attachment?.uri)
      candidate.discarded.push(previous);
    candidate.snapshot = snapshot;
    if (snapshot.attachment)
      candidate.borrowedFiles.set(snapshot.attachment.uri, snapshot.attachment);
    candidate.dirty = true;
    // Existing native file queue orders this reference update before cleanup.
    if (previous || snapshot.attachment)
      void retainLearnerDraftFiles(
        candidate.referenceOwner,
        snapshot.attachment ? [snapshot.attachment] : [],
        candidate.scope,
      ).catch(() => {
        if (candidate.snapshot.clientRequestId === snapshot.clientRequestId)
          publish(candidate, {saveError: true});
      });
    publish(candidate);
  };

  const presented = Boolean(
    view.owner &&
      view.owner === owner.current &&
      view.owner.identityKey === identityKey &&
      view.owner.caseId === caseId,
  );
  return {
    attachment: presented ? view.snapshot.attachment : undefined,
    message: presented ? view.snapshot.message : '',
    ready: presented && view.ready,
    restoreError: presented && view.restoreError,
    saveError: presented && view.saveError,
    canEdit,
    change,
    retryRestore: () => {
      if (
        view.restoreError &&
        view.owner === owner.current &&
        identity.current === identityKey &&
        selected.current === caseId &&
        owner.current &&
        !owner.current.sending &&
        ownsPresentation(owner.current)
      )
        setRevision(value => value + 1);
    },
    beginSend: (): FeedbackReplySubmission | undefined => {
      if (!canEdit()) return;
      const candidate = owner.current!;
      candidate.sending = true;
      return {owner: candidate, snapshot: candidate.snapshot};
    },
    persistSubmission: (
      submission: FeedbackReplySubmission,
      boundary: AccountSessionBoundary,
    ) => {
      if (boundary.scope !== submission.owner.scope)
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      return persist(submission.owner, submission.snapshot, boundary);
    },
    acceptSubmission: (
      submission: FeedbackReplySubmission,
      boundary: AccountSessionBoundary,
    ) => {
      const candidate = submission.owner;
      candidate.epoch += 1;
      candidate.dirty = false;
      candidate.snapshot = blankReply();
      publish(candidate, {saveError: false});
      if (candidate.borrowedFiles.size > 0)
        void retainLearnerDraftFiles(
          candidate.referenceOwner,
          [],
          candidate.scope,
        )
          .then(() => removeLearnerDraftFile(submission.snapshot.attachment))
          .catch(() => undefined);
      return clearAcceptedProductFeedbackReplyDraft(
        candidate.caseId,
        submission.snapshot.clientRequestId,
        boundary,
        [...candidate.discarded],
      );
    },
    finishSubmission: (submission: FeedbackReplySubmission) => {
      submission.owner.sending = false;
      publish(submission.owner);
    },
  };
};
