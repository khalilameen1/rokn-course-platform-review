import type {VideoQuality} from './types';
import {serverNowMs} from '../../utils/serverClock';

export type PlaybackLifecycleEventType =
  | 'start'
  | 'heartbeat'
  | 'pause'
  | 'background'
  | 'stop'
  | 'complete'
  | 'error';

export type PlaybackEndReason =
  | 'user_exit'
  | 'navigation'
  | 'lesson_changed'
  | 'app_closed'
  | 'playback_error'
  | 'source_expired'
  | 'replaced'
  | 'unknown';

export type PlaybackDiagnostics = Record<
  string,
  string | number | boolean
>;

export type PlaybackRuntimeMetrics = {
  effectiveQuality?: VideoQuality;
  effectiveBitrateKbps?: number;
  recoveryCount: number;
  bufferCount?: number;
  bufferDurationMs?: number;
  startupLatencyMs?: number;
  diagnostics?: PlaybackDiagnostics;
};

export type PlaybackPlayerEvent = PlaybackRuntimeMetrics & {
  eventType: Exclude<PlaybackLifecycleEventType, 'heartbeat' | 'complete'>;
  positionSeconds: number;
  durationSeconds?: number;
  endReason?: PlaybackEndReason;
  errorCode?: string;
};

const SAFE_DIAGNOSTIC_KEYS = new Set([
  'stage',
  'reason',
  'source_type',
  'http_status',
  'player_error',
  'buffered_seconds',
  'manifest_age_seconds',
  'retry_stage',
]);

const looksSensitive = (value: string) =>
  /(?:https?:\/\/|www\.|@|bearer\s|token|signature|password|\/playlist\.m3u8)/i.test(
    value,
  );

/**
 * Playback diagnostics use a fixed allowlist that excludes signed media URLs,
 * tokens, email addresses, and arbitrary native error text.
 */
export const sanitizePlaybackDiagnostics = (
  input?: Record<string, unknown> | null,
): PlaybackDiagnostics | undefined => {
  if (!input) return undefined;
  const output: PlaybackDiagnostics = {};
  Object.entries(input).forEach(([key, raw]) => {
    if (!SAFE_DIAGNOSTIC_KEYS.has(key) || Object.keys(output).length >= 12) {
      return;
    }
    if (typeof raw === 'boolean') {
      output[key] = raw;
      return;
    }
    if (typeof raw === 'number' && Number.isFinite(raw)) {
      output[key] = Math.max(-1_000_000, Math.min(1_000_000, raw));
      return;
    }
    if (typeof raw === 'string') {
      const value = raw.trim().slice(0, 80);
      if (value && !looksSensitive(value)) output[key] = value;
    }
  });
  return Object.keys(output).length ? output : undefined;
};

export const sanitizePlaybackErrorCode = (value?: unknown): string | undefined => {
  const raw = String(value || '').trim();
  if (!raw || looksSensitive(raw)) return undefined;
  const code = raw
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64);
  return code || undefined;
};

export const qualityForTrackHeight = (height?: number): VideoQuality | undefined => {
  if (!Number.isFinite(height) || Number(height) <= 0) return undefined;
  if (Number(height) >= 900) return '1080p';
  if (Number(height) >= 600) return '720p';
  if (Number(height) >= 400) return '480p';
  return '360p';
};

const expiryMilliseconds = (expiresAt?: string): number | null => {
  if (!expiresAt) return null;
  const numeric = Number(expiresAt);
  if (Number.isFinite(numeric) && numeric > 0) {
    return numeric < 10_000_000_000 ? numeric * 1000 : numeric;
  }
  const parsed = Date.parse(expiresAt);
  return Number.isFinite(parsed) ? parsed : null;
};

/**
 * Refresh signed playback sources before they expire. The proportional lead
 * avoids refreshing short-lived manifests immediately and caps needless API
 * traffic for long-lived URLs.
 */
export const manifestRefreshDelayMs = (
  expiresAt?: string,
  now = serverNowMs(),
): number | null => {
  const expiry = expiryMilliseconds(expiresAt);
  if (!expiry) return null;
  const remaining = expiry - now;
  if (remaining <= 5_000) return 0;
  const lead = Math.min(90_000, Math.max(15_000, remaining * 0.15));
  return Math.max(0, Math.floor(remaining - lead));
};

export const scheduledManifestRefreshDelayMs = (
  refreshAfter?: string,
  expiresAt?: string,
  now = serverNowMs(),
) => {
  const scheduled = expiryMilliseconds(refreshAfter);
  const scheduledDelay = scheduled
    ? Math.max(0, Math.floor(scheduled - now))
    : null;
  const expirySafeDelay = manifestRefreshDelayMs(expiresAt, now);
  if (scheduledDelay === null) return expirySafeDelay;
  if (expirySafeDelay === null) return scheduledDelay;
  // A malformed or stale refresh_after value must never schedule renewal
  // beyond the point where the signed source itself becomes unsafe to use.
  return Math.min(scheduledDelay, expirySafeDelay);
};
