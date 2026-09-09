import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert, NativeModules, Platform} from 'react-native';

import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  PROJECT_SUBMISSION_MAX_BYTES,
  projectFileMatchesAllowedTypes,
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
  copyProjectSubmissionDraft,
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../../../services/projectSubmissionDraft';
import {cleanUnicodeText, truncateGraphemes} from '../../../utils/unicodeText';
import {resolveProjectJourneyState} from '../courseLearning/projectJourney';
import type {ProjectSubmissionOutcome} from '../courseLearningApi';
import type {CourseProject, ProjectStatus, SelectedProjectFile} from '../types';
import {pickProjectFilesOwned} from './pickers';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import {asRecord} from '../courseLearning/shared';
import {publishCourseRevisionChange} from '../courseLearning/playbackRevision';
import {publicRequest} from '../../../constants/api';

const EMPTY_MIME_TYPES: string[] = [];

type DraftRevision = {response: unknown; currentProjectId: string | null};
const projectDraftRevision = (
  error: unknown,
  sourceProjectId: string,
): DraftRevision | null => {
  const response = asRecord(asRecord(error).response || error);
  const envelope = asRecord(response.data);
  const data = asRecord(envelope.data);
  const positiveId = (value: unknown) =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
  if (
    response.status !== 409 ||
    envelope.code !== 'course_revision_changed' ||
    !positiveId(data.source_project_id) ||
    String(data.source_project_id) !== sourceProjectId ||
    !positiveId(data.course_id) ||
    !positiveId(data.published_revision) ||
    (data.current_project_id !== null &&
      !positiveId(data.current_project_id)) ||
    (data.current_project_id !== null &&
      !positiveId(data.current_section_id)) ||
    String(data.current_project_id) === sourceProjectId
  )
    return null;
  return {
    response,
    currentProjectId:
      data.current_project_id === null ? null : String(data.current_project_id),
  };
};

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
  active = true,
  appIsActive,
  project,
  status,
  submissionAllowed,
  onSubmit,
  onOutcome,
}: {
  active?: boolean;
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
  const revisionVisitRef = useRef({active});
  if (revisionVisitRef.current.active !== active) {
    revisionVisitRef.current = {active};
  }
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
  const [draftRestoreError, setDraftRestoreError] = useState(false);
  const [draftRestoreAttempt, setDraftRestoreAttempt] = useState(0);
  const [sending, setSending] = useState(false);
  const [editingRetry, setEditingRetry] = useState(false);
  const [syncNote, setSyncNote] = useState('');
  const [revision, setRevision] = useState<DraftRevision | null>(null);
  const [revisionUpdating, setRevisionUpdating] = useState(false);
  const [revisionError, setRevisionError] = useState('');

  const normalizedNote = cleanUnicodeText(note);
  const textSubmissionEnabled = project.submissionTextEnabled !== false;
  const fileSubmissionEnabled = project.submissionFilesEnabled !== false;
  const allowedMimeTypesKey = Array.from(
    new Set(
      (project.submissionAllowedMimeTypes ?? EMPTY_MIME_TYPES)
        .map(value =>
          String(value || '')
            .trim()
            .toLowerCase(),
        )
        .filter(Boolean),
    ),
  )
    .sort()
    .join('\n');
  // Course reloads rebuild API arrays. Keep an equivalent submission contract
  // referentially stable so a refresh cannot restart draft hydration and wipe
  // text typed since the last debounced local save.
  const allowedMimeTypes = useMemo(
    () =>
      allowedMimeTypesKey ? allowedMimeTypesKey.split('\n') : EMPTY_MIME_TYPES,
    [allowedMimeTypesKey],
  );
  const fileTypesLabel = allowedFileTypesLabel(allowedMimeTypes);
  const maximumFiles = Math.max(
    1,
    Math.min(5, project.submissionMaxFiles || 3),
  );
  const maximumFileBytes =
    project.submissionMaxFileBytes ?? PROJECT_SUBMISSION_MAX_BYTES;
  const maximumFileSizeLabel = `${formatArabicNumber(
    maximumFileBytes / (1024 * 1024),
    {maximumFractionDigits: 2},
  )} ميجابايت`;
  const incompatibleDraft =
    (!textSubmissionEnabled && Boolean(note.trim())) ||
    selectedFiles.length > maximumFiles ||
    selectedFiles.some(
      file =>
        !fileSubmissionEnabled ||
        !projectFileMatchesAllowedTypes(file, allowedMimeTypes) ||
        Number(file.size || 0) > maximumFileBytes,
    );
  const filePickerDisabled =
    !fileSubmissionEnabled ||
    !submissionAllowed ||
    !draftReady ||
    sending ||
    revisionUpdating ||
    Boolean(revision) ||
    selectedFiles.length >= maximumFiles;
  const submitDisabled =
    !submissionAllowed ||
    !draftReady ||
    sending ||
    revisionUpdating ||
    Boolean(revision) ||
    incompatibleDraft ||
    ((!fileSubmissionEnabled || selectedFiles.length === 0) &&
      (!textSubmissionEnabled || normalizedNote.length < 10));
  const journeyState = resolveProjectJourneyState({
    status,
    draftReady,
    submitting: sending,
    editingRetry,
  });

  draftLifecycle.snapshot = {
    files: selectedFiles,
    note,
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
    setRevision(null);
    setRevisionError('');
    setRevisionUpdating(false);
  }, [project.id]);

  useEffect(
    () => () => {
      identityRef.current.generation += 1;
    },
    [],
  );

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
    const ownerBoundary = draftLifecycle.boundary;
    draftLifecycle.ready = false;
    draftLifecycle.snapshot = {files: [], note: ''};
    setDraftReady(false);
    setDraftSaveError(false);
    setDraftRestoreError(false);
    setSelectedFiles([]);
    setNote('');
    void captureAccountSessionBoundary()
      .then(boundary => {
        if (generation !== draftGenerationRef.current) return null;
        // An explicit retry can renew this account's session, never adopt a
        // different account's draft. Keep the owner even if this retry fails.
        if (ownerBoundary && ownerBoundary.scope !== boundary.scope)
          throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
        assertAccountSessionBoundary(boundary);
        draftLifecycle.boundary = boundary;
        // A status refresh can describe an older upload whose response was
        // lost. Only the matching accepted outcome below may clear this
        // editor's files; the draft loader still enforces its normal TTL.
        return loadProjectSubmissionDraft(project.id, boundary);
      })
      .then(draft => {
        if (generation !== draftGenerationRef.current) return;
        const boundary = draftLifecycle.boundary;
        if (!boundary) return;
        assertAccountSessionBoundary(boundary);
        // New requirements can make old work incompatible, never disposable.
        // Keep it visible until the learner explicitly edits or removes it.
        if (draft) {
          setSelectedFiles(draft.files || []);
          setNote(draft.note);
        }
        draftLifecycle.ready = true;
        setDraftReady(true);
      })
      .catch(() => {
        if (generation === draftGenerationRef.current)
          setDraftRestoreError(true);
      });
    return () => {
      draftGenerationRef.current += 1;
    };
  }, [draftLifecycle, draftRestoreAttempt, project.id]);

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
            if (!projectFileMatchesAllowedTypes(file, allowedMimeTypes)) {
              throw new Error('PROJECT_FILE_TYPE_UNSUPPORTED');
            }
            return {
              ...file,
              size: await validateProjectFile(file, maximumFileBytes),
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
            ? `الحد الأقصى ${maximumFileSizeLabel}\nاختر نسخة أصغر`
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
        // Commit the editor snapshot before resolving an older uncertain
        // attempt: its result may refresh the project and close this screen.
        await saveProjectSubmissionDraft(
          id,
          {
            files,
            note,
            updatedAt: Date.now(),
          },
          boundary,
        );
        assertAccountSessionBoundary(boundary);
        const outcome = await onSubmit(
          fileSubmissionEnabled ? files : [],
          textSubmissionEnabled ? normalizedNote : undefined,
        );
        if (!ownsProject(id, generation)) return;
        onOutcome(outcome);
        if (outcome.accepted && !outcome.preserveDraft) {
          // The consumed editor has a known empty replacement, not an unread
          // draft. Keep it ready for a later asynchronous rejection too;
          // server status and canSubmit still own presentation and submission.
          setEditingRetry(false);
          draftLifecycle.ready = true;
          draftLifecycle.status = outcome.submissionStatus;
          draftLifecycle.snapshot = {files: [], note: ''};
          setDraftReady(true);
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
        try {
          assertAccountSessionBoundary(boundary);
        } catch {
          return;
        }
        const changed = projectDraftRevision(error, id);
        if (changed) {
          setRevision(changed);
          setRevisionError('');
          return;
        }
        if (
          error instanceof Error &&
          error.message === 'PROJECT_SUBMISSION_RATE_LIMITED'
        ) {
          const seconds = Math.max(
            1,
            Number(
              (error as Error & {retryAfterSeconds?: number})
                .retryAfterSeconds || 60,
            ),
          );
          Alert.alert(
            'انتظر قليلًا قبل الإرسال',
            `يمكنك إعادة المحاولة بعد ${formatArabicNumber(
              seconds,
            )} ثانية\nملفاتك وتعديلاتك محفوظة على هذا الجهاز`,
          );
          return;
        }
        if (
          error instanceof Error &&
          error.message === 'PROJECT_SUBMISSION_PREVIOUS_ATTEMPT_PENDING'
        ) {
          Alert.alert(
            'نتحقق من المحاولة السابقة',
            'تعديلاتك الجديدة محفوظة على هذا الجهاز\nانتظر تأكيد حالة المحاولة السابقة ثم حاول مرة أخرى',
          );
          return;
        }
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
      note,
      maximumFileBytes,
      maximumFileSizeLabel,
      onOutcome,
      onSubmit,
      ownsProject,
      textSubmissionEnabled,
    ],
  );

  const submit = useCallback(async () => {
    if (
      !submissionAllowed ||
      revision ||
      incompatibleDraft ||
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
    incompatibleDraft,
    revision,
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
      !draftReady ||
      revision ||
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
        if (!projectFileMatchesAllowedTypes(file, allowedMimeTypes)) {
          throw new Error('PROJECT_FILE_TYPE_UNSUPPORTED');
        }
        const size = await validateProjectFile(file, maximumFileBytes);
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
          ? `الحد الأقصى ${maximumFileSizeLabel}\nاختر نسخة أصغر`
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
    maximumFileBytes,
    maximumFileSizeLabel,
    draftReady,
    ownsProject,
    selectedFiles.length,
    revision,
    submissionAllowed,
  ]);

  const removeSubmissionFile = useCallback(
    (file: SelectedProjectFile) => {
      if (!draftLifecycle.ready || submissionFlightRef.current) return;
      setSelectedFiles(current =>
        current.filter(candidate => candidate.uri !== file.uri),
      );
      void removeLearnerDraftFile(file);
    },
    [draftLifecycle],
  );

  const changeNote = useCallback(
    (value: string) => {
      if (draftLifecycle.ready && !submissionFlightRef.current) {
        setNote(truncateGraphemes(value, 2000));
      }
    },
    [draftLifecycle],
  );

  const restoreGeneration = draftGenerationRef.current;
  const retryDraftRestore = () => {
    if (
      !draftRestoreError ||
      !active ||
      draftLifecycle.ready ||
      identityRef.current.id !== project.id ||
      draftGenerationRef.current !== restoreGeneration
    )
      return;
    setDraftRestoreAttempt(attempt => attempt + 1);
  };

  const reviewUpdatedProject = useCallback(
    async (confirmation?: {
      projectId: string;
      snapshot: string;
    }): Promise<void> => {
      if (!revision || submissionFlightRef.current || pickerFlightRef.current)
        return;
      const visit = revisionVisitRef.current;
      if (!visit.active) return;
      const {id, generation} = identityRef.current;
      const ownsRevisionAction = () =>
        revisionVisitRef.current === visit && ownsProject(id, generation);
      const boundary = draftLifecycle.boundary;
      if (!boundary) return;
      submissionFlightRef.current = true;
      setRevisionUpdating(true);
      setRevisionError('');
      try {
        assertAccountSessionBoundary(boundary);
        // A second publish can retire the destination while this editor or
        // its confirmation is open. Read the original project again without
        // broadcasting a navigation event before its draft is prepared.
        let response: unknown;
        try {
          response = await publicRequest.get(`projects/${id}`, {
            timeout: 12000,
          });
        } catch (error) {
          response = error;
        }
        assertAccountSessionBoundary(boundary);
        if (!ownsRevisionAction()) return;
        const currentRevision = projectDraftRevision(response, id);
        if (!currentRevision) throw new Error('PROJECT_REVISION_UNAVAILABLE');
        setRevision(currentRevision);
        const destinationId = currentRevision.currentProjectId;
        if (!destinationId) {
          await saveProjectSubmissionDraft(
            id,
            {...draftLifecycle.snapshot, updatedAt: Date.now()},
            boundary,
          );
          assertAccountSessionBoundary(boundary);
          if (ownsRevisionAction())
            publishCourseRevisionChange(currentRevision.response);
          return;
        }
        const result = await copyProjectSubmissionDraft(
          id,
          destinationId,
          {...draftLifecycle.snapshot, updatedAt: Date.now()},
          boundary,
          confirmation?.projectId === destinationId
            ? confirmation.snapshot
            : undefined,
        );
        assertAccountSessionBoundary(boundary);
        if (!ownsRevisionAction()) return;
        if (result.kind === 'conflict') {
          Alert.alert(
            'توجد مسودة للمشروع المحدّث',
            'هل تريد استبدالها بهذه المسودة\nستبقى نسختك الأصلية محفوظة',
            [
              {text: 'إلغاء', style: 'cancel'},
              {
                text: 'استبدال المسودة',
                onPress: () => {
                  if (ownsRevisionAction())
                    void reviewUpdatedProject({
                      projectId: destinationId,
                      snapshot: result.destinationSnapshot,
                    });
                },
              },
            ],
          );
          return;
        }
        // The original remains durable; only a prepared destination permits the
        // existing course owner to navigate to the current project requirements.
        publishCourseRevisionChange(currentRevision.response);
      } catch {
        if (!ownsRevisionAction()) return;
        try {
          assertAccountSessionBoundary(boundary);
        } catch {
          return;
        }
        setRevisionError(
          'تعذّر تجهيز المسودة\nاترك الصفحة مفتوحة وحاول مرة أخرى',
        );
      } finally {
        if (ownsProject(id, generation)) {
          submissionFlightRef.current = false;
          setRevisionUpdating(false);
        }
      }
    },
    [draftLifecycle, ownsProject, revision],
  );

  return {
    maximumFileSizeLabel,
    revisionMessage: revision
      ? revisionError ||
        (revision.currentProjectId
          ? 'تغيّرت متطلبات المشروع\nراجع النسخة المحدّثة قبل التسليم'
          : 'لم يعد هذا المشروع ضمن الكورس\nراجع مسودتك هنا قبل فتح الكورس المحدّث')
      : '',
    revisionUpdating,
    canReviewUpdatedProject: Boolean(revision),
    revisionActionLabel: revision?.currentProjectId
      ? 'راجع المشروع المحدّث'
      : 'افتح الكورس المحدّث',
    reviewUpdatedProject: () => reviewUpdatedProject(),
    draftCompatibilityMessage: incompatibleDraft
      ? 'بعض محتوى المسودة لا يناسب المتطلبات الحالية\nعدّل النص أو أزل الملفات غير المناسبة قبل التسليم'
      : '',
    changeNote,
    chooseProjectFile,
    draftSaveError,
    draftRestoreError,
    retryDraftRestore,
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
