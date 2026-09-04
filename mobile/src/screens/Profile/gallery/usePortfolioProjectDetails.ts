import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {launchImageLibrary, type MediaType} from 'react-native-image-picker';

import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  deletePortfolioItem,
  deletePortfolioMedia,
  updatePortfolioItem,
  type PortfolioMedia,
} from '../../../services/roknApi';
import {
  discardPortfolioMediaUploads,
  listPortfolioMediaUploads,
} from '../../../services/portfolioMediaOutbox';
import {
  stagePortfolioMediaFiles,
  uploadPortfolioMediaFiles,
} from '../../../services/portfolioMediaUpload';
import {showMediaPickerFailure} from '../../../services/mediaPickerErrors';
import {learnerErrorMessage} from '../../../utils/errorPayload';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {portfolioAction, portfolioMediaSlots} from '../portfolioState';
import {isPortfolioAccountChangedError, type Project} from './portfolioModel';
import {usePortfolioPublication} from './usePortfolioPublication';
import {usePortfolioProjectSelection} from './usePortfolioProjectSelection';

type Options = {
  cancelLibraryLoad: () => void;
  captureBoundary: () => Promise<AccountSessionBoundary>;
  isCreateBusy: () => boolean;
  mountedRef: React.MutableRefObject<boolean>;
  setLibraryProjects: React.Dispatch<React.SetStateAction<Project[]>>;
  setMutationBlocked: (blocked: boolean) => void;
};

/** Owns the details → edit/media/finalize/delete half of Gallery. */
export const usePortfolioProjectDetails = ({
  cancelLibraryLoad,
  captureBoundary,
  isCreateBusy,
  mountedRef,
  setLibraryProjects,
  setMutationBlocked,
}: Options) => {
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState('');
  const [editSummary, setEditSummary] = useState('');
  const [saving, setSaving] = useState(false);
  const mutationFlightRef = useRef<symbol | null>(null);
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
    handlePreviewPlaybackError,
    openSelection,
    previewMedia,
    reconcileProject,
    refreshOpenProject,
    removeMediaLocally,
    selected,
    selectedRef,
    selectPreviewMedia,
  } = selection;

  useEffect(
    () => () => {
      mutationFlightRef.current = null;
      setMutationBlocked(false);
    },
    [setMutationBlocked],
  );

  const beginMutation = useCallback(
    (showSaving = true) => {
      if (mutationFlightRef.current) return null;
      const flight = Symbol('portfolio-project-mutation');
      mutationFlightRef.current = flight;
      setMutationBlocked(true);
      cancelLibraryLoad();
      cancelDetailLoad();
      if (showSaving) setSaving(true);
      return flight;
    },
    [cancelDetailLoad, cancelLibraryLoad, setMutationBlocked],
  );

  const finishMutation = useCallback(
    (flight: symbol) => {
      if (mutationFlightRef.current !== flight) return;
      mutationFlightRef.current = null;
      setMutationBlocked(false);
      if (mountedRef.current) setSaving(false);
    },
    [mountedRef, setMutationBlocked],
  );

  const {finalizeAfterUpload} = usePortfolioPublication({
    commit: commitRemoteProject,
    mountedRef,
  });

  const settleUploadedProjects = useCallback(
    async (projectIds: string[]) => {
      if (!projectIds.length) return;
      const boundary = await captureBoundary();
      const openProjectId = selectedRef.current?.id;
      const openProjectGeneration = detailGenerationRef.current;
      await Promise.allSettled(
        projectIds.map(async projectId => {
          // A visible server item may already contain the first successful file
          // while later files are still in the durable queue. Finalizing there
          // would publish a partial portfolio and shrink its expected count.
          const pending = await listPortfolioMediaUploads(projectId, boundary);
          if (pending.length) return 'processing' as const;
          return finalizeAfterUpload(
            projectId,
            boundary,
            projectId === openProjectId ? openProjectGeneration : undefined,
          );
        }),
      );
    },
    [captureBoundary, detailGenerationRef, finalizeAfterUpload, selectedRef],
  );

  const openProject = useCallback(
    (project: Project) => {
      setEditing(false);
      openSelection(project);
    },
    [openSelection],
  );

  const closeProject = useCallback(() => {
    setEditing(false);
    closeSelection();
  }, [closeSelection]);

  const beginEdit = useCallback(() => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    setEditTitle(current.title);
    setEditSummary(current.summary);
    setEditing(true);
  }, [saving, selectedRef]);

  const cancelEditing = useCallback(() => {
    if (!saving && !mutationFlightRef.current) setEditing(false);
  }, [saving]);

  const saveProjectEdits = useCallback(async () => {
    const current = selectedRef.current;
    if (!current || !editTitle.trim() || saving || mutationFlightRef.current)
      return;
    const flight = beginMutation();
    if (!flight) return;
    const projectId = current.id;
    const generation = detailGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      const item = await updatePortfolioItem(
        projectId,
        {title: editTitle.trim(), summary: editSummary.trim()},
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
    editSummary,
    editTitle,
    finishMutation,
    mountedRef,
    saving,
    selectedRef,
  ]);

  const finalizeSelectedProject = useCallback(async () => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    const flight = beginMutation();
    if (!flight) return;
    const projectId = current.id;
    const generation = detailGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      const publication = await finalizeAfterUpload(
        projectId,
        boundary,
        generation,
      );
      if (publication === 'published') {
        await discardPortfolioMediaUploads(projectId, boundary).catch(
          () => undefined,
        );
      } else if (mountedRef.current) {
        Alert.alert(
          publication === 'processing' ? 'يُجهز الفيديو' : 'المشروع غير مكتمل',
          publication === 'processing'
            ? 'سيظهر زر المشاركة فور اكتمال التجهيز'
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
    detailGenerationRef,
    finalizeAfterUpload,
    finishMutation,
    mountedRef,
    saving,
    selectedRef,
  ]);

  const addSelectedMedia = useCallback(async () => {
    const current = selectedRef.current;
    if (!current || saving || mutationFlightRef.current) return;
    const flight = beginMutation(false);
    if (!flight) return;
    const projectId = current.id;
    const generation = detailGenerationRef.current;
    try {
      const boundary = await captureBoundary();
      const result = await launchImageLibrary({
        mediaType: 'mixed' as MediaType,
        selectionLimit: Math.max(1, portfolioMediaSlots(current)),
        quality: 0.8,
      });
      assertAccountSessionBoundary(boundary);
      if (
        !mountedRef.current ||
        detailGenerationRef.current !== generation ||
        selectedRef.current?.id !== projectId
      ) {
        return;
      }
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
      const {discardedFiles, interrupted} = await uploadPortfolioMediaFiles({
        boundary,
        entries: staged,
        onUploaded: (uploadedProjectId, uploaded) =>
          applyUploadedMedia(uploadedProjectId, uploaded, generation),
      });
      if (interrupted && mountedRef.current) {
        Alert.alert(
          'لم يكتمل الرفع',
          'احتفظنا بالملفات وسنكملها عند فتح المشروع',
        );
      } else if (discardedFiles && mountedRef.current) {
        Alert.alert(
          'لم تُرفع بعض الملفات',
          'اختر صورة أو فيديو آخر ثم حاول مرة أخرى',
        );
      }
      if (!interrupted) {
        try {
          const publication = await finalizeAfterUpload(
            projectId,
            boundary,
            generation,
          );
          if (publication === 'processing' && mountedRef.current) {
            Alert.alert(
              'يُجهز الفيديو',
              'سيظهر زر المشاركة فور اكتمال التجهيز',
            );
          }
        } catch {
          await reconcileProject(projectId, boundary, generation).catch(
            () => undefined,
          );
          if (mountedRef.current) {
            Alert.alert(
              'اكتمل رفع الملفات',
              'تعذّر تجهيز المشروع للمشاركة\nاضغط إتمام المشروع',
            );
          }
        }
      }
    } catch (error: unknown) {
      if (!isPortfolioAccountChangedError(error) && mountedRef.current) {
        Alert.alert(
          'تعذّر رفع الملف',
          learnerErrorMessage(error, 'حاول مرة أخرى'),
        );
      }
    } finally {
      finishMutation(flight);
    }
  }, [
    applyUploadedMedia,
    beginMutation,
    captureBoundary,
    detailGenerationRef,
    finalizeAfterUpload,
    finishMutation,
    mountedRef,
    reconcileProject,
    saving,
    selectedRef,
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
    beginEdit,
    cancelEditing,
    closeProject,
    confirmDeleteSelectedProject,
    detailLoading,
    editSummary,
    editTitle,
    editing,
    finalizeAfterUpload,
    finalizeSelectedProject,
    handlePreviewPlaybackError,
    isMutationActive: () => Boolean(mutationFlightRef.current),
    openProject,
    previewMedia,
    reconcileProject,
    refreshOpenProject,
    removeSelectedMedia,
    saveProjectEdits,
    saving,
    selectPreviewMedia,
    selected,
    selectedMediaSlots: selected ? portfolioMediaSlots(selected) : 0,
    selectedAction: selected ? portfolioAction(selected) : null,
    selectedRef,
    setEditSummary,
    setEditTitle,
    settleUploadedProjects,
  };
};
