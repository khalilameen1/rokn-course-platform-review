import {useCallback, useEffect, useRef, useState} from 'react';
import type {ImageSourcePropType} from 'react-native';

import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import type {EligibleProject} from '../../../services/roknApi';
import {
  clearPortfolioEditorDraft,
  readPortfolioEditorDraft,
  writePortfolioEditorDraft,
} from '../../../services/portfolioDraft';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';
import {secureRandomUuid} from '../../../utils/secureRandom';

export type PortfolioDraftAsset = {
  uri: string;
  type?: string;
  fileName?: string;
  size?: number;
};

type Options = {
  appActive: boolean;
  captureBoundary: () => Promise<AccountSessionBoundary>;
  mountedRef: React.MutableRefObject<boolean>;
};

export const usePortfolioDraftEditor = ({
  appActive,
  captureBoundary,
  mountedRef,
}: Options) => {
  const [selectedSourceProject, setSelectedSourceProject] =
    useState<EligibleProject | null>(null);
  const [draftTitle, setDraftTitle] = useState('');
  const [draftSummary, setDraftSummary] = useState('');
  const [draftCover, setDraftCover] = useState<ImageSourcePropType | null>(
    null,
  );
  const [draftCoverAsset, setDraftCoverAsset] = useState<
    PortfolioDraftAsset | undefined
  >();
  const [draftMediaAssets, setDraftMediaAssets] = useState<
    PortfolioDraftAsset[]
  >([]);
  const [draftReady, setDraftReady] = useState(false);
  const [draftLoadError, setDraftLoadError] = useState(false);
  const [draftLoadAttempt, setDraftLoadAttempt] = useState(0);
  const draftReadyRef = useRef(false);
  const [clientRequestId, setClientRequestId] = useState(secureRandomUuid);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const persistenceRevisionRef = useRef(0);
  const snapshotRef = useRef({
    clientRequestId,
    cover: draftCoverAsset,
    media: draftMediaAssets,
    selectedSource: selectedSourceProject || undefined,
    summary: draftSummary,
    title: draftTitle,
    updatedAt: Date.now(),
  });
  snapshotRef.current = {
    clientRequestId,
    cover: draftCoverAsset,
    media: draftMediaAssets,
    selectedSource: selectedSourceProject || undefined,
    summary: draftSummary,
    title: draftTitle,
    updatedAt: Date.now(),
  };

  useEffect(() => {
    let active = true;
    draftReadyRef.current = false;
    setDraftReady(false);
    setDraftLoadError(false);
    persistenceRevisionRef.current += 1;
    void (async () => {
      const boundary = await captureBoundary();
      if (!active) return;
      const draft = await readPortfolioEditorDraft(boundary);
      assertAccountSessionBoundary(boundary);
      if (!active || !mountedRef.current) return;
      if (draft) {
        setDraftTitle(draft.title);
        setDraftSummary(draft.summary);
        setDraftCoverAsset(draft.cover);
        const restoredMedia = draft.media?.length
          ? draft.media
          : draft.cover
          ? [draft.cover]
          : [];
        setDraftMediaAssets(restoredMedia);
        const restoredCover = restoredMedia.find(
          file =>
            !String(file.type || '')
              .toLowerCase()
              .startsWith('video/'),
        );
        setDraftCover(restoredCover ? {uri: restoredCover.uri} : null);
        setSelectedSourceProject(draft.selectedSource || null);
        setClientRequestId(draft.clientRequestId);
      }
      // Only a successful read (including confirmed absence) permits writes.
      // A failed read must never turn this initially empty editor into a draft.
      draftReadyRef.current = true;
      setDraftReady(true);
    })().catch(() => {
      if (active && mountedRef.current) setDraftLoadError(true);
    });
    return () => {
      active = false;
      draftReadyRef.current = false;
      persistenceRevisionRef.current += 1;
    };
  }, [captureBoundary, draftLoadAttempt, mountedRef]);

  const retryDraftLoad = useCallback(() => {
    if (!draftLoadError) return;
    setDraftLoadError(false);
    setDraftLoadAttempt(attempt => attempt + 1);
  }, [draftLoadError]);

  useEffect(() => {
    if (!draftReady || !draftReadyRef.current) return;
    const persistenceRevision = persistenceRevisionRef.current;
    const timer = setTimeout(() => {
      void captureBoundary()
        .then(boundary =>
          draftReadyRef.current &&
          persistenceRevision === persistenceRevisionRef.current
            ? writePortfolioEditorDraft(
                {
                  clientRequestId,
                  cover: draftCoverAsset,
                  media: draftMediaAssets,
                  selectedSource: selectedSourceProject || undefined,
                  summary: draftSummary,
                  title: draftTitle,
                  updatedAt: Date.now(),
                },
                boundary,
              )
            : undefined,
        )
        .then(() => {
          if (
            mountedRef.current &&
            persistenceRevision === persistenceRevisionRef.current
          ) {
            setDraftSaveError(false);
          }
        })
        .catch(() => {
          if (
            mountedRef.current &&
            persistenceRevision === persistenceRevisionRef.current
          ) {
            setDraftSaveError(true);
          }
        });
    }, 250);
    return () => clearTimeout(timer);
  }, [
    captureBoundary,
    clientRequestId,
    draftCoverAsset,
    draftMediaAssets,
    draftReady,
    draftSummary,
    draftTitle,
    mountedRef,
    selectedSourceProject,
  ]);

  useEffect(() => {
    if (appActive || !draftReady || !draftReadyRef.current) return;
    const persistenceRevision = persistenceRevisionRef.current;
    void captureBoundary()
      .then(boundary =>
        draftReadyRef.current &&
        persistenceRevision === persistenceRevisionRef.current
          ? writePortfolioEditorDraft(
              {...snapshotRef.current, updatedAt: Date.now()},
              boundary,
            )
          : undefined,
      )
      .catch(() => {
        if (
          mountedRef.current &&
          persistenceRevision === persistenceRevisionRef.current
        ) {
          setDraftSaveError(true);
        }
      });
  }, [appActive, captureBoundary, draftReady, mountedRef]);

  const changeDraft = useCallback((change: () => void) => {
    if (!draftReadyRef.current) return;
    change();
    setClientRequestId(secureRandomUuid());
  }, []);

  const clearDraft = useCallback(
    async (ownerBoundary?: AccountSessionBoundary) => {
      const boundary = ownerBoundary || (await captureBoundary());
      assertAccountSessionBoundary(boundary);
      const previous = draftCoverAsset;
      const previousMedia = draftMediaAssets;
      persistenceRevisionRef.current += 1;
      // Retire under the existing draft lock before another edit or visit can
      // enqueue work. Earlier entered writes stay ahead; callbacks still waiting
      // for their boundary are invalidated by the revision above.
      const cleanup = clearPortfolioEditorDraft(boundary);
      if (mountedRef.current) {
        setDraftTitle('');
        setDraftSummary('');
        setDraftCover(null);
        setDraftCoverAsset(undefined);
        setDraftMediaAssets([]);
        setSelectedSourceProject(null);
        setClientRequestId(secureRandomUuid());
        setDraftSaveError(false);
      }
      await cleanup;
      assertAccountSessionBoundary(boundary);
      await Promise.all([
        removeLearnerDraftFile(previous),
        ...previousMedia.map(removeLearnerDraftFile),
      ]);
    },
    [captureBoundary, draftCoverAsset, draftMediaAssets, mountedRef],
  );

  return {
    changeDraft,
    clearDraft,
    clientRequestId,
    draftCover,
    draftCoverAsset,
    draftMediaAssets,
    draftLoadError,
    draftReady,
    draftSaveError,
    draftSummary,
    draftTitle,
    selectedSourceProject,
    retryDraftLoad,
    setDraftCover,
    setDraftCoverAsset,
    setDraftMediaAssets,
    setDraftSummary,
    setDraftTitle,
    setSelectedSourceProject,
  };
};
