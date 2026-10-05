import {useEffect, useMemo, useRef, useState} from 'react';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {
  loadProductFeedbackCase,
  loadProductFeedbackCases,
  mergeProductFeedbackHistory,
  ProductFeedbackHistoryIncompleteError,
  type ProductFeedbackCase,
  type ProductFeedbackReceipt,
  replyToProductFeedback,
} from '../../services/productFeedback';
import {settleWithin} from '../../utils/settleWithin';
import {useFeedbackArtifactPreview} from './useFeedbackArtifactPreview';
import {useFeedbackReplyDraft} from './useFeedbackReplyDraft';
import {useFeedbackScreenshotPreparation} from './useFeedbackScreenshotPreparation';

export const useFeedbackCases = (
  identityKey: string,
  requestedCaseId: string,
  focused = true,
) => {
  const [supportCases, setSupportCases] = useState<ProductFeedbackCase[]>([]);
  const [casesBusy, setCasesBusy] = useState(true);
  const [casesError, setCasesError] = useState('');
  const [selectedCaseId, setSelectedCaseId] = useState('');
  const [replyPendingCaseIds, setReplyPendingCaseIds] = useState<Set<string>>(
    new Set(),
  );
  const [replyError, setReplyError] = useState('');
  const mountedRef = useRef(true);
  const dataOwnerRef = useRef(identityKey);
  const routeOwner = useMemo(
    () => ({identityKey, requestedCaseId}),
    [identityKey, requestedCaseId],
  );
  const routeOwnerRef = useRef(routeOwner);
  routeOwnerRef.current = routeOwner;
  const casesGenerationRef = useRef(0);
  const replyGenerationRef = useRef(0);
  const replyFlightsRef = useRef(new Map<string, symbol>());

  const selectedCase = supportCases.find(
    item => item.publicId === selectedCaseId,
  );
  const replyBusy = replyPendingCaseIds.size > 0;
  const artifactPreview = useFeedbackArtifactPreview(
    identityKey,
    dataOwnerRef.current === identityKey ? selectedCase : undefined,
    focused,
  );
  const replyDraft = useFeedbackReplyDraft(
    identityKey,
    selectedCaseId,
    focused,
  );
  const screenshot = useFeedbackScreenshotPreparation({
    ownerKey: `${identityKey}:${selectedCaseId}`,
    canPrepare: () =>
      dataOwnerRef.current === identityKey &&
      Boolean(selectedCaseId) &&
      replyDraft.canEdit() &&
      !replyBusy &&
      replyFlightsRef.current.size === 0,
    onPrepared: selected => {
      replyDraft.change({attachment: selected});
      setReplyError('');
    },
  });
  const invalidateScreenshot = screenshot.invalidate;

  useEffect(() => {
    if (dataOwnerRef.current === identityKey) return;
    dataOwnerRef.current = identityKey;
    casesGenerationRef.current += 1;
    replyGenerationRef.current += 1;
    replyFlightsRef.current.clear();
    setSupportCases([]);
    setCasesBusy(true);
    setCasesError('');
    setSelectedCaseId('');
    setReplyPendingCaseIds(new Set());
    setReplyError('');
  }, [identityKey]);

  const reloadCases = async (
    preferredCaseId = '',
    received?: ProductFeedbackReceipt,
  ) => {
    // Refresh reads data; it does not replay the route that opened this screen.
    // Only an explicit open-follow-up action may select a target here.
    if (
      !mountedRef.current ||
      routeOwnerRef.current !== routeOwner ||
      dataOwnerRef.current !== identityKey
    )
      return;
    if (preferredCaseId) setSelectedCaseId(preferredCaseId);
    const generation = ++casesGenerationRef.current;
    const ownsRead = () =>
      mountedRef.current &&
      routeOwnerRef.current === routeOwner &&
      generation === casesGenerationRef.current &&
      dataOwnerRef.current === identityKey;
    setCasesBusy(true);
    setCasesError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      if (!ownsRead()) return;
      assertAccountSessionBoundary(boundary);
      const loaded = received
        ? [
            {
              ...(await loadProductFeedbackCase(
                received.publicId,
                received.accessToken,
                boundary,
              )),
              accessToken: received.accessToken,
            },
          ]
        : await loadProductFeedbackCases(boundary);
      assertAccountSessionBoundary(boundary);
      if (!ownsRead()) return;
      setSupportCases(current =>
        received
          ? [
              ...current.filter(item => item.publicId !== received.publicId),
              ...loaded,
            ]
          : loaded,
      );
    } catch (error: unknown) {
      if (ownsRead()) {
        if (
          error instanceof ProductFeedbackHistoryIncompleteError &&
          error.cases.length > 0
        ) {
          setSupportCases(current =>
            mergeProductFeedbackHistory(current, error.cases),
          );
          setCasesError('تعذّر تحديث بعض الطلبات');
        } else {
          setCasesError('تعذّر تحديث الحالات الآن');
        }
      }
    } finally {
      if (ownsRead()) {
        setCasesBusy(false);
      }
    }
  };

  useEffect(() => {
    // Route navigation supplies a new selection once. Failure leaves that
    // intent pending so a plain refresh can recover it without replaying it
    // after the learner deliberately chooses another case or the list.
    setSelectedCaseId(requestedCaseId);
    void reloadCases();
    // Writes refresh the list explicitly. Identity and requested route own reads.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [identityKey, requestedCaseId]);

  useEffect(() => {
    invalidateScreenshot();
    replyGenerationRef.current += 1;
    setReplyError('');
  }, [identityKey, selectedCaseId, invalidateScreenshot]);

  useEffect(() => {
    if (!replyDraft.ready) invalidateScreenshot();
  }, [replyDraft.ready, invalidateScreenshot]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      replyGenerationRef.current += 1;
      casesGenerationRef.current += 1;
    };
  }, []);

  const removeReplyScreenshot = () => {
    if (
      screenshot.isPreparing() ||
      replyBusy ||
      replyFlightsRef.current.size > 0 ||
      !replyDraft.canEdit()
    )
      return;
    replyDraft.change({attachment: undefined});
  };

  const setReply = (value: string) => {
    if (replyBusy || replyFlightsRef.current.size > 0) return;
    replyDraft.change({message: value});
    setReplyError('');
  };

  const sendReply = async () => {
    if (
      !selectedCase ||
      !replyDraft.canEdit() ||
      replyBusy ||
      casesBusy ||
      screenshot.isPreparing() ||
      !mountedRef.current ||
      dataOwnerRef.current !== identityKey ||
      replyFlightsRef.current.has(selectedCase.publicId)
    ) {
      return;
    }
    const submission = replyDraft.beginSend();
    if (!submission) return;
    if (submission.snapshot.message.trim().length < 2) {
      replyDraft.finishSubmission(submission);
      return;
    }
    const generation = replyGenerationRef.current;
    const ownerIdentity = identityKey;
    const caseId = selectedCase.publicId;
    const messageToSend = submission.snapshot.message;
    const attachmentToSend = submission.snapshot.attachment;
    const requestId = submission.snapshot.clientRequestId;
    const flight = Symbol(`support-reply-${caseId}`);
    replyFlightsRef.current.set(caseId, flight);
    setReplyPendingCaseIds(current => new Set(current).add(caseId));
    setReplyError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      try {
        await replyDraft.persistSubmission(submission, boundary);
      } catch {
        if (
          mountedRef.current &&
          generation === replyGenerationRef.current &&
          dataOwnerRef.current === ownerIdentity
        ) {
          setReplyError(
            'تعذّر حفظ الرد على الجهاز\nحرر مساحة ثم حاول مرة أخرى',
          );
        }
        return;
      }
      const updated = await replyToProductFeedback(
        {
          accessToken: selectedCase.accessToken,
          attachment: attachmentToSend,
          clientRequestId: requestId,
          message: messageToSend,
          publicId: caseId,
        },
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      await settleWithin(
        replyDraft.acceptSubmission(submission, boundary),
        undefined,
      );
      assertAccountSessionBoundary(boundary);
      if (!mountedRef.current) return;
      setSupportCases(current =>
        current.map(item =>
          item.publicId === updated.publicId
            ? {...updated, accessToken: item.accessToken}
            : item,
        ),
      );
    } catch (replyFailure: unknown) {
      if (
        mountedRef.current &&
        generation === replyGenerationRef.current &&
        dataOwnerRef.current === ownerIdentity &&
        !(
          replyFailure instanceof Error &&
          replyFailure.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        )
      ) {
        setReplyError('تعذّر تأكيد وصول الرد\nحاول مرة أخرى\nنصك محفوظ');
      }
    } finally {
      replyDraft.finishSubmission(submission);
      const ownsFlight = replyFlightsRef.current.get(caseId) === flight;
      if (ownsFlight) replyFlightsRef.current.delete(caseId);
      if (
        ownsFlight &&
        mountedRef.current &&
        dataOwnerRef.current === ownerIdentity
      ) {
        setReplyPendingCaseIds(current => {
          const next = new Set(current);
          next.delete(caseId);
          return next;
        });
      }
    }
  };

  return {
    casesBusy,
    casesError,
    chooseReplyScreenshot: screenshot.choose,
    replyAttachmentBusy: screenshot.preparing,
    ...artifactPreview,
    reloadCases,
    removeReplyScreenshot,
    replyAttachment: replyDraft.attachment,
    replyBusy,
    replyError:
      replyError ||
      (replyDraft.saveError
        ? 'تعذّر حفظ الرد على الجهاز\nحرر مساحة ثم حاول مرة أخرى'
        : ''),
    replyReady: replyDraft.ready,
    replyRestoreError: replyDraft.restoreError,
    retryReplyRestore: replyDraft.retryRestore,
    replyMessage: replyDraft.message,
    selectCase: (caseId: string) => {
      if (!replyBusy && !casesBusy && replyFlightsRef.current.size === 0)
        setSelectedCaseId(caseId);
    },
    selectedCase,
    selectedCaseId,
    sendReply,
    setReply,
    supportCases,
  };
};
