import {useCallback, useEffect, useRef} from 'react';

import {useAppActiveState} from '../../../hooks/useAppActiveState';
import {
  isShareablePortfolioItem,
  portfolioNeedsPublicationRecovery,
} from '../portfolioState';
import type {Project} from './portfolioModel';
import {usePortfolioCreateFlow} from './usePortfolioCreateFlow';
import {usePortfolioLibrary} from './usePortfolioLibrary';
import {usePortfolioMediaReplay} from './usePortfolioMediaReplay';
import {usePortfolioOwnerBoundary} from './usePortfolioOwnerBoundary';
import {usePortfolioProjectDetails} from './usePortfolioProjectDetails';

export type GalleryProps = {
  onSharePortfolio?: () => void | Promise<void>;
  onShareablePortfolioChange?: (available: boolean) => void;
};

/**
 * Gallery orchestration only. Library, create draft/upload, selected-project
 * mutations and post-upload publication each own their own state machine.
 */
export const usePortfolioGalleryController = ({
  onSharePortfolio,
  onShareablePortfolioChange,
}: GalleryProps = {}) => {
  const appActive = useAppActiveState();
  const mountedRef = useRef(true);
  const createBusyRef = useRef(false);
  const detailMutationBlockedRef = useRef(false);
  const previousAppActiveRef = useRef(appActive);
  const publicationRecoveryAttemptsRef = useRef(new Set<string>());
  const {captureBoundary} = usePortfolioOwnerBoundary();
  const isLoadBlocked = useCallback(
    () => createBusyRef.current || detailMutationBlockedRef.current,
    [],
  );
  const isCreateBusy = useCallback(() => createBusyRef.current, []);
  const isDetailBusy = useCallback(() => detailMutationBlockedRef.current, []);
  const setDetailMutationBlocked = useCallback((blocked: boolean) => {
    detailMutationBlockedRef.current = blocked;
  }, []);
  const library = usePortfolioLibrary({
    captureBoundary,
    isLoadBlocked,
    mountedRef,
  });
  const details = usePortfolioProjectDetails({
    cancelLibraryLoad: library.cancelLoad,
    captureBoundary,
    isCreateBusy,
    mountedRef,
    setLibraryProjects: library.setProjects,
    setMutationBlocked: setDetailMutationBlocked,
  });
  const create = usePortfolioCreateFlow({
    appActive,
    busyRef: createBusyRef,
    cancelLibraryLoad: library.cancelLoad,
    captureBoundary,
    finalizeAfterUpload: details.finalizeAfterUpload,
    isDetailBusy,
    mountedRef,
    onMediaUploaded: details.applyUploadedMedia,
    reconcileProject: details.reconcileProject,
    serverSession: library.serverSession,
    setLibraryProjects: library.setProjects,
  });
  const loadProjects = library.loadProjects;
  const openProjectDetails = details.openProject;
  const refreshOpenProject = details.refreshOpenProject;
  const selectedRef = details.selectedRef;
  const settleUploadedProjects = details.settleUploadedProjects;
  const portfolioMutationBusy = create.saving || details.saving;

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    onShareablePortfolioChange?.(
      library.projects.some(isShareablePortfolioItem),
    );
  }, [library.projects, onShareablePortfolioChange]);

  useEffect(() => {
    if (library.serverSession !== true || portfolioMutationBusy) return;
    const recoverableProjectIds = library.projects
      .filter(project => {
        if (!portfolioNeedsPublicationRecovery(project)) return false;
        const key = `${project.id}:${
          project.uploadedMediaCount || 0
        }:${project.media
          .map(media => `${media.id}:${media.status || ''}`)
          .join(',')}`;
        if (publicationRecoveryAttemptsRef.current.has(key)) return false;
        publicationRecoveryAttemptsRef.current.add(key);
        return true;
      })
      .map(project => project.id);
    if (recoverableProjectIds.length === 0) return;
    void settleUploadedProjects(recoverableProjectIds);
  }, [
    library.projects,
    library.serverSession,
    portfolioMutationBusy,
    settleUploadedProjects,
  ]);

  useEffect(() => {
    const becameActive = appActive && !previousAppActiveRef.current;
    previousAppActiveRef.current = appActive;
    if (!becameActive) return;
    publicationRecoveryAttemptsRef.current.clear();
    void loadProjects();
    void refreshOpenProject();
  }, [appActive, loadProjects, refreshOpenProject]);

  const replayPortfolioMedia = usePortfolioMediaReplay({
    appActive,
    loadProjects,
    mountedRef,
    onUploadsSettled: settleUploadedProjects,
    refreshOpenProject,
    selectedRef,
    serverSession: library.serverSession,
  });

  const openProject = useCallback(
    (project: Project) => {
      openProjectDetails(project);
      void replayPortfolioMedia().catch(() => undefined);
    },
    [openProjectDetails, replayPortfolioMedia],
  );

  return {
    ...details,
    ...create,
    appActive,
    loadError: library.loadError,
    loading: library.loading,
    loadProjects,
    handleProjectCoverError: library.handleProjectCoverError,
    handleProjectCoverLoad: library.handleProjectCoverLoad,
    onSharePortfolio,
    openProject,
    projects: library.projects,
    saving: portfolioMutationBusy,
    serverSession: library.serverSession,
  };
};

export type PortfolioGalleryController = ReturnType<
  typeof usePortfolioGalleryController
>;
