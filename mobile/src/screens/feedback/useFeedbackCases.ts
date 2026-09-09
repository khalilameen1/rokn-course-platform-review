import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
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
  type ProductFeedbackReceipt,
  replyToProductFeedback,
  restoreProductFeedbackDraftConflict,
  saveProductFeedbackReplyDraft,
} from '../../services/productFeedback';
import {secureRandomUuid} from '../../utils/secureRandom';
import {settleWithin} from '../../utils/settleWithin';
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
  const [replyRestoreError, setReplyRestoreError] = useState(false);
  const [replyRestoreRevision, setReplyRestoreRevision] = useState(0);
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
  const discardedAttachmentsRef = useRef({
    caseId: '',
    files: [] as FeedbackAttachment[],
  });
  const persistReply = useCallback(
    async (
      publicId: string,
      draft: Parameters<typeof saveProductFeedbackReplyDraft>[1],
      boundary: AccountSessionBoundary,
    ) => {
      const discarded =
        discardedAttachmentsRef.current.caseId === publicId
          ? discardedAttachmentsRef.current.files
          : [];
      await saveProductFeedbackReplyDraft(
        publicId,
        draft,
        boundary,
        discarded,
      );
      if (discardedAttachmentsRef.current.caseId === publicId)
        discardedAttachmentsRef.current.files =
          discardedAttachmentsRef.current.files.filter(
            file => !discarded.includes(file),
          );
    },
    [],
  );

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
    discardedAttachmentsRef.current = {caseId: '', files: []};
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

  const reloadCases = async (
    preferredCaseId = '',
    received?: ProductFeedbackReceipt,
  ) => {
    const generation = ++casesGenerationRef.current;
    setCasesBusy(true);
    setCasesError('');
    try {
      const boundary = await captureAccountSessionBoundary();
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
      if (
        !mountedRef.current ||
        generation !== casesGenerationRef.current ||
        dataOwnerRef.current !== identityKey
      ) {
        return;
      }
      setSupportCases(current =>
        received
          ? [
              ...current.filter(item => item.publicId !== received.publicId),
              ...loaded,
            ]
          : loaded,
      );
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
    discardedAttachmentsRef.current = {caseId: selectedCaseId, files: []};
    replyDraftOwnerScopeRef.current = '';
    setReplyStateOwnerId('');
    setReplyError('');
    setReplyRestoreError(false);
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
                if (!active || generation !== replyGenerationRef.current)
                  return;
                const restoreOwnerScope = boundary.scope;
                replyDraftEpochRef.current += 1;
                setReplyStateOwnerId('');
                void (async () => {
                  try {
                    const restoreBoundary =
                      await captureAccountSessionBoundary();
                    if (restoreBoundary.scope !== restoreOwnerScope) {
                      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
                    }
                    if (!active || generation !== replyGenerationRef.current)
                      return;
                    const restored = await restoreProductFeedbackDraftConflict(
                      alternative.id,
                      restoreBoundary,
                    );
                    if (
                      !mountedRef.current ||
                      !active ||
                      generation !== replyGenerationRef.current
                    )
                      return;
                    if (!restored) throw new Error('DRAFT_RESTORE_UNAVAILABLE');
                    const value = await loadProductFeedbackReplyDraft(
                      selectedCaseId,
                      restoreBoundary,
                    );
                    if (
                      !mountedRef.current ||
                      generation !== replyGenerationRef.current ||
                      dataOwnerRef.current !== identityKey
                    ) {
                      return;
                    }
                    if (!value) throw new Error('DRAFT_RESTORE_UNAVAILABLE');
                    setReplyMessage(value.message);
                    setReplyAttachment(value.attachment);
                    setReplyRequestId(
                      value.clientRequestId || secureRandomUuid(),
                    );
                    setReplyStateOwnerId(selectedCaseId);
                  } catch {
                    if (active && generation === replyGenerationRef.current)
                      setReplyRestoreError(true);
                  }
                })();
              },
            },
          ],
        );
      })
      .catch(() => {
        if (active && generation === replyGenerationRef.current) {
          setReplyRestoreError(true);
        }
      });

    return () => {
      active = false;
    };
  }, [identityKey, selectedCaseId, replyRestoreRevision]);

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
          if (
            draftEpoch !== replyDraftEpochRef.current ||
            dataOwnerRef.current !== identityKey
          )
            return;
          if (boundary.scope !== ownerScope) {
            throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
          }
          return persistReply(
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
    persistReply,
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
    if (
      pickerFlightRef.current ||
      replyBusy ||
      replyStateOwnerId !== selectedCaseId
    )
      return;
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
      if (previous)
        discardedAttachmentsRef.current = {
          caseId: selectedCaseId,
          files: [...discardedAttachmentsRef.current.files, previous],
        };
      setReplyAttachment(selected);
      setReplyRequestId(secureRandomUuid());
    } finally {
      pickerFlightRef.current = false;
    }
  };

  const removeReplyScreenshot = () => {
    if (replyBusy || replyStateOwnerId !== selectedCaseId) return;
    const previous = replyAttachment;
    if (previous)
      discardedAttachmentsRef.current = {
        caseId: selectedCaseId,
        files: [...discardedAttachmentsRef.current.files, previous],
      };
    setReplyAttachment(undefined);
    setReplyRequestId(secureRandomUuid());
  };

  const setReply = (value: string) => {
    if (replyBusy || replyStateOwnerId !== selectedCaseId) return;
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
        await persistReply(
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
      await settleWithin(
        persistReply(caseId, null, boundary),
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
        setReplyError('تعذّر تأكيد وصول الرد\nحاول مرة أخرى\nنصك محفوظ');
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

  const restoreOwner = replyGenerationRef.current;
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
    replyReady: Boolean(selectedCaseId) && replyStateOwnerId === selectedCaseId,
    replyRestoreError,
    retryReplyRestore: () => {
      if (
        replyRestoreError &&
        !replyBusy &&
        mountedRef.current &&
        dataOwnerRef.current === identityKey &&
        restoreOwner === replyGenerationRef.current
      )
        setReplyRestoreRevision(value => value + 1);
    },
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
