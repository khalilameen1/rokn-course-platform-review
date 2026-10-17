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
import {loadProjectSubmissionDraft} from '../../../services/projectSubmissionDraft';
import {
  createPortfolioItem,
  assertPortfolioUploadAccess,
  getEligibleProjects,
  type EligibleProject,
  type PortfolioMedia,
} from '../../../services/roknApi';
import {
  stagePortfolioMediaFiles,
  uploadPortfolioMediaFiles,
} from '../../../services/portfolioMediaUpload';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {
  showPortfolioUploadGate,
  type PortfolioSubscriptionAction,
} from '../../../components/portfolioUploadGate';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {settleWithin} from '../../../utils/settleWithin';
import {
  isPortfolioAccountChangedError,
  PORTFOLIO_PICKER_IMAGE_OPTIONS,
  toPortfolioProject,
  type Project,
} from './portfolioModel';
import {
  usePortfolioDraftEditor,
  type PortfolioDraftAsset,
} from './usePortfolioDraftEditor';
import type {PortfolioPublicationResult} from './usePortfolioPublication';
import type {PortfolioUploadProgress} from '../../../services/portfolioUploadProgress';
import {usePortfolioUploadSession} from './usePortfolioUploadSession';
import {MAX_PORTFOLIO_MEDIA_COUNT} from '../../../services/portfolioMediaPolicy';

type Options = {
  onSubscriptions?: PortfolioSubscriptionAction;
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
  /** One-shot "add this passed project" request from the project screen. */
  addFromProjectId?: string;
  onAddFromProjectHandled?: () => void;
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

const discardPickerFiles = (files: PortfolioDraftAsset[]) => {
  // Selection is already accepted or retired. Keep native cleanup ordered,
  // without letting obsolete files hold the picker or submission locked.
  void Promise.all(files.map(removeLearnerDraftFile)).catch(() => undefined);
};

const PORTFOLIO_IMAGE_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
const PORTFOLIO_DRAFT_FILE_MAX_BYTES = 50 * 1024 * 1024;

/**
 * Submitted project images still kept on this device (an unsent or restored
 * submission draft). Accepted submissions retire their files, so this is
 * usually empty and the picker opens instead.
 */
const submittedProjectImages = async (
  projectId: string,
  boundary: AccountSessionBoundary,
): Promise<PortfolioDraftAsset[]> => {
  const draft = await loadProjectSubmissionDraft(projectId, boundary).catch(
    () => null,
  );
  assertAccountSessionBoundary(boundary);
  const images = (draft?.files || [])
    .filter(file =>
      PORTFOLIO_IMAGE_TYPES.includes(String(file.type || '').toLowerCase()),
    )
    .slice(0, MAX_PORTFOLIO_MEDIA_COUNT);
  const copies: PortfolioDraftAsset[] = [];
  for (const file of images) {
    try {
      copies.push(
        await cacheLearnerDraftFile(
          'portfolio',
          {uri: file.uri, type: file.type, fileName: file.name, size: file.size},
          PORTFOLIO_DRAFT_FILE_MAX_BYTES,
          boundary,
        ),
      );
    } catch (error) {
      if (isPortfolioAccountChangedError(error)) {
        discardPickerFiles(copies);
        throw error;
      }
      // A file the OS already removed is simply not offered.
    }
  }
  return copies;
};

/** Owns eligible source selection, the durable draft and create/upload. */
export const usePortfolioCreateFlow = ({
  onSubscriptions,
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
  addFromProjectId,
  onAddFromProjectHandled,
}: Options) => {
  const [adding, setAdding] = useState(false);
  const [saving, setSaving] = useState(false);
  const [pickingMedia, setPickingMedia] = useState(false);
  const uploadSession = usePortfolioUploadSession(mountedRef);
  const {
    begin: beginUpload,
    end: endUpload,
    pause: pauseUpload,
  } = uploadSession;
  const [uploadProgress, setUploadProgress] =
    useState<PortfolioUploadProgress | null>(null);
  const [eligibleProjects, setEligibleProjects] = useState<EligibleProject[]>(
    [],
  );
  const [eligibleLoading, setEligibleLoading] = useState(false);
  const eligibleGenerationRef = useRef(0);
  const pickerFlightRef = useRef(false);
  const pickerGenerationRef = useRef(0);
  // "Add to portfolio" after a passed project: preselect that project, then
  // its files or the photo picker once the sheet is on screen.
  const [pendingSourceProjectId, setPendingSourceProjectId] = useState<
    string | null
  >(null);
  const [autoPickMedia, setAutoPickMedia] = useState(false);
  const [addSheetShown, setAddSheetShown] = useState(false);
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
    const generation = ++eligibleGenerationRef.current;
    setEligibleLoading(true);
    void captureBoundary()
      .then(async boundary => {
        await assertPortfolioUploadAccess(boundary);
        if (!mountedRef.current || eligibleGenerationRef.current !== generation)
          return [];
        setAdding(true);
        // Suggestions are optional and do not determine upload entitlement.
        return getEligibleProjects(boundary).catch(() => []);
      })
      .then(items => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleProjects(items);
        }
      })
      .catch((error: unknown) => {
        if (
          mountedRef.current &&
          eligibleGenerationRef.current === generation
        ) {
          setEligibleProjects([]);
          if (!isPortfolioAccountChangedError(error)) {
            if (
              showPortfolioUploadGate(error, onSubscriptions, () =>
                setAdding(false),
              )
            )
              return;
            Alert.alert(
              'إضافة عمل',
              learnerErrorMessage(
                error,
                'تعذّر التحقق من الاشتراك\nحاول مرة أخرى',
              ),
            );
          }
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
  }, [
    busyRef,
    captureBoundary,
    isDetailBusy,
    mountedRef,
    serverSession,
    onSubscriptions,
  ]);

  const closeAddProject = useCallback(() => {
    if (busyRef.current) {
      void pauseUpload();
      return;
    }
    eligibleGenerationRef.current += 1;
    pickerGenerationRef.current += 1;
    setEligibleLoading(false);
    setPendingSourceProjectId(null);
    setAutoPickMedia(false);
    setAddSheetShown(false);
    setAdding(false);
  }, [busyRef, pauseUpload]);

  const openAddProjectFrom = useCallback(
    (sourceProjectId: string) => {
      if (serverSession !== true || busyRef.current || isDetailBusy()) return;
      setPendingSourceProjectId(sourceProjectId);
      openAddProject();
    },
    [busyRef, isDetailBusy, openAddProject, serverSession],
  );

  const markAddSheetShown = useCallback(() => setAddSheetShown(true), []);

  const handledAddFromRef = useRef<string | null>(null);
  useEffect(() => {
    if (!addFromProjectId) {
      handledAddFromRef.current = null;
      return;
    }
    if (
      serverSession !== true ||
      handledAddFromRef.current === addFromProjectId
    )
      return;
    handledAddFromRef.current = addFromProjectId;
    openAddProjectFrom(addFromProjectId);
    onAddFromProjectHandled?.();
  }, [
    addFromProjectId,
    onAddFromProjectHandled,
    openAddProjectFrom,
    serverSession,
  ]);

  const pickDraftMedia = useCallback(async () => {
    if (
      !draftReady ||
      draftMediaAssets.length >= MAX_PORTFOLIO_MEDIA_COUNT ||
      pickerFlightRef.current ||
      saving ||
      busyRef.current ||
      isDetailBusy()
    ) {
      return;
    }
    pickerFlightRef.current = true;
    setPickingMedia(true);
    const generation = ++pickerGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      await assertPortfolioUploadAccess(boundary);
      assertAccountSessionBoundary(boundary);
      if (!mountedRef.current || pickerGenerationRef.current !== generation)
        return;
      const result = await launchImageLibrary({
        mediaType: 'mixed' as MediaType,
        selectionLimit: MAX_PORTFOLIO_MEDIA_COUNT - draftMediaAssets.length,
        ...PORTFOLIO_PICKER_IMAGE_OPTIONS,
      });
      assertAccountSessionBoundary(boundary);
      if (!mountedRef.current || pickerGenerationRef.current !== generation)
        return;
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const assets = (result.assets || [])
        .filter(asset => asset.uri)
        .slice(0, MAX_PORTFOLIO_MEDIA_COUNT - draftMediaAssets.length);
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
            discardPickerFiles(cached);
            return;
          }
        }
      } catch (error) {
        discardPickerFiles(cached);
        throw error;
      }
      if (!mountedRef.current || pickerGenerationRef.current !== generation) {
        discardPickerFiles(cached);
        return;
      }
      const next = [...draftMediaAssets, ...cached];
      const cover = next.find(
        file =>
          !String(file.type || '')
            .toLowerCase()
            .startsWith('video/'),
      );
      changeDraft(() => {
        setDraftMediaAssets(next);
        setDraftCover(cover ? {uri: cover.uri} : null);
        setDraftCoverAsset(cover);
      });
    } catch (error: unknown) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        if (
          !showPortfolioUploadGate(error, onSubscriptions, () =>
            setAdding(false),
          )
        ) {
          showMediaPickerFailure(
            typeof error === 'object' && error && 'errorCode' in error
              ? String(error.errorCode)
              : undefined,
          );
        }
      }
    } finally {
      pickerFlightRef.current = false;
      if (mountedRef.current) setPickingMedia(false);
    }
  }, [
    busyRef,
    captureBoundary,
    changeDraft,
    draftMediaAssets,
    draftReady,
    isDetailBusy,
    mountedRef,
    onSubscriptions,
    saving,
    setDraftCover,
    setDraftCoverAsset,
    setDraftMediaAssets,
  ]);

  const removeDraftMedia = useCallback(
    (file: PortfolioDraftAsset) => {
      if (
        !draftReady ||
        pickerFlightRef.current ||
        saving ||
        busyRef.current ||
        isDetailBusy()
      ) {
        return;
      }
      if (!draftMediaAssets.some(asset => asset.uri === file.uri)) return;
      const next = draftMediaAssets.filter(asset => asset.uri !== file.uri);
      const cover = next.find(
        asset =>
          !String(asset.type || '')
            .toLowerCase()
            .startsWith('video/'),
      );
      changeDraft(() => {
        setDraftMediaAssets(next);
        setDraftCover(cover ? {uri: cover.uri} : null);
        setDraftCoverAsset(cover);
      });
      // The draft writer retires files only after the new snapshot is durable.
    },
    [
      busyRef,
      changeDraft,
      draftMediaAssets,
      draftReady,
      isDetailBusy,
      saving,
      setDraftCover,
      setDraftCoverAsset,
      setDraftMediaAssets,
    ],
  );

  const chooseSourceProject = useCallback(
    (project: EligibleProject) => {
      if (
        !draftReady ||
        pickerFlightRef.current ||
        saving ||
        busyRef.current ||
        isDetailBusy()
      )
        return;
      const previous = draftCoverAsset;
      const previousMedia = draftMediaAssets;
      const previousBrief = selectedSourceProject?.summary;
      changeDraft(() => {
        setSelectedSourceProject(project);
        setDraftTitle(project.title);
        // The teacher's brief is not the learner's story. Keep what they
        // wrote; only drop a brief that older builds copied in.
        setDraftSummary(current =>
          current.trim() && current !== previousBrief && current !== project.summary
            ? current
            : '',
        );
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
      selectedSourceProject?.summary,
      setDraftCover,
      setDraftCoverAsset,
      setDraftMediaAssets,
      setDraftSummary,
      setDraftTitle,
      setSelectedSourceProject,
    ],
  );

  useEffect(() => {
    if (
      !pendingSourceProjectId ||
      !adding ||
      eligibleLoading ||
      !draftReady ||
      saving ||
      pickerFlightRef.current
    )
      return;
    const sourceProjectId = pendingSourceProjectId;
    setPendingSourceProjectId(null);
    const project = eligibleProjects.find(
      candidate => candidate.projectId === sourceProjectId,
    );
    if (!project) return;
    chooseSourceProject(project);
    const generation = ++pickerGenerationRef.current;
    pickerFlightRef.current = true;
    setPickingMedia(true);
    void captureBoundary()
      .then(boundary => submittedProjectImages(sourceProjectId, boundary))
      .then(files => {
        if (!mountedRef.current || pickerGenerationRef.current !== generation) {
          discardPickerFiles(files);
          return;
        }
        if (!files.length) {
          setAutoPickMedia(true);
          return;
        }
        const cover = files[0];
        changeDraft(() => {
          setDraftMediaAssets(files);
          setDraftCover(cover ? {uri: cover.uri} : null);
          setDraftCoverAsset(cover);
        });
      })
      .catch(() => {
        if (mountedRef.current && pickerGenerationRef.current === generation)
          setAutoPickMedia(true);
      })
      .finally(() => {
        if (pickerGenerationRef.current === generation)
          pickerFlightRef.current = false;
        if (mountedRef.current) setPickingMedia(false);
      });
  }, [
    adding,
    captureBoundary,
    changeDraft,
    chooseSourceProject,
    draftReady,
    eligibleLoading,
    eligibleProjects,
    mountedRef,
    pendingSourceProjectId,
    saving,
    setDraftCover,
    setDraftCoverAsset,
    setDraftMediaAssets,
  ]);

  useEffect(() => {
    // iOS cannot present the photo picker over a sheet that is still
    // animating in, so wait for the Modal's onShow.
    if (!autoPickMedia || !addSheetShown || !adding || pickerFlightRef.current)
      return;
    setAutoPickMedia(false);
    void pickDraftMedia();
  }, [addSheetShown, adding, autoPickMedia, pickDraftMedia, pickingMedia]);

  const clearSelectedSourceProject = useCallback(() => {
    if (
      !draftReady ||
      pickerFlightRef.current ||
      saving ||
      busyRef.current ||
      isDetailBusy()
    )
      return;
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
      pickerFlightRef.current ||
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
      beginUpload(item.id, boundary);
      const {
        discardedFiles,
        interrupted,
        paused: pausedResult,
      } = await uploadPortfolioMediaFiles({
        boundary,
        entries: staged,
        onProgress: progress => {
          assertAccountSessionBoundary(boundary);
          if (mountedRef.current) setUploadProgress(progress);
        },
        onUploaded: (projectId, uploaded) =>
          onMediaUploaded(projectId, uploaded, -1),
      });
      const paused = (await endUpload()) || pausedResult === true;
      if (interrupted && !paused && mountedRef.current) {
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
      if (!interrupted && !paused) {
        try {
          const publication = await finalizeAfterUpload(item.id, boundary);
          if (publication === 'processing' && mountedRef.current) {
            Alert.alert(
              'يُجهز الفيديو',
              'ستتم مراجعة أعمالك بعد اكتمال التجهيز',
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
        if (
          showPortfolioUploadGate(error, onSubscriptions, () =>
            setAdding(false),
          )
        )
          return;
        Alert.alert(
          remoteProjectCreated ? 'حُفظ المشروع كمسودة' : 'تعذّر إضافة المشروع',
          remoteProjectCreated
            ? 'تعذّر تجهيز بعض الملفات\nستبقى محفوظة لإكمال الرفع'
            : learnerErrorMessage(error, 'لم يُضف المشروع\nحاول مرة أخرى'),
        );
      }
    } finally {
      await endUpload();
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
    beginUpload,
    endUpload,
    isDetailBusy,
    mountedRef,
    onMediaUploaded,
    onSubscriptions,
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
    markAddSheetShown,
    openAddProjectFrom,
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
    pickDraftMedia,
    removeDraftMedia,
    pickingMedia,
    retryDraftLoad,
    saving,
    selectedSourceProject,
    updateDraftSummary,
    updateDraftTitle,
    uploadProgress,
    canPauseCreateUpload: uploadSession.canPause,
    pausingCreateUpload: uploadSession.pausing,
  };
};
