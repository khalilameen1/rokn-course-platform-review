import {useSyncExternalStore} from 'react';
import type {
  ISO639_1,
  SelectedTrack,
  SubtitleStyle,
  TextTracks,
  TextTrackType,
} from 'react-native-video';
import type {CaptionTrack} from '../types';

/**
 * Captions use react-native-video's own text-track support: sideloaded WebVTT
 * (`source.textTracks`) plus subtitle renditions already declared in the HLS
 * master playlist (reported through onLoad/onTextTracks). Rokn only decides
 * whether captions are on and which language is preferred.
 */

/** A text track reported by the native player for the current source. */
export type ReportedTextTrack = {
  index: number;
  title?: string;
  language?: string;
};

const PREFERRED_CAPTION_LANGUAGE = 'ar';
const VTT_MIME_TYPE = 'text/vtt' as TextTrackType;

const asRecord = (value: unknown): Record<string, unknown> =>
  value !== null && typeof value === 'object'
    ? (value as Record<string, unknown>)
    : {};

/**
 * Reads optional `caption_tracks` from a playback manifest:
 * [{url, language, label?, kind?}]. Only HTTPS WebVTT is accepted because it is
 * the one caption format both ExoPlayer and AVPlayer sideload.
 */
export const parseCaptionTracks = (raw: unknown): CaptionTrack[] => {
  const tracks = asRecord(raw).caption_tracks;
  if (!Array.isArray(tracks)) return [];
  const seen = new Set<string>();
  return tracks.flatMap(entry => {
    const track = asRecord(entry);
    const url = typeof track.url === 'string' ? track.url.trim() : '';
    const language =
      typeof track.language === 'string'
        ? track.language.trim().toLowerCase()
        : '';
    const kind = typeof track.kind === 'string' ? track.kind : 'captions';
    if (!/^https:\/\//i.test(url) || !/^[a-z]{2}$/.test(language)) return [];
    if (!['captions', 'subtitles'].includes(kind) || seen.has(language))
      return [];
    seen.add(language);
    const label =
      typeof track.label === 'string' && track.label.trim()
        ? track.label.trim()
        : language === 'ar'
        ? 'العربية'
        : language;
    return [{url, language, label}];
  });
};

export const toSideloadedTextTracks = (
  tracks: CaptionTrack[] | undefined,
): TextTracks | undefined =>
  tracks?.length
    ? tracks.map(track => ({
        title: track.label,
        language: track.language as ISO639_1,
        type: VTT_MIME_TYPE,
        uri: track.url,
      }))
    : undefined;

export const normalizeReportedTextTracks = (
  tracks:
    | ReadonlyArray<{index: number; title?: string; language?: string}>
    | undefined,
): ReportedTextTrack[] =>
  (tracks || [])
    .filter(track => Number.isInteger(track.index) && track.index >= 0)
    .map(track => ({
      index: track.index,
      title: track.title,
      language: track.language?.toLowerCase(),
    }));

const languageOf = (value?: string) => value?.split(/[-_]/)[0];

/**
 * Off is explicit ("disabled"), never the platform default, so a stream with
 * a default subtitle rendition does not show captions the learner turned off.
 */
export const selectCaptionTrack = ({
  enabled,
  reported,
  sideloaded,
}: {
  enabled: boolean;
  reported: ReportedTextTrack[];
  sideloaded?: CaptionTrack[];
}): SelectedTrack | undefined => {
  if (!reported.length && !sideloaded?.length) return undefined;
  if (!enabled) return {type: 'disabled' as SelectedTrack['type']};
  const preferred =
    reported.find(
      track => languageOf(track.language) === PREFERRED_CAPTION_LANGUAGE,
    ) || reported[0];
  if (preferred) {
    return {type: 'index' as SelectedTrack['type'], value: preferred.index};
  }
  const language =
    sideloaded?.find(track => track.language === PREFERRED_CAPTION_LANGUAGE)
      ?.language || sideloaded?.[0]?.language;
  return language
    ? {type: 'language' as SelectedTrack['type'], value: language}
    : undefined;
};

/** Android renders cues inside the video view; lift them above Rokn's footer. */
export const captionStyle = (bottomInset: number): SubtitleStyle => ({
  fontSize: 16,
  paddingBottom: Math.round(bottomInset + 150),
  paddingLeft: 16,
  paddingRight: 16,
});

// One learner choice for the whole session, like YouTube's CC button: it
// follows the learner from reel to reel instead of resetting on each row.
let captionsEnabled = false;
const listeners = new Set<() => void>();
const subscribe = (listener: () => void) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};
const getCaptionsEnabled = () => captionsEnabled;

export const setCaptionsEnabled = (enabled: boolean) => {
  if (captionsEnabled === enabled) return;
  captionsEnabled = enabled;
  listeners.forEach(listener => listener());
};

export const useCaptionsEnabled = () =>
  useSyncExternalStore(subscribe, getCaptionsEnabled, getCaptionsEnabled);
