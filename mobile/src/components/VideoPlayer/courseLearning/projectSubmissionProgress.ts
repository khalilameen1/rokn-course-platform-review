import {useEffect, useState} from 'react';
import type {PortfolioUploadProgress} from '../../../services/portfolioUploadProgress';

/**
 * Presentation-only byte progress of the multipart project upload. The
 * durable outbox owns delivery; this map never decides retry or ownership.
 */
export type ProjectUploadProgress = {loaded: number; total: number | null};

const progressByProject = new Map<string, ProjectUploadProgress>();
const listeners = new Set<() => void>();

const notify = () =>
  listeners.forEach(listener => {
    try {
      listener();
    } catch {
      /* Presentation cannot fail delivery. */
    }
  });

export const publishProjectUploadProgress = (
  projectId: string,
  progress: ProjectUploadProgress | null,
) => {
  if (progress) progressByProject.set(projectId, progress);
  else if (!progressByProject.delete(projectId)) return;
  notify();
};

export const readProjectUploadProgress = (projectId: string) =>
  progressByProject.get(projectId) ?? null;

export const useProjectUploadProgress = (projectId: string) => {
  const [progress, setProgress] = useState(() =>
    readProjectUploadProgress(projectId),
  );
  useEffect(() => {
    const update = () => setProgress(readProjectUploadProgress(projectId));
    update();
    listeners.add(update);
    return () => {
      listeners.delete(update);
    };
  }, [projectId]);
  return progress;
};

/** Same transfer presentation as portfolio uploads: percent, then saving. */
export const projectUploadStatus = ({
  loaded,
  total,
}: ProjectUploadProgress): PortfolioUploadProgress => {
  const sent = total !== null && total > 0 && loaded >= total;
  return {
    completed: sent ? 1 : 0,
    total: 1,
    percentage:
      total && total > 0
        ? Math.max(0, Math.min(100, Math.floor((loaded / total) * 100)))
        : null,
    phase: sent ? 'saving' : 'uploading',
  };
};
