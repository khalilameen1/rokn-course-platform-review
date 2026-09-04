import {useCallback, useEffect, useRef, useState} from 'react';
import type {ImageSourcePropType} from 'react-native';

import type {AccountSessionBoundary} from '../../../constants/helpers';
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
  const [clientRequestId, setClientRequestId] = useState(secureRandomUuid);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const persistenceRevisionRef = useRef(0);
  const persistenceFlightRef = useRef<Promise<void>>(Promise.resolve());
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
    void (async () => {
      const boundary = await captureBoundary();
      if (!active) return;
      const draft = await readPortfolioEditorDraft(boundary);
      if (!active || !draft) return;
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
    })()
      .catch(() => {
        if (active) setDraftSaveError(true);
      })
      .finally(() => {
        if (active) setDraftReady(true);
      });
    return () => {
      active = false;
    };
  }, [captureBoundary]);

  useEffect(() => {
    if (!draftReady) return;
    const persistenceRevision = persistenceRevisionRef.current;
    const timer = setTimeout(() => {
      const flight = captureBoundary()
        .then(boundary =>
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
      persistenceFlightRef.current = flight;
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
    if (appActive || !draftReady) return;
    const persistenceRevision = persistenceRevisionRef.current;
    const flight = captureBoundary()
      .then(boundary =>
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
    persistenceFlightRef.current = flight;
  }, [appActive, captureBoundary, draftReady, mountedRef]);

  const changeDraft = useCallback((change: () => void) => {
    change();
    setClientRequestId(secureRandomUuid());
  }, []);

  const clearDraft = useCallback(
    async (ownerBoundary?: AccountSessionBoundary) => {
      const previous = draftCoverAsset;
      const previousMedia = draftMediaAssets;
      persistenceRevisionRef.current += 1;
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
      try {
        await persistenceFlightRef.current.catch(() => undefined);
        await Promise.all([
          clearPortfolioEditorDraft(ownerBoundary),
          removeLearnerDraftFile(previous),
          ...previousMedia.map(removeLearnerDraftFile),
        ]);
      } catch (error) {
        if (mountedRef.current) setDraftSaveError(true);
        throw error;
      }
    },
    [draftCoverAsset, draftMediaAssets, mountedRef],
  );

  return {
    changeDraft,
    clearDraft,
    clientRequestId,
    draftCover,
    draftCoverAsset,
    draftMediaAssets,
    draftSaveError,
    draftSummary,
    draftTitle,
    selectedSourceProject,
    setDraftCover,
    setDraftCoverAsset,
    setDraftMediaAssets,
    setDraftSummary,
    setDraftTitle,
    setSelectedSourceProject,
  };
};
