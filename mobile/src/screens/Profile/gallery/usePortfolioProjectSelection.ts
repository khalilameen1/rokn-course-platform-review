import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';

import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  getPortfolioItem,
  type PortfolioItem,
  type PortfolioMedia,
} from '../../../services/roknApi';
import {remainingServerMilliseconds} from '../../../utils/serverClock';
import {
  appendPortfolioMediaToProject,
  removePortfolioMediaFromProject,
  toPortfolioProject,
  type Project,
} from './portfolioModel';

type Options = {
  captureBoundary: () => Promise<AccountSessionBoundary>;
  isCreateBusy: () => boolean;
  mountedRef: React.MutableRefObject<boolean>;
  mutationFlightRef: React.MutableRefObject<symbol | null>;
  setLibraryProjects: React.Dispatch<React.SetStateAction<Project[]>>;
};

const responseStatus = (error: unknown) =>
  Number(
    (error as {status?: unknown; response?: {status?: unknown}})?.status ??
      (error as {response?: {status?: unknown}})?.response?.status ??
      0,
  );

/** Owns only the selected remote item, its preview and signed-URL refresh. */
export const usePortfolioProjectSelection = ({
  captureBoundary,
  isCreateBusy,
  mountedRef,
  mutationFlightRef,
  setLibraryProjects,
}: Options) => {
  const [selected, setSelected] = useState<Project | null>(null);
  const [previewMedia, setPreviewMedia] = useState<PortfolioMedia | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const detailGenerationRef = useRef(0);
  const mediaRefreshFlightRef = useRef(false);
  const mediaRefreshAttemptsRef = useRef(new Set<string>());
  const selectedRef = useRef<Project | null>(selected);
  const previewMediaRef = useRef<PortfolioMedia | null>(previewMedia);
  selectedRef.current = selected;
  previewMediaRef.current = previewMedia;

  useEffect(
    () => () => {
      detailGenerationRef.current += 1;
    },
    [],
  );

  const cancelDetailLoad = useCallback(() => {
    detailGenerationRef.current += 1;
    setDetailLoading(false);
  }, []);

  const commitRemoteProject = useCallback(
    (item: PortfolioItem, detailGeneration?: number) => {
      if (!mountedRef.current) return;
      const next = toPortfolioProject(item);
      setLibraryProjects(currentProjects =>
        currentProjects.map(project =>
          project.id === next.id ? next : project,
        ),
      );
      if (
        detailGeneration !== undefined &&
        detailGenerationRef.current === detailGeneration &&
        selectedRef.current?.id === next.id
      ) {
        const previewId = previewMediaRef.current?.id;
        setSelected(next);
        setPreviewMedia(
          next.media.find(media => media.id === previewId && media.uri) ||
            next.media.find(media => media.uri) ||
            null,
        );
      }
    },
    [mountedRef, setLibraryProjects],
  );

  const applyUploadedMedia = useCallback(
    (projectId: string, uploaded: PortfolioMedia, detailGeneration: number) => {
      if (!mountedRef.current) return;
      setLibraryProjects(current =>
        current.map(project =>
          project.id === projectId
            ? appendPortfolioMediaToProject(project, uploaded)
            : project,
        ),
      );
      if (detailGenerationRef.current !== detailGeneration) return;
      setSelected(current =>
        current?.id === projectId
          ? appendPortfolioMediaToProject(current, uploaded)
          : current,
      );
      if (uploaded.uri) setPreviewMedia(current => current || uploaded);
    },
    [mountedRef, setLibraryProjects],
  );

  const reconcileProject = useCallback(
    async (
      projectId: string,
      boundary: AccountSessionBoundary,
      detailGeneration?: number,
    ) => {
      const item = await getPortfolioItem(projectId, boundary);
      assertAccountSessionBoundary(boundary);
      commitRemoteProject(item, detailGeneration);
      return item;
    },
    [commitRemoteProject],
  );

  const refreshOpenProject = useCallback(
    async (force = false) => {
      const current = selectedRef.current;
      if (
        !current ||
        mediaRefreshFlightRef.current ||
        mutationFlightRef.current ||
        isCreateBusy()
      ) {
        return;
      }
      const currentPreview = previewMediaRef.current;
      const remaining = remainingServerMilliseconds(
        currentPreview?.urlExpiresAt,
      );
      if (!force && (remaining === null || remaining > 60_000)) return;

      mediaRefreshFlightRef.current = true;
      const generation = detailGenerationRef.current;
      const projectId = current.id;
      const previewId = currentPreview?.id;
      try {
        const boundary = await captureBoundary();
        const item = await getPortfolioItem(projectId, boundary);
        assertAccountSessionBoundary(boundary);
        if (
          !mountedRef.current ||
          detailGenerationRef.current !== generation ||
          selectedRef.current?.id !== projectId
        ) {
          return;
        }
        const next = toPortfolioProject(item);
        setSelected(next);
        setPreviewMedia(
          next.media.find(media => media.id === previewId && media.uri) ||
            next.media.find(media => media.uri) ||
            null,
        );
        setLibraryProjects(currentProjects =>
          currentProjects.map(project =>
            project.id === next.id ? next : project,
          ),
        );
      } catch {
        // Keep the last visible media; foreground or explicit reopen renews it.
      } finally {
        mediaRefreshFlightRef.current = false;
      }
    },
    [
      captureBoundary,
      isCreateBusy,
      mountedRef,
      mutationFlightRef,
      setLibraryProjects,
    ],
  );

  const openSelection = useCallback(
    (project: Project) => {
      if (mutationFlightRef.current || isCreateBusy()) return;
      mediaRefreshAttemptsRef.current.clear();
      setSelected(project);
      setPreviewMedia(project.media.find(media => media.uri) || null);
      const generation = ++detailGenerationRef.current;
      setDetailLoading(true);
      void captureBoundary()
        .then(boundary => getPortfolioItem(project.id, boundary))
        .then(item => {
          if (
            !mountedRef.current ||
            detailGenerationRef.current !== generation
          ) {
            return;
          }
          const next = toPortfolioProject(item);
          setSelected(next);
          setPreviewMedia(next.media.find(media => media.uri) || null);
          setLibraryProjects(current =>
            current.map(candidate =>
              candidate.id === next.id ? next : candidate,
            ),
          );
        })
        .catch(error => {
          if (
            !mountedRef.current ||
            detailGenerationRef.current !== generation ||
            responseStatus(error) !== 404
          ) {
            return;
          }
          setLibraryProjects(current =>
            current.filter(candidate => candidate.id !== project.id),
          );
          setSelected(null);
          setPreviewMedia(null);
          Alert.alert('المشروع غير متاح', 'حُذف المشروع أو لم يعد متاحًا');
        })
        .finally(() => {
          if (
            mountedRef.current &&
            detailGenerationRef.current === generation
          ) {
            setDetailLoading(false);
          }
        });
    },
    [
      captureBoundary,
      isCreateBusy,
      mountedRef,
      mutationFlightRef,
      setLibraryProjects,
    ],
  );

  const closeSelection = useCallback(() => {
    cancelDetailLoad();
    setSelected(null);
    setPreviewMedia(null);
    mediaRefreshAttemptsRef.current.clear();
  }, [cancelDetailLoad]);

  const removeMediaLocally = useCallback(
    (projectId: string, mediaId: string, detailGeneration: number) => {
      setLibraryProjects(projects =>
        projects.map(project =>
          project.id === projectId
            ? removePortfolioMediaFromProject(project, mediaId)
            : project,
        ),
      );
      if (
        detailGenerationRef.current !== detailGeneration ||
        selectedRef.current?.id !== projectId
      ) {
        return;
      }
      const next = removePortfolioMediaFromProject(
        selectedRef.current,
        mediaId,
      );
      setSelected(next);
      if (previewMediaRef.current?.id === mediaId) {
        setPreviewMedia(next.media.find(candidate => candidate.uri) || null);
      }
    },
    [setLibraryProjects],
  );

  const selectPreviewMedia = useCallback((media: PortfolioMedia) => {
    if (media.uri) setPreviewMedia(media);
  }, []);

  const handlePreviewPlaybackError = useCallback(() => {
    const media = previewMediaRef.current;
    if (!media || mediaRefreshAttemptsRef.current.has(media.id)) return;
    mediaRefreshAttemptsRef.current.add(media.id);
    void refreshOpenProject(true);
  }, [refreshOpenProject]);

  return {
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
  };
};
