import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';

const writes = createKeyedAsyncQueue();
type PlaybackField = 'quality' | 'speed';
type PlaybackVersions = Record<PlaybackField, number>;
const versions = new Map<string, PlaybackVersions>();
type PrivacyField = 'watchHistory' | 'marketing' | 'reminder';
type PrivacyVersions = Record<PrivacyField, number>;
const privacyVersions = new Map<string, PrivacyVersions>();

/** Settings and playback share the same native preference write owner. */
export const withAccountPreferenceWrite = <T>(
  boundary: AccountSessionBoundary,
  operation: () => Promise<T>,
): Promise<T> =>
  writes(boundary.scope, async () => {
    assertAccountSessionBoundary(boundary);
    const result = await operation();
    assertAccountSessionBoundary(boundary);
    return result;
  });

export const playbackPreferenceVersions = (
  boundary: AccountSessionBoundary,
): PlaybackVersions => {
  assertAccountSessionBoundary(boundary);
  return {...(versions.get(boundary.scope) ?? {quality: 0, speed: 0})};
};

export const markPlaybackPreferenceMutation = (
  boundary: AccountSessionBoundary,
  field: PlaybackField,
) => {
  const current = playbackPreferenceVersions(boundary);
  current[field] += 1;
  versions.set(boundary.scope, current);
};

export const playbackPreferenceReadIsCurrent = (
  boundary: AccountSessionBoundary,
  readVersions: PlaybackVersions,
  field: PlaybackField,
) => playbackPreferenceVersions(boundary)[field] === readVersions[field];

/** A profile GET cannot overwrite a newer accepted edit after its ACK removed the journal. */
export const privacyPreferenceVersions = (
  boundary: AccountSessionBoundary,
): PrivacyVersions => {
  assertAccountSessionBoundary(boundary);
  return {...(privacyVersions.get(boundary.scope) ?? {
    watchHistory: 0, marketing: 0, reminder: 0,
  })};
};

export const markPrivacyPreferenceMutation = (
  boundary: AccountSessionBoundary,
  field: PrivacyField,
) => {
  const current = privacyPreferenceVersions(boundary);
  current[field] += 1;
  privacyVersions.set(boundary.scope, current);
};

export const privacyPreferenceReadIsCurrent = (
  boundary: AccountSessionBoundary,
  readVersions: PrivacyVersions,
  field: PrivacyField,
) => privacyPreferenceVersions(boundary)[field] === readVersions[field];
