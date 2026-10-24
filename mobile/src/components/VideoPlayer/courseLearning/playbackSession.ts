import appConfig from '../../../../app.json';
import {Dimensions, Platform} from 'react-native';

import {
  postWatchHistorySample,
  requestPlaybackManifest,
} from '../../../services/api/playback';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {requireProductFeature} from '../../../services/productFeatures';
import {deadlineFromServerTtl} from '../../../utils/serverClock';
import {
  type PlaybackDiagnostics,
  type PlaybackEndReason,
  type PlaybackLifecycleEventType,
} from '../playbackTelemetry';
import type {CaptionTrack, VideoQuality, VideoQualitySources} from '../types';
import {publishCourseRevisionChange} from './playbackRevision';
import {qualityOptions, qualitySources, valueAsString} from './shared';
import {parseCaptionTracks} from '../video/captions';

export type PlaybackEvidenceContext = {
  playbackSessionId?: string;
  effectiveQuality?: VideoQuality;
  effectiveBitrateKbps?: number;
  playbackRate?: number;
  recoveryCount?: number;
  bufferCount?: number;
  bufferDurationMs?: number;
  startupLatencyMs?: number;
  diagnostics?: PlaybackDiagnostics;
};

export type PlaybackClientPreference = {
  dataSaver?: boolean;
  maxBitrateKbps?: number;
  playbackSessionId?: string;
};

export type PlaybackSessionEvent = PlaybackEvidenceContext & {
  lessonId: string;
  playbackSessionId: string;
  eventType: PlaybackLifecycleEventType;
  positionSeconds: number;
  durationSeconds?: number;
  completed?: boolean;
  endReason?: PlaybackEndReason;
  errorCode?: string;
};

export type PlaybackManifest = {
  playbackSessionId: string;
  sourceUrl: string;
  fallbackUrl?: string;
  posterUrl?: string;
  protocol: 'hls' | 'dash' | 'mp4' | 'unknown';
  expiresAt?: string;
  refreshAfter?: string;
  durationSeconds?: number;
  availableQualities: VideoQuality[];
  qualitySources: VideoQualitySources;
  mediaStatus: 'ready' | 'processing' | 'failed' | 'unknown';
  captionTracks?: CaptionTrack[];
};

type SessionState = {
  sequence: number;
  tail: Promise<unknown>;
};

const sessions = new Map<string, SessionState>();
const MAX_SESSION_STATES = 256;
let runtimeGeneration = 0;

const assertOwner = (generation: number, boundary: AccountSessionBoundary) => {
  if (generation !== runtimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(boundary);
};

const sessionKey = (playbackSessionId: string, accountScope: string) =>
  `${accountScope}:${playbackSessionId}`;

const sessionState = (
  playbackSessionId: string,
  accountScope: string,
): SessionState => {
  const key = sessionKey(playbackSessionId, accountScope);
  const existing = sessions.get(key);
  if (existing) return existing;
  const created: SessionState = {
    sequence: 0,
    tail: Promise.resolve(),
  };
  sessions.set(key, created);
  while (sessions.size > MAX_SESSION_STATES) {
    const oldest = sessions.keys().next().value;
    if (typeof oldest !== 'string' || oldest === key) break;
    sessions.delete(oldest);
  }
  return created;
};

export const nextPlaybackSequence = (
  playbackSessionId: string,
  accountScope: string,
) => {
  const state = sessionState(playbackSessionId, accountScope);
  state.sequence += 1;
  return state.sequence;
};

/** Restore the last durable sequence before a queued sample is replayed. */
export const restorePlaybackSequence = (
  playbackSessionId: string,
  accountScope: string,
  sequence: number,
) => {
  if (!Number.isSafeInteger(sequence) || sequence <= 0) return;
  const state = sessionState(playbackSessionId, accountScope);
  state.sequence = Math.max(state.sequence, sequence);
};

/**
 * Serialize actual HTTP deliveries only. The durable evidence owner orders
 * lifecycle events before this boundary; a missing request is never an ACK.
 */
export const postPlaybackSample = (
  playbackSessionId: string | undefined,
  payload: Record<string, unknown>,
  boundary: AccountSessionBoundary,
): Promise<unknown> => {
  const generation = runtimeGeneration;
  const sourceLessonId = String(payload.lesson_id || '').trim() || undefined;
  const send = async () => {
    assertOwner(generation, boundary);
    try {
      const response = await postWatchHistorySample(payload);
      assertOwner(generation, boundary);
      publishCourseRevisionChange(response, sourceLessonId);
      return response;
    } catch (error) {
      const candidate = error as {response?: {data?: {code?: unknown}}};
      if (
        String(candidate.response?.data?.code || '').toLowerCase() !==
        'course_revision_changed'
      ) {
        throw error;
      }
      assertOwner(generation, boundary);
      publishCourseRevisionChange(error, sourceLessonId);
      return candidate.response;
    }
  };

  if (!playbackSessionId) return send();
  const state = sessionState(playbackSessionId, boundary.scope);

  const request = state.tail.catch(() => undefined).then(send);
  // The caller receives the real failure so durable evidence can stay queued.
  // The serialized tail must settle, otherwise the final rejected promise is
  // left unobserved when there is no following sample to consume it.
  const settled = request.catch(() => undefined);
  state.tail = settled;
  return request;
};

const playbackClientCapabilities = (preference: PlaybackClientPreference) => {
  const screen = Dimensions.get('screen');
  const osMajor = String(Platform.Version || '0').split(/[._-]/, 1)[0];
  const maxBitrate = Number(preference.maxBitrateKbps);
  const os =
    Platform.OS === 'android' || Platform.OS === 'ios' ? Platform.OS : 'other';
  const longestSide = Math.max(screen.width, screen.height);
  const maxHeight =
    [1080, 720, 480, 360].find(value => longestSide >= value) || 360;
  return {
    app_version: appConfig.expo.version,
    os,
    os_version: osMajor,
    supports_hls: Platform.OS === 'android' || Platform.OS === 'ios',
    supports_dash: Platform.OS === 'android',
    supports_mp4: true,
    max_height: maxHeight,
    data_saver: preference.dataSaver === true,
    connection: 'unknown',
    ...(Number.isFinite(maxBitrate) && maxBitrate > 0
      ? {
          max_bitrate_kbps: Math.max(
            200,
            Math.min(25_000, Math.round(maxBitrate)),
          ),
        }
      : {}),
    screen_width: Math.max(1, Math.round(screen.width)),
    screen_height: Math.max(1, Math.round(screen.height)),
  };
};

export const openPlaybackSession = async (
  lessonId: string,
  preference: PlaybackClientPreference = {},
): Promise<PlaybackManifest | null> => {
  const numericLessonId = Number(lessonId);
  if (!Number.isFinite(numericLessonId)) return null;

  const generation = runtimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  assertOwner(generation, boundary);
  await requireProductFeature('playback');
  assertOwner(generation, boundary);
  const response = await requestPlaybackManifest(numericLessonId, {
    client: Platform.OS,
    capability_version: 2,
    ...(preference.playbackSessionId
      ? {playback_session_id: preference.playbackSessionId}
      : {}),
    client_capabilities: playbackClientCapabilities(preference),
  });
  assertOwner(generation, boundary);
  const raw = response?.data?.data || response?.data;
  const sourceUrl = valueAsString(raw?.source_url || raw?.url);
  const playbackSessionId = valueAsString(raw?.playback_session_id);
  if (!sourceUrl || !playbackSessionId) return null;

  sessionState(playbackSessionId, boundary.scope);
  const sources = qualitySources(raw);
  const captionTracks = parseCaptionTracks(raw);
  return {
    playbackSessionId,
    sourceUrl,
    fallbackUrl: valueAsString(raw?.fallback_url) || undefined,
    posterUrl: valueAsString(raw?.poster_url) || undefined,
    protocol: ['hls', 'dash', 'mp4'].includes(raw?.protocol)
      ? raw.protocol
      : 'unknown',
    expiresAt:
      deadlineFromServerTtl(raw?.expires_in_seconds) ||
      valueAsString(raw?.expires_at) ||
      undefined,
    refreshAfter:
      deadlineFromServerTtl(raw?.refresh_in_seconds) ||
      valueAsString(raw?.refresh_after) ||
      undefined,
    durationSeconds: Number(raw?.duration_seconds) || undefined,
    availableQualities: qualityOptions(raw, sourceUrl, sources),
    qualitySources: sources,
    mediaStatus: ['ready', 'processing', 'failed'].includes(raw?.media_status)
      ? raw.media_status
      : 'unknown',
    ...(captionTracks.length ? {captionTracks} : {}),
  };
};

export const resetPlaybackSessionRuntime = () => {
  runtimeGeneration += 1;
  sessions.clear();
};
