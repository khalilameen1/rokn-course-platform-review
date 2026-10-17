import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {launchImageLibrary, type MediaType} from 'react-native-image-picker';

import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  deletePortfolioItem,
  assertPortfolioUploadAccess,
  deletePortfolioMedia,
  updatePortfolioItem,
  type PortfolioItem,
  type PortfolioMedia,
} from '../../../services/roknApi';
import type {PortfolioItemPatch} from '../../../services/api/portfolio';
import {
  normalizePortfolioChips,
  PORTFOLIO_TOOLS_MAX,
} from '../../../services/api/portfolioContract';
import {
  discardPortfolioMediaUploads,
  listPortfolioMediaUploads,
  setPortfolioMediaUploadsPaused,
  type PortfolioMediaOutboxEntry,
} from '../../../services/portfolioMediaOutbox';
import {
  stagePortfolioMediaFiles,
  uploadPortfolioMediaFiles,
} from '../../../services/portfolioMediaUpload';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import {
  showPortfolioUploadGate,
  type PortfolioSubscriptionAction,
} from '../../../components/portfolioUploadGate';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {portfolioAction, portfolioMediaSlots} from '../portfolioState';
import {
  isPortfolioAccountChangedError,
  PORTFOLIO_PICKER_IMAGE_OPTIONS,
  sortPortfolioProjects,
  type Project,
} from './portfolioModel';
import {usePortfolioPublication} from './usePortfolioPublication';
import {usePortfolioProjectSelection} from './usePortfolioProjectSelection';
import type {PortfolioUploadProgress} from '../../../services/portfolioUploadProgress';
import {usePortfolioUploadSession} from './usePortfolioUploadSession';

type Options = {
  onSubscriptions?: PortfolioSubscriptionAction;
  cancelLibraryLoad: () => void;
  /** Displayed library order, for move up/down. */
  libraryProjects?: Project[];
  captureBoundary: () => Promise<AccountSessionBoundary>;
  isCreateBusy: () => boolean;
  mountedRef: React.MutableRefObject<boolean>;
  setLibraryProjects: React.Dispatch<React.SetStateAction<Project[]>>;
  setMutationBlocked: (blocked: boolean) => void;
};

const EMPTY_PROJECTS: Project[] = [];
const CHIP_SEPARATORS = /[,،\n]/;

/** Behance/Dribbble editors accept a blank or a full https project link. */
export const portfolioExternalUrlError = (value: string) => {
  const url = value.trim();
  return !url || /^https:\/\/[^\s/]+\.[^\s]+$/i.test(url)
    ? ''
    : 'الرابط لازم يبدأ بـ https://';
};

/** Owns the details → edit/media/finalize/delete half of Gallery. */
export const usePortfolioProjectDetails = ({
  onSubscriptions,
  cancelLibraryLoad,
  libraryProjects = EMPTY_PROJECTS,
  captureBoundary,
  isCreateBusy,
  mountedRef,
  setLibraryProjects,
  setMutationBlocked,
}: Options) => {
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState('');
  const [editSummary, setEditSummary] = useState('');
  const [editRole, setEditRole] = useState('');
  const [editTools, setEditTools] = useState<string[]>([]);
  const [editToolInput, setEditToolInput] = useState('');
  const [editExternalUrl, setEditExternalUrl] = useState('');
  const libraryProjectsRef = useRef(libraryProjects);
  libraryProjectsRef.current = libraryProjects;
  const [saving, setSaving] = useState(false);
  const [uploadPreparing, setUploadPreparing] = useState(false);
  const uploadSession = usePortfolioUploadSession(mountedRef);
  const {
    begin: beginUpload,
    end: endUpload,
    pause: pauseUpload,
  } = uploadSession;
  const [pendingReadRevision, setPendingReadRevision] = useState(0);
  const [pendingUploads, setPendingUploads] = useState<{
    projectId: string;
    status: 'ready' | 'error';
    count: number;
    paused: boolean;
  } | null>(null);
  const [mediaUploadProgress, setMediaUploadProgress] =
    useState<PortfolioUploadProgress | null>(null);
  const mutationFlightRef = useRef<symbol | null>(null);
  const uploadFlightRef = useRef<symbol | null>(null);
  const selection = usePortfolioProjectSelection({
    captureBoundary,
    isCreateBusy,
    mountedRef,
    mutationFlightRef,
    setLibraryProjects,
  });
  const {
    applyUploadedMedia,
    cancelDetailLoad,
    closeSelection,
    commitRemoteProject,
    detailGenerationRef,
    detailLoading,
    handleMediaDeliveryError,
    handleMediaDeliverySuccess,
    openSelection,
    previewMedia,
    reconcileProject,
    refreshOpenProject,
    removeMediaLocally,
    selected,
    selectedRef,
    selectPreviewMedia,
  } = selection;
  useEffect(() => {
    const projectId = selected?.source === 'remote' ? selected.id : null;
    if (!projectId) {
      setPendingUploads(null);
      return;
    }
    let current = true;
    const generation = detailGenerationRef.current;
    void captureBoundary()
      .then(async boundary => {
        const entries = await listPortfolioMediaUploads(projectId, boundary);
        assertAccountSessionBoundary(boundary);
        if (
          current &&
          mountedRef.current &&
          detailGenerationRef.current === generation &&
          selectedRef.current?.id === projectId
        ) {
          setPendingUploads({
            projectId,
            status: 'ready',
            count: entries.length,
            paused: entries.some(entry => entry.paused === true),
          });
        }
      })
      .catch(error => {
        if (
          current &&
          mountedRef.current &&
          detailGenerationRef.current === generation &&
          selectedRef.current?.id === projectId &&
          !isPortfolioAccountChangedError(error)
        )
          setPendingUploads({
            projectId,
            status: 'error',
            count: 0,
            paused: false,
          });
      });
    return () => {
      current = false;
    };
  }, [
    captureBoundary,
    detailGenerationRef,
    mountedRef,
    pendingReadRevision,
    saving,
    selected?.id,
    selected?.source,
    selected?.uploadedMediaCount,
    selectedRef,
  ]);
  const pendingUploadsReady = Boolean(
    selected &&
      pendingUploads &&
      selected.id === pendingUploads.projectId &&
      pendingUploads.status === 'ready',
  );
  const hasPendingUploads =
    pendingUploadsReady && (pendingUploads?.count ?? 0) > 0;
  const retryPendingUploads = useCallback(
    () => setPendingReadRevision(current => current + 1),
    [],
  );
  const isMutationActive = useCallback(
    () => Boolean(mutationFlightRef.current),
    [],
  );
  const commitPublication = useCallback(
    (item: PortfolioItem) => {
      // Publication has its own mutation generation guard. A fresh retry can
      // update the currently-open item without reopening an older selection.
      commitRemoteProject(
        item,
        selectedRef.current?.id === item.id
          ? detailGenerationRef.current
          : undefined,
      );
    },
    [commitRemoteProject, detailGenerationRef, selectedRef],
  );
  const {finalizeAfterUpload, invalidatePublication} = usePortfolioPublication({
    commit: commitPublication,
    isMutationActive,
    mountedRef,
  });

  useEffect(
    () => () => {
      mutationFlightRef.current = null;
      uploadFlightRef.current = null;
      setMutationBlocked(false);
    },
    [setMutationBlocked],
  );

  const beginMutation = useCallback(
    (showSaving = true) => {
      if (mutationFlightRef.current) return null;
      const flight = Symbol('portfolio-project-mutation');
      mutationFlightRef.current = flight;
      const projectId = selectedRef.current?.id;
      if (projectId) invalidatePublication(projectId);
      setMutationBlocked(true);
      cancelLibraryLoad();
      cancelDetailLoad();
      if (showSaving) setSaving(true);
      return flight;
    },
    [
      cancelDetailLoad,
      cancelLibraryLoad,
      invalidatePublication,
      selectedRef,
      setMutationBlocked,
    ],
  );

  const finishMutation = useCallback(
    (flight: symbol) => {
      if (mutationFlightRef.current !== flight) return;
      mutationFlightRef.current = null;
      if (uploadFlightRef.current === flight) uploadFlightRef.current = null;
      setMutationBlocked(false);
      if (mountedRef.current) {
        setSaving(false);
        setUploadPreparing(false);
        setMediaUploadProgress(null);
      }
    },
    [mountedRef, setMutationBlocked],
  );

  const beginUploadMutation = useCallback(
    (showSaving = true) => {
      const flight = beginMutation(showSaving);
      if (flight) {
        uploadFlightRef.current = flight;
        setUploadPreparing(true);
      }
      return flight;
    },
    [beginMutation],
  );

  const settleUploadedProjects = useCallback(
    async (projectIds: string[]) => {
      if (!projectIds.length) return;
      const boundary = await captureBoundary();
      await Promise.allSettled(
        projectIds.map(async projectId => {
          // A visible server item may already contain the first successful file
          // while later files are still in the durable queue. Finalizing there
          // would publish a partial portfolio and shrink its expected count.
          const pending = await listPortfolioMediaUploads(projectId, boundary);
          if (pending.length) return 'processing' as const;
          return finalizeAfterUpload(projectId, boundary);
        }),
      );
    },
    [captureBoundary, finalizeAfterUpload],
  );

  const openProject = useCallback(
    (project: Project) => {
      setEditing(false);
      openSelection(project);
    },
    [openSelection],
  );

  const closeProject = useCallback(() => {
    if (uploadFlightRef.current) {
      // Preparation has no cancellable transport yet. Keep its selection
      // owner; once staged, Back and the visible stop action share pause.
      void pauseUpload();
      return;
    }
    setEditing(false);
    closeSelection();
  }, [closeSelection, pauseUpload]);

  const ownsSelectedUpload = useCallback(
    (projectId: string, generation: number, flight: symbol) =>
      mountedRef.current &&
      mutationFlightRef.current === flight &&
      uploadFlightRef.current === flight &&
      detailGenerationRef.current === generation &&
      selectedRef.current?.id === projectId,
    [detailGenerationRef, mountedRef, selectedRef],
  );

  const beginEdit = useCallback(() => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    setEditTitle(current.title);
    setEditSummary(current.summary);
    setEditRole(current.role || '');
    setEditTools(current.tools);
    setEditToolInput('');
    setEditExternalUrl(current.externalUrl || '');
    setEditing(true);
  }, [saving, selectedRef]);

  const addEditTool = useCallback(() => {
    setEditTools(
      normalizePortfolioChips(
        [...editTools, ...editToolInput.split(CHIP_SEPARATORS)],
        PORTFOLIO_TOOLS_MAX,
      ),
    );
    setEditToolInput('');
  }, [editToolInput, editTools]);

  const removeEditTool = useCallback((tool: string) => {
    setEditTools(current => current.filter(candidate => candidate !== tool));
  }, []);

  const cancelEditing = useCallback(() => {
    if (!saving && !mutationFlightRef.current) setEditing(false);
  }, [saving]);

  const saveProjectEdits = useCallback(async () => {
    const current = selectedRef.current;
    if (!current || !editTitle.trim() || saving || mutationFlightRef.current)
      return;
    const urlError = portfolioExternalUrlError(editExternalUrl);
    if (urlError) {
      Alert.alert('راجع رابط المشروع', urlError);
      return;
    }
    const flight = beginMutation();
    if (!flight) return;
    const projectId = current.id;
    const generation = detailGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      const item = await updatePortfolioItem(
        projectId,
        {
          title: editTitle.trim(),
          summary: editSummary.trim(),
          role: editRole.trim(),
          // A typed but not yet added chip is still the learner's intent.
          tools: normalizePortfolioChips(
            [...editTools, ...editToolInput.split(CHIP_SEPARATORS)],
            PORTFOLIO_TOOLS_MAX,
          ),
          externalUrl: editExternalUrl.trim(),
        },
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      commitRemoteProject(item, generation);
      if (
        mountedRef.current &&
        detailGenerationRef.current === generation &&
        selectedRef.current?.id === projectId
      ) {
        setEditing(false);
      }
    } catch (error: unknown) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        Alert.alert(
          'تعذّر حفظ التعديل',
          learnerErrorMessage(error, 'مشروعك لم يتغير\nحاول مرة أخرى'),
        );
      }
    } finally {
      finishMutation(flight);
    }
  }, [
    beginMutation,
    captureBoundary,
    commitRemoteProject,
    detailGenerationRef,
    editExternalUrl,
    editRole,
    editSummary,
    editTitle,
    editToolInput,
    editTools,
    finishMutation,
    mountedRef,
    saving,
    selectedRef,
  ]);

  /**
   * One presentation change of the open project (visibility, cover,
   * featured). Shares the detail mutation guard with edit/delete/upload.
   */
  const patchSelectedProject = useCallback(
    async (
      patch: Pick<PortfolioItemPatch, 'visible' | 'coverMediaId' | 'featured'>,
      failureTitle: string,
    ) => {
      const current = selectedRef.current;
      if (!current || saving || mutationFlightRef.current) return;
      const flight = beginMutation();
      if (!flight) return;
      const generation = detailGenerationRef.current;
      try {
        const boundary = await captureBoundary();
        const item = await updatePortfolioItem(current.id, patch, boundary);
        assertAccountSessionBoundary(boundary);
        commitRemoteProject(item, generation);
        if (patch.featured !== undefined && mountedRef.current)
          setLibraryProjects(sortPortfolioProjects);
      } catch (error: unknown) {
        if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
          Alert.alert(
            failureTitle,
            learnerErrorMessage(error, 'مشروعك متغيرش\nجرب تاني'),
          );
        }
      } finally {
        finishMutation(flight);
      }
    },
    [
      beginMutation,
      captureBoundary,
      commitRemoteProject,
      detailGenerationRef,
      finishMutation,
      mountedRef,
      saving,
      selectedRef,
      setLibraryProjects,
    ],
  );

  const toggleSelectedVisibility = useCallback(() => {
    const current = selectedRef.current;
    if (!current) return;
    void patchSelectedProject(
      {visible: Boolean(current.hidden)},
      current.hidden ? 'تعذّر إظهار المشروع' : 'تعذّر إخفاء المشروع',
    );
  }, [patchSelectedProject, selectedRef]);

  const toggleSelectedFeatured = useCallback(() => {
    const current = selectedRef.current;
    if (!current) return;
    void patchSelectedProject(
      {featured: !current.featured},
      'تعذّر تثبيت المشروع',
    );
  }, [patchSelectedProject, selectedRef]);

  const chooseSelectedCover = useCallback(
    (media: PortfolioMedia) => {
      const current = selectedRef.current;
      if (
        !current ||
        media.type !== 'image' ||
        current.coverMediaId === media.id
      )
        return;
      void patchSelectedProject({coverMediaId: media.id}, 'تعذّر تغيير الغلاف');
    },
    [patchSelectedProject, selectedRef],
  );

  /** Neighbours within the same featured group, as the server orders them. */
  const selectedOrderGroup = useCallback(
    (current: Project | null = selectedRef.current) => {
      if (!current) return null;
      const group = sortPortfolioProjects(libraryProjectsRef.current).filter(
        project => Boolean(project.featured) === Boolean(current.featured),
      );
      const index = group.findIndex(project => project.id === current.id);
      return index < 0 ? null : {group, index};
    },
    [selectedRef],
  );

  const moveSelectedProject = useCallback(
    async (direction: -1 | 1) => {
      const placement = selectedOrderGroup();
      if (!placement || saving || mutationFlightRef.current) return;
      const {group, index} = placement;
      const target = index + direction;
      if (target < 0 || target >= group.length) return;
      const reordered = [...group];
      [reordered[index], reordered[target]] = [
        reordered[target],
        reordered[index],
      ];
      // Explicit positions: projects created with sort_order 0 tie otherwise.
      const changes = reordered
        .map((project, position) => ({project, position}))
        .filter(({project, position}) => (project.sortOrder ?? 0) !== position);
      const flight = beginMutation();
      if (!flight) return;
      const generation = detailGenerationRef.current;
      try {
        const boundary = await captureBoundary();
        for (const {project, position} of changes) {
          const item = await updatePortfolioItem(
            project.id,
            {sortOrder: position},
            boundary,
          );
          assertAccountSessionBoundary(boundary);
          commitRemoteProject(
            item,
            selectedRef.current?.id === item.id ? generation : undefined,
          );
        }
      } catch (error: unknown) {
        if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
          Alert.alert(
            'تعذّر تغيير الترتيب',
            learnerErrorMessage(error, 'جرب تاني'),
          );
        }
      } finally {
        if (mountedRef.current) setLibraryProjects(sortPortfolioProjects);
        finishMutation(flight);
      }
    },
    [
      beginMutation,
      captureBoundary,
      commitRemoteProject,
      detailGenerationRef,
      finishMutation,
      mountedRef,
      saving,
      selectedOrderGroup,
      selectedRef,
      setLibraryProjects,
    ],
  );

  const selectedPlacement = selectedOrderGroup(selected);

  const finalizeSelectedProject = useCallback(async () => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    const flight = beginMutation();
    if (!flight) return;
    const projectId = current.id;
    try {
      const boundary = await captureBoundary();
      if ((await listPortfolioMediaUploads(projectId, boundary)).length) {
        if (mountedRef.current)
          Alert.alert('الرفع غير مكتمل', 'أكمل رفع الملفات أولاً');
        return;
      }
      const publication = await finalizeAfterUpload(projectId, boundary, {
        ownsMutation: true,
      });
      if (publication === 'published') {
        await discardPortfolioMediaUploads(projectId, boundary).catch(
          () => undefined,
        );
      } else if (mountedRef.current) {
        Alert.alert(
          publication === 'processing' ? 'يُجهز الفيديو' : 'المشروع غير مكتمل',
          publication === 'processing'
            ? 'ستتم مراجعة أعمالك بعد اكتمال التجهيز'
            : 'أضف صورة أو فيديو جاهزًا ثم حاول مرة أخرى',
        );
      }
    } catch (error: unknown) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        Alert.alert(
          'تعذّر إتمام المشروع',
          learnerErrorMessage(error, 'حاول مرة أخرى'),
        );
      }
    } finally {
      finishMutation(flight);
    }
  }, [
    beginMutation,
    captureBoundary,
    finalizeAfterUpload,
    finishMutation,
    mountedRef,
    saving,
    selectedRef,
  ]);

  const uploadSelectedEntries = useCallback(
    async (
      projectId: string,
      entries: PortfolioMediaOutboxEntry[],
      boundary: AccountSessionBoundary,
      generation: number,
      flight: symbol,
    ) => {
      assertAccountSessionBoundary(boundary);
      if (!ownsSelectedUpload(projectId, generation, flight)) return;
      beginUpload(projectId, boundary);
      const {
        discardedFiles,
        interrupted,
        paused: pausedResult,
      } = await uploadPortfolioMediaFiles({
        boundary,
        entries,
        onProgress: progress => {
          assertAccountSessionBoundary(boundary);
          if (
            mountedRef.current &&
            mutationFlightRef.current === flight &&
            detailGenerationRef.current === generation &&
            selectedRef.current?.id === projectId
          )
            setMediaUploadProgress(progress);
        },
        onUploaded: (uploadedProjectId, uploaded) =>
          applyUploadedMedia(uploadedProjectId, uploaded, generation),
      });
      const paused = (await endUpload()) || pausedResult === true;
      if (interrupted && !paused && mountedRef.current)
        Alert.alert(
          'لم يكتمل الرفع',
          'احتفظنا بالملفات وسنكملها عند فتح المشروع',
        );
      else if (discardedFiles && mountedRef.current)
        Alert.alert(
          'لم تُرفع بعض الملفات',
          'اختر صورة أو فيديو آخر ثم حاول مرة أخرى',
        );
      if (!interrupted && !paused) {
        try {
          const publication = await finalizeAfterUpload(projectId, boundary, {
            ownsMutation: true,
          });
          if (publication === 'processing' && mountedRef.current)
            Alert.alert(
              'يُجهز الفيديو',
              'ستتم مراجعة أعمالك بعد اكتمال التجهيز',
            );
        } catch (error) {
          if (isPortfolioAccountChangedError(error)) throw error;
          await reconcileProject(projectId, boundary, generation).catch(
            () => undefined,
          );
          assertAccountSessionBoundary(boundary);
          if (mountedRef.current)
            Alert.alert(
              'اكتمل رفع الملفات',
              'تعذّر تجهيز المشروع للمشاركة\nاضغط إتمام المشروع',
            );
        }
      }
    },
    [
      beginUpload,
      endUpload,
      applyUploadedMedia,
      detailGenerationRef,
      finalizeAfterUpload,
      mountedRef,
      ownsSelectedUpload,
      reconcileProject,
      selectedRef,
    ],
  );

  const resumeSelectedUploads = useCallback(async () => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    const flight = beginUploadMutation();
    if (!flight) return;
    const projectId = current.id;
    const generation = detailGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      if (!ownsSelectedUpload(projectId, generation, flight)) return;
      await assertPortfolioUploadAccess(boundary);
      assertAccountSessionBoundary(boundary);
      if (!ownsSelectedUpload(projectId, generation, flight)) return;
      await setPortfolioMediaUploadsPaused(projectId, false, boundary);
      const entries = await listPortfolioMediaUploads(projectId, boundary);
      assertAccountSessionBoundary(boundary);
      await uploadSelectedEntries(
        projectId,
        entries,
        boundary,
        generation,
        flight,
      );
    } catch (error) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        if (showPortfolioUploadGate(error, onSubscriptions, closeSelection))
          return;
        Alert.alert(
          'تعذّر استكمال الرفع',
          learnerErrorMessage(error, 'حاول مرة أخرى'),
        );
      }
    } finally {
      await endUpload();
      finishMutation(flight);
    }
  }, [
    beginUploadMutation,
    captureBoundary,
    closeSelection,
    detailGenerationRef,
    finishMutation,
    mountedRef,
    onSubscriptions,
    ownsSelectedUpload,
    saving,
    selectedRef,
    uploadSelectedEntries,
    endUpload,
  ]);

  const addSelectedMedia = useCallback(async () => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    const flight = beginUploadMutation(false);
    if (!flight) return;
    const projectId = current.id;
    const generation = detailGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      if (!ownsSelectedUpload(projectId, generation, flight)) return;
      await assertPortfolioUploadAccess(boundary);
      assertAccountSessionBoundary(boundary);
      if (!ownsSelectedUpload(projectId, generation, flight)) return;
      if ((await listPortfolioMediaUploads(projectId, boundary)).length) {
        if (mountedRef.current)
          Alert.alert('الرفع غير مكتمل', 'أكمل رفع الملفات أولاً');
        return;
      }
      if (!ownsSelectedUpload(projectId, generation, flight)) return;
      const result = await launchImageLibrary({
        mediaType: 'mixed' as MediaType,
        selectionLimit: Math.max(1, portfolioMediaSlots(current)),
        ...PORTFOLIO_PICKER_IMAGE_OPTIONS,
      });
      assertAccountSessionBoundary(boundary);
      if (!ownsSelectedUpload(projectId, generation, flight)) return;
      if (result.errorCode) {
        showMediaPickerFailure(result.errorCode);
        return;
      }
      const assets = (result.assets || []).filter(asset => asset.uri);
      if (!assets.length) return;
      setSaving(true);
      const staged = await stagePortfolioMediaFiles({
        boundary,
        projectId,
        requestIdAt: () => secureRandomUuid(),
        sources: assets.map(asset => ({
          uri: String(asset.uri),
          type: asset.type,
          fileName: asset.fileName,
          size: asset.fileSize,
        })),
      });
      await uploadSelectedEntries(
        projectId,
        staged,
        boundary,
        generation,
        flight,
      );
    } catch (error: unknown) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        if (showPortfolioUploadGate(error, onSubscriptions, closeSelection))
          return;
        Alert.alert(
          'تعذّر رفع الملف',
          learnerErrorMessage(error, 'حاول مرة أخرى'),
        );
      }
    } finally {
      await endUpload();
      finishMutation(flight);
    }
  }, [
    beginUploadMutation,
    captureBoundary,
    closeSelection,
    detailGenerationRef,
    finishMutation,
    mountedRef,
    onSubscriptions,
    ownsSelectedUpload,
    saving,
    selectedRef,
    uploadSelectedEntries,
    endUpload,
  ]);

  const confirmDeleteSelectedProject = useCallback(() => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    const flight = beginMutation(false);
    if (!flight) return;
    let deleteStarted = false;
    const release = () => {
      if (!deleteStarted) finishMutation(flight);
    };
    Alert.alert(
      'حذف المشروع',
      `سيُحذف ${current.title} من البورتفوليو\nلا يمكن التراجع`,
      [
        {text: 'إلغاء', style: 'cancel', onPress: release},
        {
          text: 'حذف المشروع',
          style: 'destructive',
          onPress: () => {
            if (deleteStarted || mutationFlightRef.current !== flight) return;
            deleteStarted = true;
            setSaving(true);
            void (async () => {
              try {
                const boundary = await captureBoundary();
                await deletePortfolioItem(current.id, boundary);
                await discardPortfolioMediaUploads(current.id, boundary).catch(
                  () => undefined,
                );
                if (!mountedRef.current) return;
                setLibraryProjects(projects =>
                  projects.filter(project => project.id !== current.id),
                );
                if (selectedRef.current?.id === current.id) closeProject();
              } catch (error: unknown) {
                if (
                  !isPortfolioAccountChangedError(error) &&
                  mountedRef.current
                ) {
                  Alert.alert(
                    'تعذّر حذف المشروع',
                    'المشروع ما زال محفوظًا\nحاول مرة أخرى',
                  );
                }
              } finally {
                finishMutation(flight);
              }
            })();
          },
        },
      ],
      {cancelable: true, onDismiss: release},
    );
  }, [
    beginMutation,
    captureBoundary,
    closeProject,
    finishMutation,
    mountedRef,
    saving,
    selectedRef,
    setLibraryProjects,
  ]);

  const removeSelectedMedia = useCallback(
    (media: PortfolioMedia) => {
      const current = selectedRef.current;
      if (!current || saving || mutationFlightRef.current) return;
      const flight = beginMutation();
      if (!flight) return;
      let deleteStarted = false;
      const release = () => {
        if (!deleteStarted) finishMutation(flight);
      };
      Alert.alert(
        'حذف الملف',
        'سيُحذف من المشروع',
        [
          {text: 'إلغاء', style: 'cancel', onPress: release},
          {
            text: 'حذف',
            style: 'destructive',
            onPress: () => {
              if (deleteStarted || mutationFlightRef.current !== flight) return;
              deleteStarted = true;
              const generation = detailGenerationRef.current;
              void captureBoundary()
                .then(boundary =>
                  deletePortfolioMedia(current.id, media.id, boundary),
                )
                .then(() => {
                  if (!mountedRef.current) return;
                  removeMediaLocally(current.id, media.id, generation);
                })
                .catch(error => {
                  if (
                    !isPortfolioAccountChangedError(error) &&
                    mountedRef.current
                  ) {
                    Alert.alert(
                      'تعذّر حذف الملف',
                      learnerErrorMessage(error, 'حاول مرة أخرى'),
                    );
                  }
                })
                .finally(() => finishMutation(flight));
            },
          },
        ],
        {cancelable: true, onDismiss: release},
      );
    },
    [
      beginMutation,
      captureBoundary,
      detailGenerationRef,
      finishMutation,
      mountedRef,
      removeMediaLocally,
      saving,
      selectedRef,
    ],
  );

  return {
    applyUploadedMedia,
    addSelectedMedia,
    addEditTool,
    beginEdit,
    canMoveSelectedDown: Boolean(
      selectedPlacement &&
        selectedPlacement.index < selectedPlacement.group.length - 1,
    ),
    canMoveSelectedUp: Boolean(selectedPlacement && selectedPlacement.index > 0),
    cancelEditing,
    chooseSelectedCover,
    closeProject,
    confirmDeleteSelectedProject,
    detailLoading,
    editExternalUrl,
    editRole,
    editSummary,
    editTitle,
    editToolInput,
    editTools,
    editing,
    finalizeAfterUpload,
    finalizeSelectedProject,
    handleMediaDeliveryError,
    handleMediaDeliverySuccess,
    isMutationActive,
    moveSelectedProject,
    openProject,
    previewMedia,
    reconcileProject,
    refreshOpenProject,
    removeEditTool,
    removeSelectedMedia,
    saveProjectEdits,
    saving,
    mediaUploadProgress,
    canPauseSelectedUpload: uploadSession.canPause,
    pausingSelectedUpload: uploadSession.pausing,
    pauseSelectedUpload: uploadSession.pause,
    preparingSelectedUpload:
      uploadPreparing && !uploadSession.canPause && !uploadSession.pausing,
    pendingUploadsReady,
    pendingUploadsError:
      selected?.id === pendingUploads?.projectId &&
      pendingUploads?.status === 'error',
    hasPendingUploads,
    selectedUploadPaused: hasPendingUploads && pendingUploads?.paused === true,
    retryPendingUploads,
    resumeSelectedUploads,
    selectPreviewMedia,
    selected,
    selectedMediaSlots: selected ? portfolioMediaSlots(selected) : 0,
    selectedAction: selected ? portfolioAction(selected) : null,
    selectedRef,
    setEditExternalUrl,
    setEditRole,
    setEditSummary,
    setEditTitle,
    setEditToolInput,
    settleUploadedProjects,
    toggleSelectedFeatured,
    toggleSelectedVisibility,
  };
};
