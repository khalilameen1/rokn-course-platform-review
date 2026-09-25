import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type SetStateAction,
} from 'react';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  clearProjectSubmissionDraft,
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../../../services/projectSubmissionDraft';
import type {ProjectStatus, SelectedProjectFile} from '../types';

type DraftSnapshot = {files: SelectedProjectFile[]; note: string};
const editable = (status: ProjectStatus) =>
  status === 'draft' || status === 'needs_changes';

const persistEditableSnapshot = (
  projectId: string,
  snapshot: DraftSnapshot,
  boundary: AccountSessionBoundary,
) =>
  snapshot.files.length === 0 && snapshot.note.trim() === ''
    ? clearProjectSubmissionDraft(projectId, [], boundary)
    : saveProjectSubmissionDraft(
        projectId,
        {...snapshot, updatedAt: Date.now()},
        boundary,
      );

export const useProjectDraftEditor = ({
  projectId,
  status,
  active,
  appIsActive,
}: {
  projectId: string;
  status: ProjectStatus;
  active: boolean;
  appIsActive: boolean;
}) => {
  const lifecycle = useMemo(
    () => ({
      projectId,
      boundary: null as AccountSessionBoundary | null,
      ready: false,
      status: 'draft' as ProjectStatus,
      snapshot: {files: [], note: ''} as DraftSnapshot,
    }),
    [projectId],
  );
  const currentLifecycle = useRef(lifecycle);
  currentLifecycle.current = lifecycle;
  const mounted = useRef(true);
  const hydrationGeneration = useRef(0);
  const [files, setFiles] = useState<SelectedProjectFile[]>([]);
  const [note, setNote] = useState('');
  const [ready, setReady] = useState(false);
  const [saveError, setSaveError] = useState(false);
  const [restoreError, setRestoreError] = useState(false);
  const [restoreAttempt, setRestoreAttempt] = useState(0);

  lifecycle.snapshot = {files, note};
  lifecycle.ready = ready;
  lifecycle.status = status;

  const isCurrent = useCallback(
    () => mounted.current && currentLifecycle.current === lifecycle,
    [lifecycle],
  );

  // Callers may read the session, but only this owner changes its lifecycle.
  // Its identity is stable until the project changes, not until a status refresh.
  const session = useMemo(
    () => ({
      get boundary() {
        return lifecycle.boundary;
      },
      get ready() {
        return lifecycle.ready;
      },
      get snapshot() {
        return lifecycle.snapshot;
      },
      persist: async (snapshot: DraftSnapshot = lifecycle.snapshot) => {
        const boundary = lifecycle.boundary;
        if (!isCurrent() || !lifecycle.ready || !boundary)
          throw new Error('PROJECT_DRAFT_NOT_READY');
        assertAccountSessionBoundary(boundary);
        await saveProjectSubmissionDraft(
          projectId,
          {...snapshot, updatedAt: Date.now()},
          boundary,
        );
        assertAccountSessionBoundary(boundary);
      },
      consume: (
        nextStatus: ProjectStatus,
        submittedFiles: SelectedProjectFile[],
      ) => {
        const boundary = lifecycle.boundary;
        if (!isCurrent() || !boundary) return;
        assertAccountSessionBoundary(boundary);
        // This is a known empty replacement, not an unread draft. A later
        // asynchronous rejection may allow editing again without rehydration.
        lifecycle.ready = true;
        lifecycle.status = nextStatus;
        lifecycle.snapshot = {files: [], note: ''};
        setReady(true);
        setFiles([]);
        setNote('');
        void clearProjectSubmissionDraft(
          projectId,
          submittedFiles,
          boundary,
        ).catch(() => undefined);
      },
    }),
    [isCurrent, lifecycle, projectId],
  );

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  useEffect(
    () => () => {
      if (!lifecycle.ready || !editable(lifecycle.status)) return;
      const boundary = lifecycle.boundary;
      if (!boundary) return;
      // Capture the departing editor, not the newly rendered project's state.
      void persistEditableSnapshot(
        lifecycle.projectId,
        lifecycle.snapshot,
        boundary,
      ).catch(() => undefined);
    },
    [lifecycle],
  );

  useEffect(() => {
    const generation = ++hydrationGeneration.current;
    const ownerBoundary = lifecycle.boundary;
    lifecycle.ready = false;
    lifecycle.snapshot = {files: [], note: ''};
    setReady(false);
    setSaveError(false);
    setRestoreError(false);
    setFiles([]);
    setNote('');
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (generation !== hydrationGeneration.current) return null;
        // A retry may renew a session for the same account, never adopt another.
        if (ownerBoundary && ownerBoundary.scope !== boundary.scope)
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        assertAccountSessionBoundary(boundary);
        lifecycle.boundary = boundary;
        return loadProjectSubmissionDraft(projectId, boundary);
      })
      .then(draft => {
        if (generation !== hydrationGeneration.current) return;
        const boundary = lifecycle.boundary;
        if (!boundary) return;
        assertAccountSessionBoundary(boundary);
        // Changed requirements may make work incompatible, not disposable.
        if (draft) {
          setFiles(draft.files || []);
          setNote(draft.note);
        }
        lifecycle.ready = true;
        setReady(true);
      })
      .catch(() => {
        if (generation === hydrationGeneration.current) setRestoreError(true);
      });
    return () => {
      hydrationGeneration.current += 1;
    };
  }, [lifecycle, projectId, restoreAttempt]);

  useEffect(() => {
    if (!editable(status) || !ready || !lifecycle.ready) return;
    const boundary = lifecycle.boundary;
    if (!boundary) return;
    const timer = setTimeout(() => {
      const persist = persistEditableSnapshot(
        projectId,
        {files, note},
        boundary,
      );
      void persist
        .then(() => {
          if (isCurrent()) setSaveError(false);
        })
        .catch(() => {
          if (isCurrent()) setSaveError(true);
        });
    }, 250);
    return () => clearTimeout(timer);
  }, [files, isCurrent, lifecycle, note, projectId, ready, status]);

  useEffect(() => {
    if (appIsActive || !editable(status) || !ready || !lifecycle.ready) return;
    const boundary = lifecycle.boundary;
    if (!boundary) return;
    void saveProjectSubmissionDraft(
      projectId,
      {...lifecycle.snapshot, updatedAt: Date.now()},
      boundary,
    ).catch(() => {
      if (isCurrent()) setSaveError(true);
    });
  }, [appIsActive, isCurrent, lifecycle, projectId, ready, status]);

  const restoreGeneration = hydrationGeneration.current;
  const retryRestore = () => {
    if (
      !restoreError ||
      !active ||
      lifecycle.ready ||
      !isCurrent() ||
      hydrationGeneration.current !== restoreGeneration
    )
      return;
    setRestoreAttempt(attempt => attempt + 1);
  };

  const updateFiles = useCallback(
    (value: SetStateAction<SelectedProjectFile[]>) => {
      if (isCurrent() && lifecycle.ready) setFiles(value);
    },
    [isCurrent, lifecycle],
  );
  const updateNote = useCallback(
    (value: string) => {
      if (isCurrent() && lifecycle.ready) setNote(value);
    },
    [isCurrent, lifecycle],
  );

  return {
    session,
    files,
    setFiles: updateFiles,
    note,
    setNote: updateNote,
    ready,
    saveError,
    restoreError,
    retryRestore,
  };
};
