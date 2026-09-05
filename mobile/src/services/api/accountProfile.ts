import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {firstBoolean, isApiRecord, payload} from './common';

const profileAvatar = (...values: unknown[]): string | undefined => {
  const value = values.find(candidate => {
    const uri = typeof candidate === 'string' ? candidate.trim() : '';
    return uri && !/\/images\/service\.jpg(?:\?|#|$)/i.test(uri);
  });
  return typeof value === 'string' ? value.trim() : undefined;
};

export type Profile = {
  id: string;
  name: string;
  email: string;
  jobTitle: string;
  portfolioSlug?: string;
  portfolioHeadline: string;
  portfolioUrl?: string;
  avatar?: string;
  watchHistoryEnabled: boolean;
  marketingNotificationsEnabled: boolean;
  videoQualityPreference: string;
  playbackSpeed: number;
  profileRevision: number;
};

type ProfileFallback = Partial<
  Pick<Profile, 'jobTitle' | 'name' | 'portfolioHeadline' | 'profileRevision'>
>;

const profileFromPayload = (
  value: unknown,
  fallback: ProfileFallback = {},
): Profile => {
  if (!isApiRecord(value) || value.id === null || value.id === undefined) {
    throw new Error('PROFILE_CONTRACT_INVALID');
  }
  const quality = String(
    value.video_quality_preference || 'auto',
  ).toLowerCase();
  const speed = Number(value.playback_speed);
  return {
    id: String(value.id),
    name: String(value.name || fallback.name || 'طالب ركن'),
    email: String(value.email || ''),
    jobTitle: String(value.job_title || fallback.jobTitle || ''),
    portfolioSlug: value.portfolio_slug
      ? String(value.portfolio_slug)
      : undefined,
    portfolioHeadline: String(
      value.portfolio_headline || fallback.portfolioHeadline || '',
    ),
    portfolioUrl: value.portfolio_url ? String(value.portfolio_url) : undefined,
    avatar: profileAvatar(value.profile_image, value.image),
    watchHistoryEnabled: firstBoolean(value.watch_history_enabled) ?? true,
    marketingNotificationsEnabled:
      firstBoolean(value.marketing_notifications_enabled) ?? false,
    videoQualityPreference: ['auto', '360p', '480p', '720p', '1080p'].includes(
      quality,
    )
      ? quality
      : 'auto',
    playbackSpeed: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2].includes(speed)
      ? speed
      : 1,
    profileRevision: Math.max(
      fallback.profileRevision || 0,
      Number(value.profile_revision) || 0,
    ),
  };
};

export const getProfile = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<Profile> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const data = payload<unknown>(
    await publicRequest.get('user/profile', {
      params: {include_learning: 0},
    }),
  );
  assertAccountSessionBoundary(boundary);
  return profileFromPayload(data);
};

export const updateProfile = async (
  {
    name,
    jobTitle,
    avatar,
    portfolioSlug,
    portfolioHeadline,
    clientRequestId,
    expectedProfileRevision,
  }: {
    name: string;
    jobTitle?: string;
    avatar?: {uri: string; type?: string; fileName?: string; size?: number};
    portfolioSlug?: string;
    portfolioHeadline?: string;
    clientRequestId: string;
    expectedProfileRevision: number;
  },
  ownerBoundary?: AccountSessionBoundary,
): Promise<Profile> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const form = new FormData();
  form.append('client_request_id', clientRequestId);
  form.append('expected_profile_revision', String(expectedProfileRevision));
  form.append('name', name);
  if (jobTitle !== undefined) form.append('job_title', jobTitle);
  if (portfolioSlug) form.append('portfolio_slug', portfolioSlug);
  if (portfolioHeadline !== undefined) {
    form.append('portfolio_headline', portfolioHeadline);
  }
  if (avatar?.uri) {
    form.append('profile_image', {
      uri: avatar.uri,
      type: avatar.type || 'image/jpeg',
      name: avatar.fileName || `profile-${Date.now()}.jpg`,
    } as unknown as Blob);
  }
  assertAccountSessionBoundary(boundary);
  const data = payload<unknown>(
    await publicRequest.post('user/profile', form, {
      timeout: 30_000,
      headers: {'Idempotency-Key': clientRequestId},
    }),
  );
  assertAccountSessionBoundary(boundary);
  const updated = profileFromPayload(data, {
    jobTitle,
    name,
    portfolioHeadline,
    profileRevision: expectedProfileRevision,
  });
  if (updated.profileRevision <= expectedProfileRevision) {
    throw new Error('PROFILE_REVISION_NOT_ADVANCED');
  }
  return updated;
};

export const updateNotificationStatus = async (
  enabled: boolean,
  ownerBoundary?: AccountSessionBoundary,
): Promise<boolean> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload<unknown>(
    await publicRequest.put('user/profile', {notifications_status: enabled}),
  );
  assertAccountSessionBoundary(boundary);
  if (!isApiRecord(data)) {
    throw new Error('PROFILE_NOTIFICATION_CONTRACT_INVALID');
  }
  const saved = firstBoolean(data.notifications_status);
  if (saved === undefined) {
    throw new Error('PROFILE_NOTIFICATION_CONTRACT_INVALID');
  }
  return saved;
};

export const updatePrivacyPreferences = async (
  input: {
    watchHistoryEnabled?: boolean;
    marketingNotificationsEnabled?: boolean;
  },
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const body: Record<string, boolean> = {};
  if (typeof input.watchHistoryEnabled === 'boolean') {
    body.watch_history_enabled = input.watchHistoryEnabled;
  }
  if (typeof input.marketingNotificationsEnabled === 'boolean') {
    body.marketing_notifications_enabled = input.marketingNotificationsEnabled;
  }
  if (!Object.keys(body).length) return;
  assertAccountSessionBoundary(boundary);
  await publicRequest.put('user/profile', body);
  assertAccountSessionBoundary(boundary);
};

export const updatePlaybackPreferences = async (
  input: {
    videoQualityPreference?: string;
    playbackSpeed?: number;
  },
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const body: Record<string, boolean | number | string> = {};
  if (input.videoQualityPreference) {
    body.video_quality_preference =
      input.videoQualityPreference === 'data_saver'
        ? '360p'
        : input.videoQualityPreference;
  }
  if (typeof input.playbackSpeed === 'number') {
    body.playback_speed = input.playbackSpeed;
  }
  if (!Object.keys(body).length) return;
  assertAccountSessionBoundary(boundary);
  await publicRequest.put('user/profile', body);
  assertAccountSessionBoundary(boundary);
};
