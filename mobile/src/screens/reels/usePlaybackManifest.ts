import {useCallback} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  openPlaybackSession,
  reportPlaybackSessionEvent,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseLearningData,
  CourseReel,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import {PLAYBACK_PREFERENCE_BITRATE_KBPS} from './presentation';
import {
  applyPlaybackManifest,
  disableLessonPlayback,
  playbackFeatureErrorCode,
  playbackManifestHttpStatus,
} from './playbackCourseState';

type PlaybackManifestRefs = {
  activeReel: MutableRefObject<CourseReel | undefined>;
  course: MutableRefObject<CourseLearningData | null>;
  durations: MutableRefObject<Record<string, number>>;
  flights: MutableRefObject<Map<string, Promise<void>>>;
  mounted: MutableRefObject<boolean>;
  ownerGeneration: MutableRefObject<number>;
  positions: MutableRefObject<Record<string, number>>;
  playbackActive: MutableRefObject<boolean>;
  retries: MutableRefObject<Record<string, number>>;
  revisionReloadPending: MutableRefObject<boolean>;
  runtime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  versions: MutableRefObject<Record<string, number>>;
};

const MAX_AUTOMATIC_MANIFEST_RETRIES = 3;

export const usePlaybackManifest = ({
  courseId,
  dataSaver,
  getPlaybackSpeed,
  onCourseRevisionChanged,
  playbackPreferencesReady,
  refs,
  scheduleDelayedAction,
  selectedQuality,
  serverSession,
  setConnectionNote,
  setCourse,
  setManifestRefreshNonce,
}: {
  courseId?: string;
  dataSaver: boolean;
  getPlaybackSpeed: () => number;
  onCourseRevisionChanged: () => void;
  playbackPreferencesReady: boolean;
  refs: PlaybackManifestRefs;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  selectedQuality: VideoQuality;
  serverSession: boolean | null;
  setConnectionNote: Dispatch<SetStateAction<string>>;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
  setManifestRefreshNonce: Dispatch<SetStateAction<number>>;
}) =>
  useCallback(
    (
      reel: CourseReel,
      expectedSessionId?: string,
      reuseExpectedSession = true,
    ) => {
      if (refs.revisionReloadPending.current) {
        onCourseRevisionChanged();
        return;
      }
      if (!serverSession || !playbackPreferencesReady) {
        return;
      }
      const sourceCourseId = courseId;
      const ownerGeneration = refs.ownerGeneration.current;
      const lessonId = reel.lessonId;
      const ownsVisibleLesson = () =>
        refs.playbackActive.current &&
        refs.activeReel.current?.lessonId === lessonId;
      const maxBitrateKbps = dataSaver
        ? 750
        : PLAYBACK_PREFERENCE_BITRATE_KBPS[selectedQuality];
      // A lesson has one manifest owner. Preference changes or player recovery
      // may ask for the same lesson during the existing request; joining that
      // flight is cheaper and prevents the later request from invalidating a
      // perfectly usable signed response from the earlier one.
      const flightKey = lessonId;
      const existingFlight = refs.flights.current.get(flightKey);
      if (existingFlight) return existingFlight;
      const requestVersion = (refs.versions.current[lessonId] || 0) + 1;
      refs.versions.current[lessonId] = requestVersion;
      const scheduleAutomaticRetry = (delayMs: number) => {
        if (!ownsVisibleLesson()) return false;
        const attempts = refs.retries.current[lessonId] || 0;
        if (attempts >= MAX_AUTOMATIC_MANIFEST_RETRIES) return false;
        scheduleDelayedAction(() => {
          if (
            !refs.mounted.current ||
            !ownsVisibleLesson() ||
            refs.ownerGeneration.current !== ownerGeneration ||
            refs.course.current?.id !== sourceCourseId ||
            refs.versions.current[lessonId] !== requestVersion
          ) {
            return;
          }
          const currentAttempts = refs.retries.current[lessonId] || 0;
          if (currentAttempts >= MAX_AUTOMATIC_MANIFEST_RETRIES) return;
          refs.retries.current[lessonId] = currentAttempts + 1;
          setManifestRefreshNonce(value => value + 1);
        }, delayMs);
        return true;
      };
      const flight = openPlaybackSession(lessonId, {
        dataSaver,
        maxBitrateKbps,
        playbackSessionId: reuseExpectedSession ? expectedSessionId : undefined,
      })
        .then(manifest => {
          if (
            !refs.mounted.current ||
            refs.ownerGeneration.current !== ownerGeneration ||
            refs.course.current?.id !== sourceCourseId ||
            refs.versions.current[lessonId] !== requestVersion
          ) {
            return;
          }
          if (!manifest) {
            const scheduled = scheduleAutomaticRetry(10_000);
            if (ownsVisibleLesson()) {
              setConnectionNote(
                scheduled
                  ? 'تعذّر تجديد رابط الفيديو\nسنحاول مرة أخرى'
                  : 'تعذّر تجديد رابط الفيديو\nحاول مرة أخرى',
              );
            }
            return;
          }

          delete refs.retries.current[lessonId];

          if (
            expectedSessionId &&
            expectedSessionId !== manifest.playbackSessionId
          ) {
            const runtime = refs.runtime.current[reel.id];
            void reportPlaybackSessionEvent({
              lessonId,
              playbackSessionId: expectedSessionId,
              eventType: 'stop',
              endReason: 'replaced',
              positionSeconds:
                refs.positions.current[`${sourceCourseId}:${reel.id}`] || 0,
              durationSeconds: refs.durations.current[reel.id],
              playbackRate: getPlaybackSpeed(),
              ...runtime,
            });
          }

          if (ownsVisibleLesson()) {
            setConnectionNote(current =>
              current === 'تعذّر تجديد رابط الفيديو\nسنحاول مرة أخرى' ||
              current === 'تعذّر تجديد رابط الفيديو\nحاول مرة أخرى' ||
              current === 'الفيديو قيد التجهيز\nحاول بعد قليل'
                ? ''
                : current,
            );
          }
          setCourse(previous =>
            applyPlaybackManifest(previous, {
              courseId: sourceCourseId,
              lessonId,
              expectedSessionId,
              manifest,
              revision: requestVersion,
            }),
          );
        })
        .catch((error: unknown) => {
          if (
            !refs.mounted.current ||
            refs.ownerGeneration.current !== ownerGeneration ||
            refs.course.current?.id !== sourceCourseId ||
            refs.versions.current[lessonId] !== requestVersion
          ) {
            return;
          }
          const code = playbackFeatureErrorCode(error).toLowerCase();
          const status = playbackManifestHttpStatus(error);
          if (code === 'course_revision_changed') {
            onCourseRevisionChanged();
            return;
          }
          if (code === 'feature_playback_disabled') {
            if (ownsVisibleLesson()) {
              setConnectionNote('تشغيل الفيديو متوقف مؤقتًا للصيانة');
            }
            return;
          }
          if (code === 'course_purchase_required') {
            if (ownsVisibleLesson()) {
              setConnectionNote('اختر فئة الكورس لتشغيل هذا المقطع');
            }
            setCourse(previous =>
              disableLessonPlayback(
                previous,
                sourceCourseId,
                lessonId,
                'course_purchase_required',
              ),
            );
            return;
          }
          if (code === 'module_project_not_passed') {
            if (ownsVisibleLesson()) {
              setConnectionNote('اجتز مشروع العبور لتشغيل هذا المقطع');
            }
            setCourse(previous =>
              disableLessonPlayback(
                previous,
                sourceCourseId,
                lessonId,
                'module_project_not_passed',
              ),
            );
            return;
          }
          if (status === 403 || status === 404 || code === 'lesson_locked') {
            if (ownsVisibleLesson()) {
              setConnectionNote('هذا المقطع غير متاح الآن');
            }
            setCourse(previous =>
              disableLessonPlayback(
                previous,
                sourceCourseId,
                lessonId,
                code === 'lesson_locked'
                  ? 'previous_section_incomplete'
                  : 'lesson_unavailable',
              ),
            );
            return;
          }
          if (status === 401) {
            if (ownsVisibleLesson()) {
              setConnectionNote('انتهى تسجيل الدخول\nسجّل الدخول ثم أكمل');
            }
            return;
          }
          const scheduled = scheduleAutomaticRetry(
            status === 409 ? 15_000 : 4_000,
          );
          if (ownsVisibleLesson()) {
            setConnectionNote(
              code === 'video_processing'
                ? 'الفيديو قيد التجهيز\nحاول بعد قليل'
                : scheduled
                ? 'تعذّر تجديد رابط الفيديو\nسنحاول مرة أخرى'
                : 'تعذّر تجديد رابط الفيديو\nحاول مرة أخرى',
            );
          }
        })
        .finally(() => {
          // Delete only the flight we own. A new account/course generation
          // may already have registered its own lesson request.
          if (refs.flights.current.get(flightKey) === flight) {
            refs.flights.current.delete(flightKey);
          }
        });
      refs.flights.current.set(flightKey, flight);
      return flight;
    },
    [
      courseId,
      dataSaver,
      getPlaybackSpeed,
      onCourseRevisionChanged,
      playbackPreferencesReady,
      refs,
      scheduleDelayedAction,
      selectedQuality,
      serverSession,
      setConnectionNote,
      setCourse,
      setManifestRefreshNonce,
    ],
  );
