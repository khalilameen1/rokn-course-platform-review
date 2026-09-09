import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert} from 'react-native';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import {
  clearProductFeedbackDraft,
  loadProductFeedbackDraft,
  loadProductFeedbackDraftConflicts,
  type FeedbackAttachment,
  type ProductFeedbackCategory,
  type ProductFeedbackReceipt,
  persistProductFeedbackReceipt,
  restoreProductFeedbackDraftConflict,
  saveProductFeedbackDraft,
  submitProductFeedback,
} from '../../services/productFeedback';
import {secureRandomUuid} from '../../utils/secureRandom';
import {pickFeedbackScreenshot} from './pickFeedbackScreenshot';

type Options = {
  identityKey: string;
  locale: string;
  sourceScreen: string;
};

export const useFeedbackComposer = ({
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
  const pickerFlightRef = useRef(false);
  const submitFlightRef = useRef(false);
  const submitGenerationRef = useRef(0);
  const dataOwnerRef = useRef(identityKey);
  const draftOwnerScopeRef = useRef('');
  const draftRestoreGenerationRef = useRef(0);
  const discardedAttachmentsRef = useRef<FeedbackAttachment[]>([]);
  const persistDraft = useCallback(
    async (
      draft: Parameters<typeof saveProductFeedbackDraft>[0],
      boundary: AccountSessionBoundary,
    ) => {
      const discarded = discardedAttachmentsRef.current;
      await saveProductFeedbackDraft(draft, boundary, discarded);
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

  const canSubmit = useMemo(
    () =>
      draftReady &&
      message.trim().length >= 10 &&
      !busy &&
      !trackingRecoveryNeeded,
    [busy, draftReady, message, trackingRecoveryNeeded],
  );

  useEffect(() => {
    if (dataOwnerRef.current === identityKey) return;
    dataOwnerRef.current = identityKey;
    submitGenerationRef.current += 1;
    submitFlightRef.current = false;
    pickerFlightRef.current = false;
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
  }, [identityKey, sourceScreen, draftRestoreRevision]);

  useEffect(() => {
    if (!draftReady || sent || busy || trackingRecoveryNeeded) return;
    const ownerScope = draftOwnerScopeRef.current;
    if (!ownerScope) return;
    const restoreGeneration = draftRestoreGenerationRef.current;
    const timer = setTimeout(() => {
      void captureAccountSessionBoundary()
        .then(boundary => {
          if (restoreGeneration !== draftRestoreGenerationRef.current) return;
          if (boundary.scope !== ownerScope) {
            throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
          }
          return persistDraft(
            {
              attachment,
              category,
              clientRequestId,
              includeDiagnostics,
              message,
              sourceScreen: draftSourceScreen,
              updatedAt: Date.now(),
            },
            boundary,
          );
        })
        .then(() => {
          if (mountedRef.current) setDraftSaveError(false);
        })
        .catch(saveError => {
          if (
            mountedRef.current &&
            !(
              saveError instanceof Error &&
              saveError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
            )
          ) {
            setDraftSaveError(true);
          }
        });
    }, 250);

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
    persistDraft,
    sent,
    trackingRecoveryNeeded,
  ]);

  useEffect(() => {
    if (appActive || !draftReady || sent || busy || trackingRecoveryNeeded)
      return;
    const ownerScope = draftOwnerScopeRef.current;
    if (!ownerScope) return;
    const restoreGeneration = draftRestoreGenerationRef.current;
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (restoreGeneration !== draftRestoreGenerationRef.current) return;
        if (boundary.scope !== ownerScope) {
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        }
        return persistDraft(
          {
            ...draftSnapshotRef.current,
            updatedAt: Date.now(),
          },
          boundary,
        );
      })
      .catch(saveError => {
        if (
          mountedRef.current &&
          !(
            saveError instanceof Error &&
            saveError.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
          )
        ) {
          setDraftSaveError(true);
        }
      });
  }, [appActive, busy, draftReady, persistDraft, sent, trackingRecoveryNeeded]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      submitGenerationRef.current += 1;
    };
  }, []);

  const changeDraft = (change: () => void) => {
    if (busy || !draftReady || trackingRecoveryNeeded) return;
    change();
    setClientRequestId(secureRandomUuid());
    setDraftSourceScreen(sourceScreen);
    setReceipt(undefined);
    setError('');
  };

  const chooseScreenshot = async () => {
    if (pickerFlightRef.current || busy) return;
    const generation = submitGenerationRef.current;
    const owner = identityKey;
    pickerFlightRef.current = true;
    try {
      const selected = await pickFeedbackScreenshot();
      if (!selected) return;
      if (
        !mountedRef.current ||
        generation !== submitGenerationRef.current ||
        owner !== dataOwnerRef.current
      ) {
        await removeLearnerDraftFile(selected).catch(() => undefined);
        return;
      }
      const previous = attachment;
      changeDraft(() => {
        if (previous)
          discardedAttachmentsRef.current = [
            ...discardedAttachmentsRef.current,
            previous,
          ];
        setAttachment(selected);
      });
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const removeScreenshot = () => {
    if (busy) return;
    const previous = attachment;
    changeDraft(() => {
      if (previous)
        discardedAttachmentsRef.current = [
          ...discardedAttachmentsRef.current,
          previous,
        ];
      setAttachment(undefined);
    });
  };

  const submit = async () => {
    if (!canSubmit || submitFlightRef.current) return;
    const generation = submitGenerationRef.current;
    submitFlightRef.current = true;
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
      const pendingDraft = {
        attachment,
        category,
        clientRequestId,
        includeDiagnostics,
        message,
        sourceScreen: draftSourceScreen,
        updatedAt: Date.now(),
      } satisfies Parameters<typeof saveProductFeedbackDraft>[0];
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
          attachment,
          category,
          clientRequestId,
          context: {
            includeDiagnostics,
            locale,
            sourceScreen: draftSourceScreen,
          },
          message,
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
    setCategory('problem');
    setMessage('');
    setAttachment(undefined);
    setIncludeDiagnostics(false);
    setClientRequestId(secureRandomUuid());
    setDraftSourceScreen(sourceScreen);
    setDraftSaveError(false);
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
    chooseScreenshot,
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
      changeDraft(() => setCategory(value)),
    setIncludeDiagnostics: (value: boolean) =>
      changeDraft(() => setIncludeDiagnostics(value)),
    sent,
    setMessage: (value: string) => changeDraft(() => setMessage(value)),
    submit,
  };
};
