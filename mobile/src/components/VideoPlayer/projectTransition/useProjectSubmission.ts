import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert, NativeModules, Platform} from 'react-native';

import {
  PROJECT_SUBMISSION_FORMATS_LABEL,
  PROJECT_SUBMISSION_MAX_BYTES,
  projectFileMatchesAllowedTypes,
  validateProjectFile,
} from '../../../config/projects';
import {assertAccountSessionBoundary} from '../../../constants/helpers';
import {removeLearnerDraftFile} from '../../../services/learnerDraftFiles';
import {cacheProjectDraftFile} from '../../../services/projectSubmissionDraft';
import {cleanUnicodeText, truncateGraphemes} from '../../../utils/unicodeText';
import {resolveProjectJourneyState} from '../courseLearning/projectJourney';
import type {ProjectSubmissionOutcome} from '../courseLearningApi';
import type {CourseProject, ProjectStatus, SelectedProjectFile} from '../types';
import {pickProjectFilesOwned} from './pickers';
import {formatArabicNumber} from '../../../constants/arabicFormatting';
import {publishCourseRevisionChange} from '../courseLearning/playbackRevision';
import {
  isAiConsentRequired,
  requestAiConsent,
} from '../../../services/aiConsent';

import {useProjectDraftEditor} from './useProjectDraftEditor';
import {
  projectDraftRevision,
  resolveLatestProjectDraftRevision,
  prepareProjectDraftDestination,
  type DraftRevision,
  type DraftReplacementConfirmation,
} from './projectDraftRevision';

const EMPTY_MIME_TYPES: string[] = [];

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
  const {
    session: draftSession,
    files: selectedFiles,
    setFiles: setSelectedFiles,
    note,
    setNote,
    ready: draftReady,
    saveError: draftSaveError,
    restoreError: draftRestoreError,
    retryRestore: retryDraftRestore,
  } = useProjectDraftEditor({
    projectId: project.id,
    status,
    active,
    appIsActive,
  });
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
      const boundary = draftSession.boundary;
      if (!boundary) return;
      assertAccountSessionBoundary(boundary);
      setSyncNote('');
      try {
        // Commit the editor snapshot before resolving an older uncertain
        // attempt: its result may refresh the project and close this screen.
        await draftSession.persist({files, note});
        assertAccountSessionBoundary(boundary);
        if (!(await requestAiConsent(boundary))) return;
        assertAccountSessionBoundary(boundary);
        if (!ownsProject(id, generation)) return;
        const outcome = await onSubmit(
          fileSubmissionEnabled ? files : [],
          textSubmissionEnabled ? normalizedNote : undefined,
        );
        if (!ownsProject(id, generation)) return;
        onOutcome(outcome);
        if (outcome.accepted && !outcome.preserveDraft) {
          setEditingRetry(false);
          draftSession.consume(outcome.submissionStatus, files);
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
        if (isAiConsentRequired(error)) {
          Alert.alert(
            'تأكيد استخدام المراجعة',
            'أعد المحاولة لتأكيد اختيارك. مشروعك محفوظ',
          );
          return;
        }
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
      draftSession,
      allowedMimeTypes,
      fileSubmissionEnabled,
      normalizedNote,
      note,
      maximumFileBytes,
      maximumFileSizeLabel,
      onOutcome,
      onSubmit,
      ownsProject,
      setSelectedFiles,
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
    const visit = revisionVisitRef.current;
    if (!visit.active) return;
    const {id, generation} = identityRef.current;
    const ownsPicker = () =>
      revisionVisitRef.current === visit && ownsProject(id, generation);
    const cached: SelectedProjectFile[] = [];
    pickerFlightRef.current = true;
    try {
      const {files, ownerBoundary} = await pickProjectFilesOwned(
        allowedMimeTypes,
        ownsPicker,
      );
      assertAccountSessionBoundary(ownerBoundary);
      if (
        draftSession.boundary?.scope !== ownerBoundary.scope ||
        draftSession.boundary.epoch !== ownerBoundary.epoch
      ) {
        return;
      }
      if (!files.length || !ownsPicker()) return;
      const available = files.slice(
        0,
        Math.max(0, maximumFiles - selectedFiles.length),
      );
      for (const file of available) {
        if (!ownsPicker()) break;
        if (!projectFileMatchesAllowedTypes(file, allowedMimeTypes)) {
          throw new Error('PROJECT_FILE_TYPE_UNSUPPORTED');
        }
        const size = await validateProjectFile(file, maximumFileBytes);
        assertAccountSessionBoundary(ownerBoundary);
        if (!ownsPicker()) break;
        cached.push(
          await cacheProjectDraftFile({...file, size}, ownerBoundary),
        );
        assertAccountSessionBoundary(ownerBoundary);
      }
      if (!ownsPicker()) {
        await Promise.all(cached.map(removeLearnerDraftFile));
        return;
      }
      setSelectedFiles(current =>
        [...current, ...cached].slice(0, maximumFiles),
      );
    } catch (error: unknown) {
      await Promise.all(cached.map(removeLearnerDraftFile));
      if (!ownsPicker()) return;
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
    draftSession,
    fileSubmissionEnabled,
    maximumFiles,
    maximumFileBytes,
    maximumFileSizeLabel,
    draftReady,
    ownsProject,
    selectedFiles.length,
    setSelectedFiles,
    revision,
    submissionAllowed,
  ]);

  const removeSubmissionFile = useCallback(
    (file: SelectedProjectFile) => {
      if (!draftSession.ready || submissionFlightRef.current) return;
      setSelectedFiles(current =>
        current.filter(candidate => candidate.uri !== file.uri),
      );
      void removeLearnerDraftFile(file);
    },
    [draftSession, setSelectedFiles],
  );

  const changeNote = useCallback(
    (value: string) => {
      if (draftSession.ready && !submissionFlightRef.current) {
        setNote(truncateGraphemes(value, 2000));
      }
    },
    [draftSession, setNote],
  );

  const reviewUpdatedProject = useCallback(
    async (confirmation?: DraftReplacementConfirmation): Promise<void> => {
      if (!revision || submissionFlightRef.current || pickerFlightRef.current)
        return;
      const visit = revisionVisitRef.current;
      if (!visit.active) return;
      const {id, generation} = identityRef.current;
      const ownsRevisionAction = () =>
        revisionVisitRef.current === visit && ownsProject(id, generation);
      const boundary = draftSession.boundary;
      if (!boundary) return;
      submissionFlightRef.current = true;
      setRevisionUpdating(true);
      setRevisionError('');
      try {
        const currentRevision = await resolveLatestProjectDraftRevision(
          id,
          boundary,
        );
        if (!ownsRevisionAction()) return;
        setRevision(currentRevision);
        const result = await prepareProjectDraftDestination({
          sourceProjectId: id,
          revision: currentRevision,
          snapshot: draftSession.snapshot,
          boundary,
          confirmation,
        });
        if (!ownsRevisionAction()) return;
        if (result.kind === 'conflict' && currentRevision.currentProjectId) {
          const destinationId = currentRevision.currentProjectId;
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
    [draftSession, ownsProject, revision],
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
