import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {launchImageLibrary, type MediaType} from 'react-native-image-picker';

import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
} from '../../../services/learnerDraftFiles';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import {
  createPortfolioItem,
  getEligibleProjects,
  type EligibleProject,
  type PortfolioMedia,
} from '../../../services/roknApi';
import {
  stagePortfolioMediaFiles,
  uploadPortfolioMediaFiles,
} from '../../../services/portfolioMediaUpload';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {settleWithin} from '../../../utils/settleWithin';
import {
  isPortfolioAccountChangedError,
  toPortfolioProject,
  type Project,
} from './portfolioModel';
import {usePortfolioDraftEditor} from './usePortfolioDraftEditor';
import type {PortfolioPublicationResult} from './usePortfolioPublication';

type Options = {
  appActive: boolean;
  busyRef: React.MutableRefObject<boolean>;
  cancelLibraryLoad: () => void;
  captureBoundary: () => Promise<AccountSessionBoundary>;
  finalizeAfterUpload: (
    projectId: string,
    boundary: AccountSessionBoundary,
  ) => Promise<PortfolioPublicationResult>;
  isDetailBusy: () => boolean;
  mountedRef: React.MutableRefObject<boolean>;
  onMediaUploaded: (
    projectId: string,
    media: PortfolioMedia,
    generation: number,
  ) => void;
  reconcileProject: (
    projectId: string,
    boundary: AccountSessionBoundary,
  ) => Promise<unknown>;
  serverSession: boolean | null;
  setLibraryProjects: React.Dispatch<React.SetStateAction<Project[]>>;
};

const portfolioMediaRequestId = (projectRequestId: string, index: number) => {
  const hex = projectRequestId.replace(/-/g, '').toLowerCase();
  if (!/^[0-9a-f]{32}$/.test(hex)) return secureRandomUuid();
  const derived = `${hex.slice(0, 30)}${Math.max(0, index)
    .toString(16)
    .padStart(2, '0')
    .slice(-2)}`;
  return `${derived.slice(0, 8)}-${derived.slice(8, 12)}-${derived.slice(
    12,
    16,
  )}-${derived.slice(16, 20)}-${derived.slice(20)}`;
};

/** Owns eligible source selection, the durable draft and create/upload. */
export const usePortfolioCreateFlow = ({
  appActive,
  busyRef,
  cancelLibraryLoad,
  captureBoundary,
  finalizeAfterUpload,
  isDetailBusy,
  mountedRef,
  onMediaUploaded,
  reconcileProject,
  serverSession,
  setLibraryProjects,
}: Options) => {
  const [adding, setAdding] = useState(false);
  const [saving, setSaving] = useState(false);
  const [uploadProgress, setUploadProgress] = useState<{
    completed: number;
    total: number;
  } | null>(null);
  const [eligibleProjects, setEligibleProjects] = useState<EligibleProject[]>(
    [],
  );
  const [eligibleLoading, setEligibleLoading] = useState(false);
  const eligibleGenerationRef = useRef(0);
  const pickerFlightRef = useRef(false);
  const pickerGenerationRef = useRef(0);
  const {
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
  } = usePortfolioDraftEditor({appActive, captureBoundary, mountedRef});

  useEffect(
    () => () => {
      busyRef.current = false;
      eligibleGenerationRef.current += 1;
      pickerGenerationRef.current += 1;
    },
    [busyRef],
  );

  const openAddProject = useCallback(() => {
    if (serverSession !== true || busyRef.current || isDetailBusy()) return;
    setAdding(true);
    const generation = ++eligibleGenerationRef.current;
    setEligibleLoading(true);
    void captureBoundary()
      .then(boundary => getEligibleProjects(boundary))
      .then(items => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleProjects(items);
        }
      })
      .catch(() => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleProjects([]);
        }
      })
      .finally(() => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleLoading(false);
        }
      });
  }, [busyRef, captureBoundary, isDetailBusy, mountedRef, serverSession]);

  const closeAddProject = useCallback(() => {
    if (busyRef.current) return;
    eligibleGenerationRef.current += 1;
    pickerGenerationRef.current += 1;
    setEligibleLoading(false);
    setAdding(false);
  }, [busyRef]);

  const pickDraftMedia = useCallback(async () => {
    if (
      !draftReady ||
      pickerFlightRef.current ||
      saving ||
      busyRef.current ||
      isDetailBusy()
    ) {
      return;
    }
    pickerFlightRef.current = true;
    const generation = ++pickerGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      const result = await launchImageLibrary({
        mediaType: 'mixed' as MediaType,
        selectionLimit: 12,
        quality: 0.8,
      });
      assertAccountSessionBoundary(boundary);
      if (!mountedRef.current || pickerGenerationRef.current !== generation)
        return;
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const assets = (result.assets || []).filter(asset => asset.uri);
      if (!assets.length) return;
      const cached: Array<{
        uri: string;
        type?: string;
        fileName?: string;
        size?: number;
      }> = [];
      try {
        for (const asset of assets) {
          cached.push(
            await cacheLearnerDraftFile(
              'portfolio',
              {
                uri: String(asset.uri),
                type: asset.type,
                fileName: asset.fileName,
                size: asset.fileSize,
              },
              50 * 1024 * 1024,
              boundary,
            ),
          );
          assertAccountSessionBoundary(boundary);
          if (
            !mountedRef.current ||
            pickerGenerationRef.current !== generation
          ) {
            await Promise.all(cached.map(removeLearnerDraftFile));
            return;
          }
        }
      } catch (error) {
        await Promise.all(cached.map(removeLearnerDraftFile));
        throw error;
      }
      if (!mountedRef.current || pickerGenerationRef.current !== generation) {
        await Promise.all(cached.map(removeLearnerDraftFile));
        return;
      }
      const previous = draftMediaAssets;
      const cover = cached.find(
        file =>
          !String(file.type || '')
            .toLowerCase()
            .startsWith('video/'),
      );
      changeDraft(() => {
        setDraftMediaAssets(cached);
        setDraftCover(cover ? {uri: cover.uri} : null);
        setDraftCoverAsset(cover);
      });
      await Promise.all(previous.map(removeLearnerDraftFile));
    } catch (error: unknown) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        showMediaPickerFailure(
          typeof error === 'object' && error && 'errorCode' in error
            ? String(error.errorCode)
            : undefined,
        );
      }
    } finally {
      pickerFlightRef.current = false;
    }
  }, [
    busyRef,
    captureBoundary,
    changeDraft,
    draftMediaAssets,
    draftReady,
    isDetailBusy,
    mountedRef,
    saving,
    setDraftCover,
    setDraftCoverAsset,
    setDraftMediaAssets,
  ]);

  const chooseSourceProject = useCallback(
    (project: EligibleProject) => {
      if (!draftReady || saving || busyRef.current || isDetailBusy()) return;
      const previous = draftCoverAsset;
      const previousMedia = draftMediaAssets;
      changeDraft(() => {
        setSelectedSourceProject(project);
        setDraftTitle(project.title);
        setDraftSummary(project.summary);
        setDraftCover(project.courseImage ? {uri: project.courseImage} : null);
        setDraftCoverAsset(undefined);
        setDraftMediaAssets([]);
      });
      void Promise.all([
        removeLearnerDraftFile(previous),
        ...previousMedia.map(removeLearnerDraftFile),
      ]);
    },
    [
      busyRef,
      changeDraft,
      draftCoverAsset,
      draftMediaAssets,
      draftReady,
      isDetailBusy,
      saving,
      setDraftCover,
      setDraftCoverAsset,
      setDraftMediaAssets,
      setDraftSummary,
      setDraftTitle,
      setSelectedSourceProject,
    ],
  );

  const clearSelectedSourceProject = useCallback(() => {
    if (!draftReady || saving || busyRef.current || isDetailBusy()) return;
    const previous = draftMediaAssets;
    changeDraft(() => {
      setSelectedSourceProject(null);
      setDraftCover(null);
      setDraftCoverAsset(undefined);
      setDraftMediaAssets([]);
    });
    void Promise.all(previous.map(removeLearnerDraftFile));
  }, [
    busyRef,
    changeDraft,
    draftMediaAssets,
    draftReady,
    isDetailBusy,
    saving,
    setDraftCover,
    setDraftCoverAsset,
    setDraftMediaAssets,
    setSelectedSourceProject,
  ]);

  const updateDraftTitle = useCallback(
    (value: string) => {
      if (draftReady && !saving && !busyRef.current) {
        changeDraft(() => setDraftTitle(value));
      }
    },
    [busyRef, changeDraft, draftReady, saving, setDraftTitle],
  );

  const updateDraftSummary = useCallback(
    (value: string) => {
      if (draftReady && !saving && !busyRef.current) {
        changeDraft(() => setDraftSummary(value));
      }
    },
    [busyRef, changeDraft, draftReady, saving, setDraftSummary],
  );

  const addProject = useCallback(async () => {
    if (
      serverSession !== true ||
      !draftReady ||
      !draftTitle.trim() ||
      !draftMediaAssets.length ||
      saving ||
      busyRef.current ||
      isDetailBusy()
    ) {
      return;
    }
    busyRef.current = true;
    cancelLibraryLoad();
    setSaving(true);
    let remoteProjectCreated = false;
    const input = {
      clientRequestId,
      media: draftMediaAssets,
      source: selectedSourceProject,
      summary: draftSummary.trim(),
      title: draftTitle.trim(),
    };
    try {
      const boundary = await captureBoundary();
      const item = await createPortfolioItem(
        {
          title: input.title,
          summary: input.summary,
          sourceProjectId: input.source?.projectId,
          courseId: input.source?.courseId,
          clientRequestId: input.clientRequestId,
          expectedMediaCount: input.media.length,
        },
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      remoteProjectCreated = true;
      if (mountedRef.current) {
        setLibraryProjects(current => [
          toPortfolioProject(item),
          ...current.filter(project => project.id !== item.id),
        ]);
        if (input.source) {
          setEligibleProjects(current =>
            current.filter(
              candidate => candidate.projectId !== input.source?.projectId,
            ),
          );
        }
      }

      const staged = await stagePortfolioMediaFiles({
        boundary,
        projectId: item.id,
        requestIdAt: index =>
          portfolioMediaRequestId(input.clientRequestId, index),
        sources: input.media,
      });
      if (mountedRef.current && staged.length) {
        setUploadProgress({completed: 0, total: staged.length});
      }
      const {discardedFiles, interrupted} = await uploadPortfolioMediaFiles({
        boundary,
        entries: staged,
        onProgress: (completed, total) => {
          if (mountedRef.current) setUploadProgress({completed, total});
        },
        onUploaded: (projectId, uploaded) =>
          onMediaUploaded(projectId, uploaded, -1),
      });
      if (interrupted && mountedRef.current) {
        Alert.alert(
          'لم يكتمل الرفع',
          'أُضيف المشروع واحتفظنا بالملفات\nسنكملها عند فتحه',
        );
      } else if (discardedFiles && mountedRef.current) {
        Alert.alert(
          'لم تُرفع بعض الملفات',
          'اختر صورة أو فيديو آخر ثم أضفه إلى المشروع',
        );
      }
      if (!interrupted) {
        try {
          const publication = await finalizeAfterUpload(item.id, boundary);
          if (publication === 'processing' && mountedRef.current) {
            Alert.alert(
              'يُجهز الفيديو',
              'سيظهر زر المشاركة فور اكتمال التجهيز',
            );
          }
        } catch (error) {
          if (isPortfolioAccountChangedError(error)) throw error;
          await reconcileProject(item.id, boundary).catch(() => undefined);
          assertAccountSessionBoundary(boundary);
          if (mountedRef.current) {
            Alert.alert(
              'اكتمل رفع المشروع',
              'تعذّر تجهيزه للمشاركة\nافتحه واضغط إتمام المشروع',
            );
          }
        }
      }
      // The server item and durable upload intents already own this work.
      // Keep raw draft retirement ordered, but do not hold the accepted UI open
      // for native storage/file housekeeping.
      await settleWithin(clearDraft(boundary), undefined);
      assertAccountSessionBoundary(boundary);
      if (mountedRef.current) setAdding(false);
    } catch (error: unknown) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        Alert.alert(
          remoteProjectCreated ? 'حُفظ المشروع كمسودة' : 'تعذّر إضافة المشروع',
          remoteProjectCreated
            ? 'تعذّر تجهيز بعض الملفات\nستبقى محفوظة لإكمال الرفع'
            : learnerErrorMessage(error, 'لم يُضف المشروع\nحاول مرة أخرى'),
        );
      }
    } finally {
      busyRef.current = false;
      if (mountedRef.current) {
        setSaving(false);
        setUploadProgress(null);
      }
    }
  }, [
    busyRef,
    cancelLibraryLoad,
    captureBoundary,
    clearDraft,
    clientRequestId,
    draftMediaAssets,
    draftReady,
    draftSummary,
    draftTitle,
    finalizeAfterUpload,
    isDetailBusy,
    mountedRef,
    onMediaUploaded,
    reconcileProject,
    saving,
    selectedSourceProject,
    serverSession,
    setLibraryProjects,
  ]);

  return {
    addProject,
    adding,
    chooseSourceProject,
    clearSelectedSourceProject,
    closeAddProject,
    draftCover,
    draftMediaAssets,
    draftLoadError,
    draftReady,
    draftSaveError,
    draftSummary,
    draftTitle,
    eligibleLoading,
    eligibleProjects,
    openAddProject,
    pickCover: pickDraftMedia,
    retryDraftLoad,
    saving,
    selectedSourceProject,
    updateDraftSummary,
    updateDraftTitle,
    uploadProgress,
  };
};
