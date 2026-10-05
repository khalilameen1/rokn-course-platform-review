import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert} from 'react-native';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {
  clearProductFeedbackDraft,
  loadProductFeedbackDraft,
  loadProductFeedbackDraftConflicts,
  type FeedbackAttachment,
  type ProductFeedbackCategory,
  type ProductFeedbackDraft,
  type ProductFeedbackReceipt,
  persistProductFeedbackReceipt,
  restoreProductFeedbackDraftConflict,
  saveProductFeedbackDraft,
  submitProductFeedback,
} from '../../services/productFeedback';
import {secureRandomUuid} from '../../utils/secureRandom';
import {useFeedbackScreenshotPreparation} from './useFeedbackScreenshotPreparation';

type Options = {
  focused?: boolean;
  identityKey: string;
  locale: string;
  sourceScreen: string;
};

export const useFeedbackComposer = ({
  focused = true,
  identityKey,
  locale,
  sourceScreen,
}: Options) => {
  const appActive = useAppForegroundState();
  const [category, setCategory] = useState<ProductFeedbackCategory>('problem');
  const [message, setMessage] = useState('');
  const [attachment, setAttachment] = useState<FeedbackAttachment>();
  const [includeDiagnostics, setIncludeDiagnostics] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);
  const [draftReady, setDraftReady] = useState(false);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const [draftRestoreError, setDraftRestoreError] = useState(false);
  const [draftRestoreRevision, setDraftRestoreRevision] = useState(0);
  const [draftSourceScreen, setDraftSourceScreen] = useState(sourceScreen);
  const [clientRequestId, setClientRequestId] = useState(secureRandomUuid);
  const [receipt, setReceipt] = useState<ProductFeedbackReceipt>();
  const [trackingRecoveryNeeded, setTrackingRecoveryNeeded] = useState(false);
  const mountedRef = useRef(true);
  const submitFlightRef = useRef(false);
  const submitGenerationRef = useRef(0);
  const dataOwnerRef = useRef(identityKey);
  const draftOwnerScopeRef = useRef('');
  const draftRestoreGenerationRef = useRef(0);
  const draftDirtyRef = useRef(false);
  const draftWritableRef = useRef(false);
  draftWritableRef.current =
    draftReady && !sent && !busy && !trackingRecoveryNeeded;
  const discardedAttachmentsRef = useRef<FeedbackAttachment[]>([]);
  const persistDraft = useCallback(
    async (
      draft: Parameters<typeof saveProductFeedbackDraft>[0],
      boundary: AccountSessionBoundary,
    ) => {
      const discarded = discardedAttachmentsRef.current;
      await saveProductFeedbackDraft(draft, boundary, discarded);
      if (
        draftSnapshotRef.current.clientRequestId === draft.clientRequestId &&
        draftOwnerScopeRef.current === boundary.scope
      )
        draftDirtyRef.current = false;
      discardedAttachmentsRef.current = discardedAttachmentsRef.current.filter(
        file => !discarded.includes(file),
      );
    },
    [],
  );
  const draftSnapshotRef = useRef({
    attachment,
    category,
    clientRequestId,
    includeDiagnostics,
    message,
    sourceScreen: draftSourceScreen,
    updatedAt: Date.now(),
  });
  draftSnapshotRef.current = {
    attachment,
    category,
    clientRequestId,
    includeDiagnostics,
    message,
    sourceScreen: draftSourceScreen,
    updatedAt: Date.now(),
  };

  const changeDraft = (
    change: Partial<
      Pick<
        ProductFeedbackDraft,
        'attachment' | 'category' | 'includeDiagnostics' | 'message'
      >
    >,
  ) => {
    if (
      !draftWritableRef.current ||
      submitFlightRef.current ||
      !mountedRef.current ||
      dataOwnerRef.current !== identityKey
    )
      return;
    const next = {
      ...draftSnapshotRef.current,
      ...change,
      clientRequestId: secureRandomUuid(),
      sourceScreen,
      updatedAt: Date.now(),
    };
    draftDirtyRef.current = true;
    setAttachment(next.attachment);
    setCategory(next.category);
    setMessage(next.message);
    setIncludeDiagnostics(next.includeDiagnostics);
    setClientRequestId(next.clientRequestId);
    setDraftSourceScreen(next.sourceScreen);
    setReceipt(undefined);
    setError('');
    // Back navigation can tear down the form before a React render commits.
    draftSnapshotRef.current = next;
  };

  const screenshot = useFeedbackScreenshotPreparation({
    ownerKey: identityKey,
    canPrepare: () =>
      dataOwnerRef.current === identityKey &&
      draftWritableRef.current &&
      draftReady &&
      !busy &&
      !submitFlightRef.current &&
      !sent &&
      !trackingRecoveryNeeded,
    onPrepared: selected => {
      const previous = draftSnapshotRef.current.attachment;
      if (previous) discardedAttachmentsRef.current.push(previous);
      changeDraft({attachment: selected});
    },
  });
  const invalidateScreenshot = screenshot.invalidate;

  const canSubmit = useMemo(
    () =>
      draftReady &&
      message.trim().length >= 10 &&
      !busy &&
      !screenshot.preparing &&
      !trackingRecoveryNeeded,
    [busy, draftReady, message, screenshot.preparing, trackingRecoveryNeeded],
  );

  const saveCurrentDraft = useCallback(async () => {
    if (
      !draftWritableRef.current ||
      !draftDirtyRef.current ||
      submitFlightRef.current ||
      dataOwnerRef.current !== identityKey
    )
      return;
    const ownerScope = draftOwnerScopeRef.current;
    if (!ownerScope) return;
    const restoreGeneration = draftRestoreGenerationRef.current;
    const snapshot = {...draftSnapshotRef.current, updatedAt: Date.now()};
    const ownsSnapshot = () =>
      dataOwnerRef.current === identityKey &&
      restoreGeneration === draftRestoreGenerationRef.current &&
      snapshot.clientRequestId === draftSnapshotRef.current.clientRequestId;
    try {
      const boundary = await captureAccountSessionBoundary();
      if (
        !ownsSnapshot() ||
        !draftWritableRef.current ||
        submitFlightRef.current
      )
        return;
      if (boundary.scope !== ownerScope)
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      await persistDraft(snapshot, boundary);
      if (mountedRef.current && ownsSnapshot()) setDraftSaveError(false);
    } catch (saveError) {
      if (
        mountedRef.current &&
        ownsSnapshot() &&
        !(
          saveError instanceof Error &&
          saveError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        )
      )
        setDraftSaveError(true);
    }
  }, [identityKey, persistDraft]);
  const flushDraftRef = useRef(saveCurrentDraft);
  flushDraftRef.current = saveCurrentDraft;

  useEffect(() => {
    if (dataOwnerRef.current === identityKey) return;
    dataOwnerRef.current = identityKey;
    submitGenerationRef.current += 1;
    submitFlightRef.current = false;
    draftWritableRef.current = false;
    draftDirtyRef.current = false;
    draftOwnerScopeRef.current = '';
    discardedAttachmentsRef.current = [];
    setCategory('problem');
    setMessage('');
    setAttachment(undefined);
    setIncludeDiagnostics(false);
    setBusy(false);
    setError('');
    setSent(false);
    setDraftReady(false);
    setDraftSaveError(false);
    setClientRequestId(secureRandomUuid());
    setReceipt(undefined);
    setTrackingRecoveryNeeded(false);
    setDraftSourceScreen(sourceScreen);
  }, [identityKey, sourceScreen]);

  useEffect(() => {
    let active = true;
    draftWritableRef.current = false;
    invalidateScreenshot();
    draftRestoreGenerationRef.current += 1;
    setDraftReady(false);
    setDraftRestoreError(false);
    const generation = submitGenerationRef.current;
    let ownerBoundary: AccountSessionBoundary | null = null;
    void captureAccountSessionBoundary()
      .then(async boundary => {
        ownerBoundary = boundary;
        return {
          boundary,
          values: await Promise.all([
            loadProductFeedbackDraft(boundary),
            loadProductFeedbackDraftConflicts(boundary),
          ]),
        };
      })
      .then(({boundary, values: [draft, conflicts]}) => {
        assertAccountSessionBoundary(boundary);
        if (
          !active ||
          generation !== submitGenerationRef.current ||
          dataOwnerRef.current !== identityKey
        ) {
          return;
        }
        draftOwnerScopeRef.current = boundary.scope;
        draftDirtyRef.current = false;
        if (draft) {
          setCategory(draft.category);
          setMessage(draft.message);
          setAttachment(draft.attachment);
          setClientRequestId(draft.clientRequestId);
          setIncludeDiagnostics(draft.includeDiagnostics);
          setDraftSourceScreen(draft.sourceScreen || sourceScreen);
        }
        setDraftReady(true);
        const alternative = conflicts.find(conflict => conflict.type === 'new');
        if (!alternative) return;
        Alert.alert(
          'توجد مسودة أخرى',
          'يمكنك استعادة المسودة التي كتبتها قبل تسجيل الدخول',
          [
            {text: 'الاحتفاظ بالحالية', style: 'cancel'},
            {
              text: 'استعادة الأخرى',
              onPress: () => {
                const restoreOwnerScope = ownerBoundary?.scope;
                if (
                  !restoreOwnerScope ||
                  !active ||
                  generation !== submitGenerationRef.current
                )
                  return;
                draftRestoreGenerationRef.current += 1;
                draftWritableRef.current = false;
                invalidateScreenshot();
                setDraftReady(false);
                void (async () => {
                  try {
                    const restoreBoundary =
                      await captureAccountSessionBoundary();
                    if (restoreBoundary.scope !== restoreOwnerScope) {
                      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
                    }
                    if (!active || generation !== submitGenerationRef.current)
                      return;
                    const restored = await restoreProductFeedbackDraftConflict(
                      alternative.id,
                      restoreBoundary,
                    );
                    if (
                      !mountedRef.current ||
                      generation !== submitGenerationRef.current ||
                      dataOwnerRef.current !== identityKey
                    ) {
                      return;
                    }
                    if (!restored) throw new Error('DRAFT_RESTORE_UNAVAILABLE');
                    const value = await loadProductFeedbackDraft(
                      restoreBoundary,
                    );
                    if (
                      !mountedRef.current ||
                      generation !== submitGenerationRef.current ||
                      dataOwnerRef.current !== identityKey
                    ) {
                      return;
                    }
                    if (!value) throw new Error('DRAFT_RESTORE_UNAVAILABLE');
                    draftDirtyRef.current = false;
                    setCategory(value.category);
                    setMessage(value.message);
                    setAttachment(value.attachment);
                    setClientRequestId(value.clientRequestId);
                    setIncludeDiagnostics(value.includeDiagnostics);
                    setDraftSourceScreen(value.sourceScreen || sourceScreen);
                    setDraftReady(true);
                  } catch {
                    if (
                      mountedRef.current &&
                      generation === submitGenerationRef.current &&
                      dataOwnerRef.current === identityKey
                    ) {
                      setDraftRestoreError(true);
                    }
                  }
                })();
              },
            },
          ],
        );
      })
      .catch(() => {
        if (active && generation === submitGenerationRef.current) {
          setDraftRestoreError(true);
        }
      });

    return () => {
      active = false;
    };
  }, [identityKey, sourceScreen, draftRestoreRevision, invalidateScreenshot]);

  useEffect(() => {
    if (
      !draftReady ||
      sent ||
      busy ||
      trackingRecoveryNeeded ||
      !draftDirtyRef.current
    )
      return;
    const timer = setTimeout(() => void saveCurrentDraft(), 250);

    return () => clearTimeout(timer);
  }, [
    attachment,
    busy,
    category,
    clientRequestId,
    draftReady,
    draftSourceScreen,
    includeDiagnostics,
    message,
    saveCurrentDraft,
    sent,
    trackingRecoveryNeeded,
  ]);

  useEffect(() => {
    if (!appActive || !focused) void saveCurrentDraft();
  }, [
    appActive,
    focused,
    saveCurrentDraft,
    clientRequestId,
    draftReady,
    busy,
    sent,
    trackingRecoveryNeeded,
  ]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      void flushDraftRef.current();
      submitGenerationRef.current += 1;
    };
  }, []);

  const removeScreenshot = () => {
    if (screenshot.isPreparing() || busy) return;
    const previous = draftSnapshotRef.current.attachment;
    if (previous) discardedAttachmentsRef.current.push(previous);
    changeDraft({attachment: undefined});
  };

  const submit = async () => {
    if (
      !canSubmit ||
      screenshot.isPreparing() ||
      submitFlightRef.current ||
      !mountedRef.current ||
      dataOwnerRef.current !== identityKey
    )
      return;
    const generation = submitGenerationRef.current;
    const pendingDraft = {
      ...draftSnapshotRef.current,
      updatedAt: Date.now(),
    } satisfies Parameters<typeof saveProductFeedbackDraft>[0];
    submitFlightRef.current = true;
    draftWritableRef.current = false;
    setBusy(true);
    setError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const ownerScope = draftOwnerScopeRef.current;
      if (ownerScope && boundary.scope !== ownerScope) {
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      }
      draftOwnerScopeRef.current = boundary.scope;
      assertAccountSessionBoundary(boundary);
      try {
        await persistDraft(pendingDraft, boundary);
      } catch {
        if (
          mountedRef.current &&
          generation === submitGenerationRef.current &&
          dataOwnerRef.current === identityKey
        ) {
          setDraftSaveError(true);
          setError('تعذّر حفظ الرسالة على الجهاز\nحرر مساحة ثم حاول مرة أخرى');
        }
        return;
      }
      const received = await submitProductFeedback(
        {
          attachment: pendingDraft.attachment,
          category: pendingDraft.category,
          clientRequestId: pendingDraft.clientRequestId,
          context: {
            includeDiagnostics: pendingDraft.includeDiagnostics,
            locale,
            sourceScreen: pendingDraft.sourceScreen,
          },
          message: pendingDraft.message,
        },
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      if (
        !mountedRef.current ||
        generation !== submitGenerationRef.current ||
        dataOwnerRef.current !== identityKey
      ) {
        return;
      }
      setReceipt(received);
      const needsTracking =
        !received.trackingSaved && !boundary.scope.startsWith('user-');
      setTrackingRecoveryNeeded(needsTracking);
      if (!needsTracking) {
        draftRestoreGenerationRef.current += 1;
        // The account index or durable guest receipt retains access. Cleanup is
        // ancillary and stays on the existing draft queue, even if it is slow.
        void clearProductFeedbackDraft(boundary).catch(() => undefined);
        resetDraft();
      }
      setSent(true);
    } catch (submitError: unknown) {
      if (
        mountedRef.current &&
        generation === submitGenerationRef.current &&
        dataOwnerRef.current === identityKey &&
        !(
          submitError instanceof Error &&
          submitError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        )
      ) {
        setError('تعذّر تأكيد وصول الرسالة\nحاول مرة أخرى\nنصك محفوظ');
      }
    } finally {
      if (generation === submitGenerationRef.current) {
        submitFlightRef.current = false;
        if (mountedRef.current) setBusy(false);
      }
    }
  };

  const resetDraft = () => {
    const requestId = secureRandomUuid();
    draftDirtyRef.current = false;
    setCategory('problem');
    setMessage('');
    setAttachment(undefined);
    setIncludeDiagnostics(false);
    setClientRequestId(requestId);
    setDraftSourceScreen(sourceScreen);
    setDraftSaveError(false);
    draftSnapshotRef.current = {
      attachment: undefined,
      category: 'problem',
      message: '',
      includeDiagnostics: false,
      clientRequestId: requestId,
      sourceScreen,
      updatedAt: Date.now(),
    };
  };

  const retryTracking = async () => {
    if (!receipt || !trackingRecoveryNeeded || submitFlightRef.current) return;
    const generation = submitGenerationRef.current;
    submitFlightRef.current = true;
    setBusy(true);
    try {
      const boundary = await captureAccountSessionBoundary();
      if (boundary.scope !== draftOwnerScopeRef.current) return;
      const saved = await persistProductFeedbackReceipt(receipt, boundary);
      if (
        !saved ||
        !mountedRef.current ||
        generation !== submitGenerationRef.current ||
        dataOwnerRef.current !== identityKey
      )
        return;
      setTrackingRecoveryNeeded(false);
      draftWritableRef.current = false;
      draftRestoreGenerationRef.current += 1;
      void clearProductFeedbackDraft(boundary).catch(() => undefined);
      resetDraft();
    } catch {
      // The receipt remains received and its original guest recovery draft is
      // untouched. This action retries tracking only, never the server send.
    } finally {
      if (generation === submitGenerationRef.current) {
        submitFlightRef.current = false;
        if (mountedRef.current) setBusy(false);
      }
    }
  };

  const restoreOwner = draftRestoreGenerationRef.current;
  return {
    attachment,
    busy,
    canSubmit,
    category,
    chooseScreenshot: screenshot.choose,
    preparingAttachment: screenshot.preparing,
    dismissReceipt: () => setSent(false),
    draftSaveError,
    draftRestoreError,
    retryDraftRestore: () => {
      if (
        !draftReady &&
        draftRestoreError &&
        !busy &&
        mountedRef.current &&
        dataOwnerRef.current === identityKey &&
        restoreOwner === draftRestoreGenerationRef.current
      )
        setDraftRestoreRevision(value => value + 1);
    },
    error,
    includeDiagnostics,
    message,
    receipt: dataOwnerRef.current === identityKey ? receipt : undefined,
    receiptId: receipt?.caseNumber || '',
    receiptPublicId: receipt?.publicId || '',
    retryTracking,
    trackingRecoveryNeeded,
    ready: draftReady,
    removeScreenshot,
    selectCategory: (value: ProductFeedbackCategory) =>
      changeDraft({category: value}),
    setIncludeDiagnostics: (value: boolean) =>
      changeDraft({includeDiagnostics: value}),
    sent,
    setMessage: (value: string) => changeDraft({message: value}),
    submit,
  };
};
