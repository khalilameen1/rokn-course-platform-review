import {useEffect, useMemo, useRef, useState} from 'react';
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
  const [clientRequestId, setClientRequestId] = useState(secureRandomUuid);
  const [receiptId, setReceiptId] = useState('');
  const [receiptPublicId, setReceiptPublicId] = useState('');
  const mountedRef = useRef(true);
  const pickerFlightRef = useRef(false);
  const submitFlightRef = useRef(false);
  const submitGenerationRef = useRef(0);
  const dataOwnerRef = useRef(identityKey);
  const draftOwnerScopeRef = useRef('');
  const draftSnapshotRef = useRef({
    attachment,
    category,
    clientRequestId,
    includeDiagnostics,
    message,
    updatedAt: Date.now(),
  });
  draftSnapshotRef.current = {
    attachment,
    category,
    clientRequestId,
    includeDiagnostics,
    message,
    updatedAt: Date.now(),
  };

  const canSubmit = useMemo(
    () => draftReady && message.trim().length >= 10 && !busy,
    [busy, draftReady, message],
  );

  useEffect(() => {
    if (dataOwnerRef.current === identityKey) return;
    dataOwnerRef.current = identityKey;
    submitGenerationRef.current += 1;
    submitFlightRef.current = false;
    pickerFlightRef.current = false;
    draftOwnerScopeRef.current = '';
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
    setReceiptId('');
    setReceiptPublicId('');
  }, [identityKey]);

  useEffect(() => {
    let active = true;
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
        }
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
                if (!restoreOwnerScope) return;
                setDraftReady(false);
                void (async () => {
                  try {
                    const restoreBoundary =
                      await captureAccountSessionBoundary();
                    if (restoreBoundary.scope !== restoreOwnerScope) {
                      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
                    }
                    const restored = await restoreProductFeedbackDraftConflict(
                      alternative.id,
                      restoreBoundary,
                    );
                    if (
                      !restored ||
                      !mountedRef.current ||
                      generation !== submitGenerationRef.current ||
                      dataOwnerRef.current !== identityKey
                    ) {
                      return;
                    }
                    const value = await loadProductFeedbackDraft(
                      restoreBoundary,
                    );
                    if (
                      !value ||
                      !mountedRef.current ||
                      generation !== submitGenerationRef.current ||
                      dataOwnerRef.current !== identityKey
                    ) {
                      return;
                    }
                    setCategory(value.category);
                    setMessage(value.message);
                    setAttachment(value.attachment);
                    setClientRequestId(value.clientRequestId);
                    setIncludeDiagnostics(value.includeDiagnostics);
                  } catch {
                    if (
                      mountedRef.current &&
                      generation === submitGenerationRef.current &&
                      dataOwnerRef.current === identityKey
                    ) {
                      setDraftSaveError(true);
                    }
                  } finally {
                    if (
                      !mountedRef.current ||
                      generation !== submitGenerationRef.current ||
                      dataOwnerRef.current !== identityKey
                    ) {
                      return;
                    }
                    setDraftReady(true);
                  }
                })();
              },
            },
          ],
        );
      })
      .catch(() => {
        if (active) {
          if (ownerBoundary) {
            draftOwnerScopeRef.current = ownerBoundary.scope;
          }
          setDraftSaveError(true);
        }
      })
      .finally(() => {
        if (active) setDraftReady(true);
      });

    return () => {
      active = false;
    };
  }, [identityKey]);

  useEffect(() => {
    if (!draftReady || sent) return;
    const ownerScope = draftOwnerScopeRef.current;
    if (!ownerScope) return;
    const timer = setTimeout(() => {
      void captureAccountSessionBoundary()
        .then(boundary => {
          if (boundary.scope !== ownerScope) {
            throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
          }
          return saveProductFeedbackDraft(
            {
              attachment,
              category,
              clientRequestId,
              includeDiagnostics,
              message,
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
    category,
    clientRequestId,
    draftReady,
    includeDiagnostics,
    message,
    sent,
  ]);

  useEffect(() => {
    if (appActive || !draftReady || sent) return;
    const ownerScope = draftOwnerScopeRef.current;
    if (!ownerScope) return;
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (boundary.scope !== ownerScope) {
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        }
        return saveProductFeedbackDraft(
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
  }, [appActive, draftReady, sent]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      submitGenerationRef.current += 1;
    };
  }, []);

  const changeDraft = (change: () => void) => {
    if (busy || !draftReady) return;
    change();
    setClientRequestId(secureRandomUuid());
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
      changeDraft(() => setAttachment(selected));
      await removeLearnerDraftFile(previous).catch(() => undefined);
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const removeScreenshot = () => {
    if (busy) return;
    const previous = attachment;
    changeDraft(() => setAttachment(undefined));
    void removeLearnerDraftFile(previous).catch(() => undefined);
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
        updatedAt: Date.now(),
      } satisfies Parameters<typeof saveProductFeedbackDraft>[0];
      try {
        await saveProductFeedbackDraft(pendingDraft, boundary);
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
      const receipt = await submitProductFeedback(
        {
          attachment,
          category,
          clientRequestId,
          context: {includeDiagnostics, locale, sourceScreen},
          message,
        },
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      await clearProductFeedbackDraft(boundary).catch(() => undefined);
      assertAccountSessionBoundary(boundary);
      if (
        !mountedRef.current ||
        generation !== submitGenerationRef.current ||
        dataOwnerRef.current !== identityKey
      ) {
        return;
      }
      setReceiptId(receipt.caseNumber);
      setReceiptPublicId(receipt.publicId);
      setCategory('problem');
      setMessage('');
      setAttachment(undefined);
      setIncludeDiagnostics(false);
      setClientRequestId(secureRandomUuid());
      setDraftSaveError(false);
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
        setError('لم تصل الرسالة\nتحقق من الاتصال ثم حاول مرة أخرى\nنصك محفوظ');
      }
    } finally {
      if (generation === submitGenerationRef.current) {
        submitFlightRef.current = false;
        if (mountedRef.current) setBusy(false);
      }
    }
  };

  return {
    attachment,
    busy,
    canSubmit,
    category,
    chooseScreenshot,
    dismissReceipt: () => setSent(false),
    draftSaveError,
    error,
    includeDiagnostics,
    message,
    receiptId,
    receiptPublicId,
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
