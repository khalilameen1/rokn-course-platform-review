import {useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {removeLearnerDraftFile} from '../../services/learnerDraftFiles';
import {
  loadProductFeedbackCase,
  loadProductFeedbackCases,
  loadProductFeedbackDraftConflicts,
  loadProductFeedbackReplyDraft,
  type FeedbackAttachment,
  type ProductFeedbackArtifact,
  type ProductFeedbackCase,
  replyToProductFeedback,
  restoreProductFeedbackDraftConflict,
  saveProductFeedbackReplyDraft,
} from '../../services/productFeedback';
import {secureRandomUuid} from '../../utils/secureRandom';
import {pickFeedbackScreenshot} from './pickFeedbackScreenshot';

export const useFeedbackCases = (
  identityKey: string,
  requestedCaseId: string,
) => {
  const [supportCases, setSupportCases] = useState<ProductFeedbackCase[]>([]);
  const [casesBusy, setCasesBusy] = useState(true);
  const [casesError, setCasesError] = useState('');
  const [selectedCaseId, setSelectedCaseId] = useState('');
  const [replyStateOwnerId, setReplyStateOwnerId] = useState('');
  const [replyMessage, setReplyMessage] = useState('');
  const [replyRequestId, setReplyRequestId] = useState(secureRandomUuid);
  const [replyAttachment, setReplyAttachment] = useState<FeedbackAttachment>();
  const [replyPendingCaseIds, setReplyPendingCaseIds] = useState<Set<string>>(
    new Set(),
  );
  const [replyError, setReplyError] = useState('');
  const [previewArtifact, setPreviewArtifact] =
    useState<ProductFeedbackArtifact>();
  const [previewLoadFailed, setPreviewLoadFailed] = useState(false);
  const mountedRef = useRef(true);
  const dataOwnerRef = useRef(identityKey);
  const casesGenerationRef = useRef(0);
  const replyGenerationRef = useRef(0);
  const replyDraftEpochRef = useRef(0);
  const replyDraftOwnerScopeRef = useRef('');
  const replyFlightsRef = useRef(new Map<string, symbol>());
  const pickerFlightRef = useRef(false);
  const artifactPreviewGenerationRef = useRef(0);
  const artifactRefreshFlightRef = useRef<symbol | null>(null);

  const selectedCase = supportCases.find(
    item => item.publicId === selectedCaseId,
  );
  const replyBusy = replyPendingCaseIds.size > 0;

  useEffect(() => {
    if (dataOwnerRef.current === identityKey) return;
    dataOwnerRef.current = identityKey;
    casesGenerationRef.current += 1;
    replyGenerationRef.current += 1;
    replyDraftEpochRef.current += 1;
    replyDraftOwnerScopeRef.current = '';
    replyFlightsRef.current.clear();
    pickerFlightRef.current = false;
    artifactPreviewGenerationRef.current += 1;
    artifactRefreshFlightRef.current = null;
    setSupportCases([]);
    setCasesBusy(true);
    setCasesError('');
    setSelectedCaseId('');
    setReplyStateOwnerId('');
    setReplyMessage('');
    setReplyRequestId(secureRandomUuid());
    setReplyAttachment(undefined);
    artifactPreviewGenerationRef.current += 1;
    artifactRefreshFlightRef.current = null;
    setPreviewArtifact(undefined);
    setPreviewLoadFailed(false);
    setReplyPendingCaseIds(new Set());
    setReplyError('');
    setPreviewArtifact(undefined);
    setPreviewLoadFailed(false);
  }, [identityKey]);

  const reloadCases = async (preferredCaseId = '') => {
    const generation = ++casesGenerationRef.current;
    setCasesBusy(true);
    setCasesError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const loaded = await loadProductFeedbackCases(boundary);
      assertAccountSessionBoundary(boundary);
      if (
        !mountedRef.current ||
        generation !== casesGenerationRef.current ||
        dataOwnerRef.current !== identityKey
      ) {
        return;
      }
      setSupportCases(loaded);
      const targetCaseId = preferredCaseId || requestedCaseId;
      if (targetCaseId && loaded.some(item => item.publicId === targetCaseId)) {
        setSelectedCaseId(targetCaseId);
      }
    } catch {
      if (
        mountedRef.current &&
        generation === casesGenerationRef.current &&
        dataOwnerRef.current === identityKey
      ) {
        setCasesError('تعذّر تحديث الحالات الآن');
      }
    } finally {
      if (
        mountedRef.current &&
        generation === casesGenerationRef.current &&
        dataOwnerRef.current === identityKey
      ) {
        setCasesBusy(false);
      }
    }
  };

  useEffect(() => {
    void reloadCases();
    // Writes refresh the list explicitly. Identity and requested route own reads.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [identityKey, requestedCaseId]);

  useEffect(() => {
    let active = true;
    const generation = ++replyGenerationRef.current;
    replyDraftOwnerScopeRef.current = '';
    setReplyStateOwnerId('');
    setReplyError('');
    setReplyMessage('');
    setReplyRequestId(secureRandomUuid());
    setReplyAttachment(undefined);
    if (!selectedCaseId) {
      return () => {
        active = false;
      };
    }
    void captureAccountSessionBoundary()
      .then(async boundary => {
        assertAccountSessionBoundary(boundary);
        if (!active || generation !== replyGenerationRef.current) return null;
        replyDraftOwnerScopeRef.current = boundary.scope;
        return {
          boundary,
          values: await Promise.all([
            loadProductFeedbackReplyDraft(selectedCaseId, boundary),
            loadProductFeedbackDraftConflicts(boundary),
          ]),
        };
      })
      .then(result => {
        if (!result) return;
        const {
          boundary,
          values: [draft, conflicts],
        } = result;
        assertAccountSessionBoundary(boundary);
        if (!active || generation !== replyGenerationRef.current) return;
        if (draft) {
          setReplyMessage(draft.message);
          setReplyAttachment(draft.attachment);
          setReplyRequestId(draft.clientRequestId || secureRandomUuid());
        }
        setReplyStateOwnerId(selectedCaseId);
        const alternative = conflicts.find(
          conflict =>
            conflict.type === 'reply' && conflict.publicId === selectedCaseId,
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
                const restoreOwnerScope = boundary.scope;
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
                    if (!restored || !mountedRef.current) return;
                    const value = await loadProductFeedbackReplyDraft(
                      selectedCaseId,
                      restoreBoundary,
                    );
                    if (
                      !value ||
                      !mountedRef.current ||
                      generation !== replyGenerationRef.current ||
                      dataOwnerRef.current !== identityKey
                    ) {
                      return;
                    }
                    setReplyMessage(value.message);
                    setReplyAttachment(value.attachment);
                    setReplyRequestId(
                      value.clientRequestId || secureRandomUuid(),
                    );
                  } catch {}
                })();
              },
            },
          ],
        );
      })
      .catch(() => {
        if (active && generation === replyGenerationRef.current) {
          setReplyStateOwnerId(selectedCaseId);
        }
      });

    return () => {
      active = false;
    };
  }, [identityKey, selectedCaseId]);

  useEffect(() => {
    if (!selectedCaseId || replyStateOwnerId !== selectedCaseId) return;
    const draftEpoch = replyDraftEpochRef.current;
    const ownerScope = replyDraftOwnerScopeRef.current;
    const persistReplyDraft = () => {
      if (
        !ownerScope ||
        draftEpoch !== replyDraftEpochRef.current ||
        dataOwnerRef.current !== identityKey
      ) {
        return;
      }
      void captureAccountSessionBoundary()
        .then(boundary => {
          if (boundary.scope !== ownerScope) {
            throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
          }
          return saveProductFeedbackReplyDraft(
            selectedCaseId,
            replyMessage.trim() || replyAttachment
              ? {
                  attachment: replyAttachment,
                  clientRequestId: replyRequestId,
                  message: replyMessage,
                }
              : null,
            boundary,
          );
        })
        .catch(() => undefined);
    };
    const timer = setTimeout(persistReplyDraft, 300);

    return () => {
      clearTimeout(timer);
      persistReplyDraft();
    };
  }, [
    identityKey,
    replyAttachment,
    replyMessage,
    replyRequestId,
    replyStateOwnerId,
    selectedCaseId,
  ]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      replyGenerationRef.current += 1;
      casesGenerationRef.current += 1;
    };
  }, []);

  const chooseReplyScreenshot = async () => {
    if (pickerFlightRef.current || replyBusy) return;
    const ownerCaseId = selectedCaseId;
    const ownerGeneration = replyGenerationRef.current;
    pickerFlightRef.current = true;
    try {
      const selected = await pickFeedbackScreenshot();
      if (!selected) return;
      if (
        !mountedRef.current ||
        ownerGeneration !== replyGenerationRef.current ||
        ownerCaseId !== selectedCaseId
      ) {
        await removeLearnerDraftFile(selected).catch(() => undefined);
        return;
      }
      const previous = replyAttachment;
      setReplyAttachment(selected);
      setReplyRequestId(secureRandomUuid());
      await removeLearnerDraftFile(previous).catch(() => undefined);
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const removeReplyScreenshot = () => {
    if (replyBusy) return;
    const previous = replyAttachment;
    setReplyAttachment(undefined);
    setReplyRequestId(secureRandomUuid());
    void removeLearnerDraftFile(previous).catch(() => undefined);
  };

  const setReply = (value: string) => {
    if (replyBusy) return;
    setReplyMessage(value);
    setReplyRequestId(secureRandomUuid());
    setReplyError('');
  };

  const sendReply = async () => {
    if (
      !selectedCase ||
      replyStateOwnerId !== selectedCase.publicId ||
      replyBusy ||
      casesBusy ||
      replyFlightsRef.current.has(selectedCase.publicId) ||
      replyMessage.trim().length < 2
    ) {
      return;
    }
    const generation = replyGenerationRef.current;
    const ownerIdentity = identityKey;
    const caseId = selectedCase.publicId;
    const messageToSend = replyMessage;
    const attachmentToSend = replyAttachment;
    const requestId = replyRequestId;
    const flight = Symbol(`support-reply-${caseId}`);
    replyFlightsRef.current.set(caseId, flight);
    setReplyPendingCaseIds(current => new Set(current).add(caseId));
    setReplyError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const replyOwnerScope = replyDraftOwnerScopeRef.current;
      if (replyOwnerScope && boundary.scope !== replyOwnerScope) {
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      }
      try {
        await saveProductFeedbackReplyDraft(
          caseId,
          {
            attachment: attachmentToSend,
            clientRequestId: requestId,
            message: messageToSend,
          },
          boundary,
        );
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
      await saveProductFeedbackReplyDraft(caseId, null, boundary).catch(
        () => undefined,
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
      void removeLearnerDraftFile(attachmentToSend).catch(() => undefined);
      if (generation !== replyGenerationRef.current) return;
      replyDraftEpochRef.current += 1;
      setReplyAttachment(undefined);
      setReplyMessage('');
      setReplyRequestId(secureRandomUuid());
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
        setReplyError(
          'لم يصل الرد\nتحقق من الاتصال ثم حاول مرة أخرى\nنصك محفوظ',
        );
      }
    } finally {
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

  const openArtifact = async (
    artifact: ProductFeedbackArtifact,
    forceRefresh = false,
  ) => {
    const owner = selectedCase;
    if (!owner) return;
    const previewGeneration = ++artifactPreviewGenerationRef.current;
    setPreviewLoadFailed(false);
    setPreviewArtifact(artifact);
    const expiresAt = Date.parse(artifact.expiresAt);
    if (
      !forceRefresh &&
      Number.isFinite(expiresAt) &&
      expiresAt > Date.now() + 30_000
    ) {
      return;
    }

    const flight = Symbol(`support-artifact-${artifact.id}`);
    artifactRefreshFlightRef.current = flight;
    try {
      const boundary = await captureAccountSessionBoundary();
      const refreshed = await loadProductFeedbackCase(
        owner.publicId,
        owner.accessToken,
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      if (
        !mountedRef.current ||
        selectedCaseId !== owner.publicId ||
        artifactPreviewGenerationRef.current !== previewGeneration ||
        artifactRefreshFlightRef.current !== flight
      ) {
        return;
      }
      const renewed = [
        ...refreshed.attachments,
        ...refreshed.messages.flatMap(item => item.attachments),
      ].find(candidate => candidate.id === artifact.id);
      if (!renewed) throw new Error('SUPPORT_ATTACHMENT_RETIRED');
      setSupportCases(current =>
        current.map(item =>
          item.publicId === refreshed.publicId
            ? {...refreshed, accessToken: item.accessToken}
            : item,
        ),
      );
      setPreviewLoadFailed(false);
      setPreviewArtifact(renewed);
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        return;
      }
      if (
        mountedRef.current &&
        artifactPreviewGenerationRef.current === previewGeneration &&
        artifactRefreshFlightRef.current === flight
      ) {
        setPreviewLoadFailed(true);
        setPreviewArtifact(artifact);
      }
    } finally {
      if (artifactRefreshFlightRef.current === flight) {
        artifactRefreshFlightRef.current = null;
      }
    }
  };

  return {
    casesBusy,
    casesError,
    chooseReplyScreenshot,
    closeArtifact: () => {
      artifactPreviewGenerationRef.current += 1;
      artifactRefreshFlightRef.current = null;
      setPreviewArtifact(undefined);
      setPreviewLoadFailed(false);
    },
    markArtifactLoadFailed: (artifactId: string) => {
      if (previewArtifact?.id === artifactId) setPreviewLoadFailed(true);
    },
    openArtifact,
    previewArtifact,
    previewLoadFailed,
    reloadCases,
    removeReplyScreenshot,
    replyAttachment,
    replyBusy,
    replyError,
    replyMessage,
    selectCase: (caseId: string) => {
      if (!replyBusy && !casesBusy) setSelectedCaseId(caseId);
    },
    selectedCase,
    selectedCaseId,
    sendReply,
    setReply,
    supportCases,
  };
};
