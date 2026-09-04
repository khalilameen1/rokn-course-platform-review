import {toArabicDigits} from '../../../constants/arabicFormatting';
import type {VideoQuality, VideoQualitySources} from '../types';
import {isUnsupportedVideoPageUri} from '../videoSourcePolicy';

export type PlaybackFailure =
  | 'offline'
  | 'expired'
  | 'missing'
  | 'reachable'
  | 'server'
  | 'source'
  | 'timeout'
  | 'unsupported';

export type VideoSourceType = 'm3u8' | 'mp4' | 'mpd';

export const VIDEO_BITRATE_BY_QUALITY: Record<VideoQuality, number> = {
  auto: 0,
  '1080p': 5_000_000,
  '720p': 2_800_000,
  '480p': 1_300_000,
  '360p': 750_000,
};

export const normalizeVideoUri = (value?: string) => {
  const uri = String(value || '')
    .trim()
    .replace(/&amp;/gi, '&');
  if (uri.startsWith('//')) {
    return `https:${uri}`;
  }
  // Emulator loopback stays on HTTP; other legacy remote sources use HTTPS.
  if (
    uri.startsWith('http://') &&
    !/^http:\/\/(10\.0\.2\.2|localhost|127\.0\.0\.1)(:\d+)?\//i.test(uri)
  ) {
    return uri.replace(/^http:\/\//i, 'https://');
  }
  return uri;
};

export const sourceTypeForUri = (uri: string): VideoSourceType | undefined => {
  const path = uri.split(/[?#]/, 1)[0].toLowerCase();
  if (path.endsWith('.m3u8')) return 'm3u8';
  if (path.endsWith('.mpd')) return 'mpd';
  if (path.endsWith('.mp4')) return 'mp4';
  return undefined;
};

export const sourceHostForLog = (uri: string) =>
  uri.match(/^https?:\/\/([^/?#]+)/i)?.[1] || 'unknown';

export const selectVideoSource = ({
  effectiveQuality,
  fallbackVideoUrl,
  qualitySources,
  usingFallback,
  videoUrl,
}: {
  effectiveQuality: VideoQuality;
  fallbackVideoUrl?: string;
  qualitySources?: VideoQualitySources;
  usingFallback: boolean;
  videoUrl: string;
}) => {
  const primaryUri = normalizeVideoUri(videoUrl);
  const fallbackUri = normalizeVideoUri(fallbackVideoUrl);
  const selectedVariantUri =
    effectiveQuality === 'auto'
      ? ''
      : normalizeVideoUri(qualitySources?.[effectiveQuality]);
  const preferredUri = selectedVariantUri || primaryUri;
  const hasSupportedFallback =
    Boolean(fallbackUri) &&
    fallbackUri !== preferredUri &&
    !isUnsupportedVideoPageUri(fallbackUri);
  const useSupportedFallback =
    isUnsupportedVideoPageUri(preferredUri) && hasSupportedFallback;
  const isFallbackSource = usingFallback || useSupportedFallback;
  const uri = isFallbackSource ? fallbackUri || preferredUri : preferredUri;
  const sourceType = sourceTypeForUri(uri);

  return {
    adaptiveSource: sourceType === 'm3u8' || sourceType === 'mpd',
    fallbackUri,
    hasSupportedFallback,
    isFallbackSource,
    selectedVariantUri,
    source: sourceType ? {uri, type: sourceType} : {uri},
    sourceType,
    unsupportedSource: isUnsupportedVideoPageUri(uri),
  };
};

export const nextRecoveryQuality = (
  current: VideoQuality,
  available: VideoQuality[],
): VideoQuality | null => {
  const ordered: VideoQuality[] = ['1080p', '720p', '480p', '360p'];
  const supported = ordered.filter(quality => available.includes(quality));
  if (!supported.length) return null;
  if (current === 'auto') {
    return supported.includes('480p')
      ? '480p'
      : supported[supported.length - 1];
  }
  const currentIndex = ordered.indexOf(current);
  return (
    supported.find(quality => ordered.indexOf(quality) > currentIndex) || null
  );
};

export type PlaybackRecoveryStep =
  | {kind: 'defer'}
  | {kind: 'fail'}
  | {kind: 'fallback'}
  | {kind: 'pending'}
  | {kind: 'quality'; quality: VideoQuality}
  | {kind: 'retry'};

export const selectPlaybackRecoveryStep = ({
  adaptiveSource,
  availableQualities,
  effectiveQuality,
  hasSelectedVariant,
  hasSupportedFallback,
  isFallbackSource,
  isVisible,
  recoveryAttempts,
  recoveryPending,
  sameSourceRetryUsed,
  sourceRefreshRequired = false,
}: {
  adaptiveSource: boolean;
  availableQualities: VideoQuality[];
  effectiveQuality: VideoQuality;
  hasSelectedVariant: boolean;
  hasSupportedFallback: boolean;
  isFallbackSource: boolean;
  isVisible: boolean;
  recoveryAttempts: number;
  recoveryPending: boolean;
  sameSourceRetryUsed: boolean;
  sourceRefreshRequired?: boolean;
}): PlaybackRecoveryStep => {
  if (recoveryPending) return {kind: 'pending'};
  if (!isVisible) return {kind: 'defer'};
  if (sourceRefreshRequired && !sameSourceRetryUsed) return {kind: 'retry'};

  const lowerQuality =
    adaptiveSource || hasSelectedVariant
      ? nextRecoveryQuality(effectiveQuality, availableQualities)
      : null;
  if (lowerQuality && recoveryAttempts < 2) {
    return {kind: 'quality', quality: lowerQuality};
  }
  if (!isFallbackSource && hasSupportedFallback) return {kind: 'fallback'};
  if (!sameSourceRetryUsed) return {kind: 'retry'};
  return {kind: 'fail'};
};

export type VideoTimelinePresentation = {
  accessibilityDuration: number;
  accessibilityPosition: number;
  bufferedProgress: number;
  displayedTime: number;
  duration: number;
  progress: number;
  remaining: number;
};

export const selectVideoTimeline = ({
  bufferedTime,
  currentTime,
  duration,
  previewTime,
}: {
  bufferedTime: number;
  currentTime: number;
  duration: number;
  previewTime: number | null;
}): VideoTimelinePresentation => {
  const safeDuration = Number.isFinite(duration) && duration > 0 ? duration : 0;
  const requestedTime = previewTime ?? currentTime;
  const safeTime = Number.isFinite(requestedTime)
    ? Math.max(0, requestedTime)
    : 0;
  const displayedTime = safeDuration
    ? Math.min(safeDuration, safeTime)
    : safeTime;
  const safeBufferedTime = Number.isFinite(bufferedTime)
    ? Math.max(0, bufferedTime)
    : 0;
  const progress = safeDuration ? Math.min(1, displayedTime / safeDuration) : 0;
  const bufferedProgress = safeDuration
    ? Math.min(1, Math.max(progress, safeBufferedTime / safeDuration))
    : 0;
  const accessibilityDuration = Math.max(1, Math.round(safeDuration));

  return {
    accessibilityDuration,
    accessibilityPosition: Math.min(
      accessibilityDuration,
      Math.max(0, Math.round(displayedTime)),
    ),
    bufferedProgress,
    displayedTime,
    duration: safeDuration,
    progress,
    remaining: Math.max(0, safeDuration - displayedTime),
  };
};

export const formatVideoDuration = (seconds: number) => {
  const safeSeconds = Math.max(0, Math.round(seconds));
  const minutes = Math.floor(safeSeconds / 60);
  const remainder = safeSeconds % 60;
  return toArabicDigits(`${minutes}:${remainder.toString().padStart(2, '0')}`);
};

export const selectPlaybackErrorCopy = (
  failureKind: PlaybackFailure,
  unsupportedSource: boolean,
) => {
  if (unsupportedSource) {
    return {
      title: 'الفيديو يحتاج إلى تحديث',
      message: 'صيغة الفيديو غير جاهزة للتشغيل',
    };
  }
  if (failureKind === 'offline') {
    return {
      title: 'أنت غير متصل بالإنترنت',
      message: 'اتصل بالإنترنت ثم حاول مرة أخرى',
    };
  }
  if (failureKind === 'timeout') {
    return {
      title: 'الاتصال بطيء',
      message: 'حاول مرة أخرى أو اختر جودة أقل',
    };
  }
  if (failureKind === 'expired') {
    return {
      title: 'انتهى رابط التشغيل',
      message: 'اضغط للمحاولة من مكانك',
    };
  }
  if (failureKind === 'missing') {
    return {
      title: 'الفيديو غير متاح',
      message: 'أبلغ الدعم من داخل التطبيق',
    };
  }
  if (failureKind === 'server') {
    return {
      title: 'تعذّر الوصول إلى الفيديو',
      message: 'حاول مرة أخرى بعد قليل',
    };
  }
  return {
    title: 'الفيديو غير متاح الآن',
    message: 'حاول مرة أخرى بعد قليل',
  };
};
