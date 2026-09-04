import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert, NativeModules, Platform} from 'react-native';

import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  PROJECT_SUBMISSION_MAX_LABEL,
  validateProjectFile,
} from '../../../config/projects';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';
import {
  cacheProjectDraftFile,
  clearProjectSubmissionDraft,
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../../../services/projectSubmissionDraft';
import {cleanUnicodeText, truncateGraphemes} from '../../../utils/unicodeText';
import {resolveProjectJourneyState} from '../courseLearning/projectJourney';
import type {ProjectSubmissionOutcome} from '../courseLearningApi';
import type {CourseProject, ProjectStatus, SelectedProjectFile} from '../types';
import {pickProjectFilesOwned} from './pickers';

const EMPTY_MIME_TYPES: string[] = [];

const isAllowedProjectFile = (file: SelectedProjectFile, allowed: string[]) =>
  allowed.includes(file.type.trim().toLowerCase());

const allowedFileTypesLabel = (mimeTypes: string[]) => {
  const labels: string[] = [];
  if (mimeTypes.some(type => type.startsWith('image/'))) labels.push('صور');
  if (mimeTypes.includes('application/pdf')) labels.push('PDF');
  if (
    mimeTypes.includes(
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    )
  ) {
    labels.push('Word');
  }
  if (
    mimeTypes.includes(
      'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    )
  ) {
    labels.push('PowerPoint');
  }
  if (mimeTypes.includes('text/plain')) labels.push('ملف نصي');
  return labels.join(' أو ');
};

export const useProjectSubmission = ({
  appIsActive,
  project,
  status,
  submissionAllowed,
  onSubmit,
  onOutcome,
}: {
  appIsActive: boolean;
  project: CourseProject;
  status: ProjectStatus;
  submissionAllowed: boolean;
  onSubmit: (
    files: SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  onOutcome: (outcome: ProjectSubmissionOutcome) => void;
}) => {
  const identityRef = useRef({id: project.id, generation: 0});
  if (identityRef.current.id !== project.id) {
    identityRef.current = {
      id: project.id,
      generation: identityRef.current.generation + 1,
    };
  }
  const pickerFlightRef = useRef(false);
  const submissionFlightRef = useRef(false);
  const draftGenerationRef = useRef(0);
  const draftLifecycle = useMemo(
    () => ({
      projectId: project.id,
      boundary: null as AccountSessionBoundary | null,
      ready: false,
      status: 'draft' as ProjectStatus,
      snapshot: {files: [] as SelectedProjectFile[], note: ''},
    }),
    [project.id],
  );

  const [selectedFiles, setSelectedFiles] = useState<SelectedProjectFile[]>([]);
  const [note, setNote] = useState('');
  const [draftReady, setDraftReady] = useState(false);
  const [draftSaveError, setDraftSaveError] = useState(false);
  const [sending, setSending] = useState(false);
  const [editingRetry, setEditingRetry] = useState(false);
  const [syncNote, setSyncNote] = useState('');

  const normalizedNote = cleanUnicodeText(note);
  const textSubmissionEnabled = project.submissionTextEnabled !== false;
  const fileSubmissionEnabled = project.submissionFilesEnabled !== false;
  const allowedMimeTypes =
    project.submissionAllowedMimeTypes ?? EMPTY_MIME_TYPES;
  const fileTypesLabel = allowedFileTypesLabel(allowedMimeTypes);
  const maximumFiles = Math.max(
    1,
    Math.min(5, project.submissionMaxFiles || 3),
  );
  const filePickerDisabled =
    !fileSubmissionEnabled ||
    !submissionAllowed ||
    !draftReady ||
    sending ||
    selectedFiles.length >= maximumFiles;
  const submitDisabled =
    !submissionAllowed ||
    !draftReady ||
    sending ||
    ((!fileSubmissionEnabled || selectedFiles.length === 0) &&
      (!textSubmissionEnabled || normalizedNote.length < 10));
  const journeyState = resolveProjectJourneyState({
    status,
    draftReady,
    submitting: sending,
    editingRetry,
  });

  draftLifecycle.snapshot = {
    files: fileSubmissionEnabled ? selectedFiles : [],
    note: textSubmissionEnabled ? note : '',
  };
  draftLifecycle.ready = draftReady;
  draftLifecycle.status = status;

  const ownsProject = useCallback(
    (id: string, generation: number) =>
      identityRef.current.id === id &&
      identityRef.current.generation === generation,
    [],
  );

  useEffect(() => {
    setEditingRetry(false);
    setSyncNote('');
    submissionFlightRef.current = false;
    pickerFlightRef.current = false;
    setSending(false);
  }, [project.id]);

  useEffect(() => {
    if (status !== 'evaluating') setSyncNote('');
  }, [status]);

  useEffect(
    () => () => {
      if (
        !draftLifecycle.ready ||
        !['draft', 'needs_changes'].includes(draftLifecycle.status)
      ) {
        return;
      }
      const snapshot = draftLifecycle.snapshot;
      const boundary = draftLifecycle.boundary;
      if (!boundary) return;
      const persist =
        snapshot.files.length === 0 && snapshot.note.trim() === ''
          ? clearProjectSubmissionDraft(draftLifecycle.projectId, [], boundary)
          : saveProjectSubmissionDraft(
              draftLifecycle.projectId,
              {
                ...snapshot,
                updatedAt: Date.now(),
              },
              boundary,
            );
      void persist.catch(() => undefined);
    },
    [draftLifecycle],
  );

  useEffect(() => {
    const generation = ++draftGenerationRef.current;
    draftLifecycle.ready = false;
    draftLifecycle.boundary = null;
    draftLifecycle.snapshot = {files: [], note: ''};
    setDraftReady(false);
    setDraftSaveError(false);
    setSelectedFiles([]);
    setNote('');
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (generation !== draftGenerationRef.current) return null;
        draftLifecycle.boundary = boundary;
        if (project.status === 'passed' || project.status === 'evaluating') {
          return clearProjectSubmissionDraft(project.id, [], boundary).then(
            () => null,
          );
        }
        return loadProjectSubmissionDraft(project.id, boundary);
      })
      .then(async draft => {
        if (generation !== draftGenerationRef.current || !draft) return;
        const files = fileSubmissionEnabled
          ? (draft.files || []).filter(file =>
              allowedMimeTypes.length === 0
                ? true
                : allowedMimeTypes.includes(file.type.toLowerCase()),
            )
          : [];
        const removedFiles = (draft.files || []).filter(
          file => !files.some(kept => kept.uri === file.uri),
        );
        await Promise.all(removedFiles.map(removeLearnerDraftFile));
        if (generation !== draftGenerationRef.current) return;
        setSelectedFiles(files);
        setNote(textSubmissionEnabled ? draft.note : '');
      })
      .catch(() => {
        if (generation === draftGenerationRef.current) setDraftSaveError(true);
      })
      .finally(() => {
        if (generation === draftGenerationRef.current) {
          draftLifecycle.ready = true;
          setDraftReady(true);
        }
      });
    return () => {
      draftGenerationRef.current += 1;
    };
  }, [
    allowedMimeTypes,
    draftLifecycle,
    fileSubmissionEnabled,
    project.id,
    project.status,
    textSubmissionEnabled,
  ]);

  useEffect(() => {
    if (!['draft', 'needs_changes'].includes(status) || !draftReady) return;
    const {id, generation} = identityRef.current;
    const boundary = draftLifecycle.boundary;
    if (!boundary) return;
    const timer = setTimeout(() => {
      const persist =
        selectedFiles.length === 0 && note.trim() === ''
          ? clearProjectSubmissionDraft(id, [], boundary)
          : saveProjectSubmissionDraft(
              id,
              {
                files: selectedFiles,
                note,
                updatedAt: Date.now(),
              },
              boundary,
            );
      void persist
        .then(() => {
          if (ownsProject(id, generation)) setDraftSaveError(false);
        })
        .catch(() => {
          if (ownsProject(id, generation)) setDraftSaveError(true);
        });
    }, 250);
    return () => clearTimeout(timer);
  }, [draftLifecycle, draftReady, note, ownsProject, selectedFiles, status]);

  useEffect(() => {
    if (
      appIsActive ||
      !['draft', 'needs_changes'].includes(status) ||
      !draftReady
    ) {
      return;
    }
    const {id, generation} = identityRef.current;
    const boundary = draftLifecycle.boundary;
    if (!boundary) return;
    void saveProjectSubmissionDraft(
      id,
      {
        ...draftLifecycle.snapshot,
        updatedAt: Date.now(),
      },
      boundary,
    ).catch(() => {
      if (ownsProject(id, generation)) setDraftSaveError(true);
    });
  }, [appIsActive, draftLifecycle, draftReady, ownsProject, status]);

  const submitSelectedFiles = useCallback(
    async (files: SelectedProjectFile[]) => {
      const {id, generation} = identityRef.current;
      try {
        const validated = await Promise.all(
          files.map(async file => {
            if (!isAllowedProjectFile(file, allowedMimeTypes)) {
              throw new Error('PROJECT_FILE_TYPE_UNSUPPORTED');
            }
            return {
              ...file,
              size: await validateProjectFile(file),
            };
          }),
        );
        if (!ownsProject(id, generation)) return;
        setSelectedFiles(validated);
      } catch (error: unknown) {
        if (!ownsProject(id, generation)) return;
        const code = error instanceof Error ? error.message : '';
        Alert.alert(
          code === 'LEARNER_DRAFT_STORAGE_FULL'
            ? 'اكتملت مساحة الملفات المعلّقة'
            : code === 'PROJECT_FILE_TOO_LARGE'
            ? 'حجم الملف كبير'
            : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
            ? 'صيغة الملف غير مدعومة'
            : 'تعذّر قراءة حجم الملف',
          code === 'LEARNER_DRAFT_STORAGE_FULL'
            ? 'اتصل بالإنترنت لإرسال الملفات المعلّقة\nثم حاول مرة أخرى'
            : code === 'PROJECT_FILE_TOO_LARGE'
            ? `اختر ملفًا أصغر من ${PROJECT_SUBMISSION_MAX_LABEL}`
            : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
            ? `اختر ${PROJECT_SUBMISSION_FORMATS_LABEL}`
            : 'اختر الملف مرة أخرى أو نسخة أصغر',
        );
        return;
      }

      if (
        Platform.OS === 'android' &&
        NativeModules.RoknMediaInspector?.inspect
      ) {
        try {
          for (const file of files.filter(candidate =>
            candidate.type.startsWith('image/'),
          )) {
            const inspection = await NativeModules.RoknMediaInspector.inspect(
              file.uri,
            );
            if (inspection?.isBlank) {
              Alert.alert('الصورة غير واضحة', 'اختر صورة واضحة لعملك');
              return;
            }
          }
        } catch {
          // A failed local hint never blocks a real project submission.
        }
      }

      if (!ownsProject(id, generation)) return;
      const boundary = draftLifecycle.boundary;
      if (!boundary) return;
      assertAccountSessionBoundary(boundary);
      setSyncNote('');
      try {
        const outcome = await onSubmit(
          fileSubmissionEnabled ? files : [],
          textSubmissionEnabled ? normalizedNote : undefined,
        );
        if (!ownsProject(id, generation)) return;
        onOutcome(outcome);
        if (outcome.accepted) {
          setEditingRetry(false);
          draftLifecycle.ready = false;
          draftLifecycle.status = outcome.submissionStatus;
          draftLifecycle.snapshot = {files: [], note: ''};
          setDraftReady(false);
          setSelectedFiles([]);
          setNote('');
          void clearProjectSubmissionDraft(id, files, boundary).catch(
            () => undefined,
          );
        }
        if (!outcome.accepted && outcome.submissionStatus === 'draft') {
          Alert.alert(
            'لم يكتمل الإرسال',
            'محاولتك محفوظة على هذا الجهاز\nحاول مرة أخرى عند استقرار الاتصال',
          );
        }
        if (outcome.submissionStatus === 'evaluating') {
          setSyncNote(
            outcome.accepted
              ? 'استلمنا مشروعك\nسنفتح المقطع التالي بعد المراجعة'
              : 'محاولتك محفوظة\nسنرسلها عند استقرار الاتصال',
          );
        }
      } catch (error: unknown) {
        if (!ownsProject(id, generation)) return;
        const responseStatus = Number(
          error && typeof error === 'object'
            ? (error as {status?: unknown; response?: {status?: unknown}})
                .status ??
                (error as {response?: {status?: unknown}}).response?.status
            : 0,
        );
        setSyncNote('');
        Alert.alert(
          'لم يكتمل التسليم',
          responseStatus === 401
            ? 'سجّل الدخول ثم حاول مرة أخرى'
            : responseStatus === 403
            ? 'لم يعد هذا المشروع متاحًا لحسابك'
            : responseStatus === 409
            ? 'أكمل المحتوى السابق ثم حاول مرة أخرى'
            : responseStatus === 422
            ? 'راجع الملف المختار ثم حاول مرة أخرى'
            : 'حاول تسليم المشروع مرة أخرى',
        );
      }
    },
    [
      draftLifecycle,
      allowedMimeTypes,
      fileSubmissionEnabled,
      normalizedNote,
      onOutcome,
      onSubmit,
      ownsProject,
      textSubmissionEnabled,
    ],
  );

  const submit = useCallback(async () => {
    if (
      !submissionAllowed ||
      !draftReady ||
      submissionFlightRef.current ||
      pickerFlightRef.current
    ) {
      return;
    }
    const hasFiles = fileSubmissionEnabled && selectedFiles.length > 0;
    const hasText = textSubmissionEnabled && normalizedNote.length >= 10;
    if (!hasFiles && !hasText) {
      Alert.alert(
        'أضف محاولتك',
        textSubmissionEnabled && fileSubmissionEnabled
          ? 'اكتب ما نفذته أو أضف ملفًا يوضحه'
          : textSubmissionEnabled
          ? 'اكتب ما نفذته'
          : 'أضف ملفًا من الأنواع المطلوبة',
      );
      return;
    }
    const {id, generation} = identityRef.current;
    submissionFlightRef.current = true;
    setSending(true);
    try {
      await submitSelectedFiles(selectedFiles);
    } finally {
      if (ownsProject(id, generation)) {
        submissionFlightRef.current = false;
        setSending(false);
      }
    }
  }, [
    draftReady,
    fileSubmissionEnabled,
    normalizedNote.length,
    ownsProject,
    selectedFiles,
    submissionAllowed,
    submitSelectedFiles,
    textSubmissionEnabled,
  ]);

  const chooseProjectFile = useCallback(async () => {
    if (
      !submissionAllowed ||
      !fileSubmissionEnabled ||
      pickerFlightRef.current ||
      submissionFlightRef.current
    ) {
      return;
    }
    const {id, generation} = identityRef.current;
    const cached: SelectedProjectFile[] = [];
    pickerFlightRef.current = true;
    try {
      const {files, ownerBoundary} = await pickProjectFilesOwned(
        allowedMimeTypes,
      );
      assertAccountSessionBoundary(ownerBoundary);
      if (
        draftLifecycle.boundary?.scope !== ownerBoundary.scope ||
        draftLifecycle.boundary.epoch !== ownerBoundary.epoch
      ) {
        return;
      }
      if (!files.length || !ownsProject(id, generation)) return;
      const available = files.slice(
        0,
        Math.max(0, maximumFiles - selectedFiles.length),
      );
      for (const file of available) {
        if (!isAllowedProjectFile(file, allowedMimeTypes)) {
          throw new Error('PROJECT_FILE_TYPE_UNSUPPORTED');
        }
        const size = await validateProjectFile(file);
        assertAccountSessionBoundary(ownerBoundary);
        cached.push(
          await cacheProjectDraftFile({...file, size}, ownerBoundary),
        );
        assertAccountSessionBoundary(ownerBoundary);
      }
      if (!ownsProject(id, generation)) {
        await Promise.all(cached.map(removeLearnerDraftFile));
        return;
      }
      setSelectedFiles(current =>
        [...current, ...cached].slice(0, maximumFiles),
      );
    } catch (error: unknown) {
      await Promise.all(cached.map(removeLearnerDraftFile));
      if (!ownsProject(id, generation)) return;
      const code = error instanceof Error ? error.message : '';
      if (code === 'ACCOUNT_CHANGED_DURING_REQUEST') return;
      Alert.alert(
        code === 'PROJECT_FILE_TOO_LARGE'
          ? 'حجم الملف كبير'
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? 'صيغة الملف غير مدعومة'
          : 'تعذّر قراءة الملف',
        code === 'PROJECT_FILE_TOO_LARGE'
          ? `الحد الأقصى ${PROJECT_SUBMISSION_MAX_LABEL}\nاختر نسخة أصغر`
          : code === 'PROJECT_FILE_TYPE_UNSUPPORTED'
          ? `اختر ${PROJECT_SUBMISSION_FORMATS_LABEL}`
          : 'اختر الملف مرة أخرى أو نسخة أصغر',
      );
    } finally {
      if (ownsProject(id, generation)) pickerFlightRef.current = false;
    }
  }, [
    allowedMimeTypes,
    draftLifecycle,
    fileSubmissionEnabled,
    maximumFiles,
    ownsProject,
    selectedFiles.length,
    submissionAllowed,
  ]);

  const removeSubmissionFile = useCallback((file: SelectedProjectFile) => {
    if (submissionFlightRef.current) return;
    setSelectedFiles(current =>
      current.filter(candidate => candidate.uri !== file.uri),
    );
    void removeLearnerDraftFile(file);
  }, []);

  const changeNote = useCallback((value: string) => {
    if (textSubmissionEnabled && !submissionFlightRef.current) {
      setNote(truncateGraphemes(value, 2000));
    }
  }, [textSubmissionEnabled]);

  return {
    changeNote,
    chooseProjectFile,
    draftSaveError,
    editRetry: () => setEditingRetry(true),
    filePickerDisabled,
    fileTypesLabel,
    journeyState,
    fileSubmissionEnabled,
    maximumFiles,
    note,
    removeSubmissionFile,
    selectedFiles,
    sending,
    submissionAllowed,
    submit,
    submitDisabled,
    syncNote,
    textSubmissionEnabled,
  };
};
