import type {DistributionChannel} from '../constants/distribution';
import {cleanUnicodeText} from '../utils/unicodeText';
import {formatAuthoredDisplayText} from '../constants/arabicFormatting';

export type AppUpdateNotice = {
  contractVersion: number;
  latestVersion: string | null;
  latestVersionCode: number | null;
  latestBuildNumber: number | null;
  minimumSupportedVersionCode: number | null;
  minimumSupportedBuildNumber: number | null;
  message: string;
  releaseNotes: string | null;
  downloadUrl: string | null;
  isBlocking: boolean;
  hasUnsafeDownloadUrl: boolean;
};

export const MOBILE_API_CAPABILITIES = [
  'account_scoped_storage_v1',
  'secure_session_v2',
  'social_oauth_pkce_v1',
  'product_feature_flags_v1',
  'playback_manifest_v2',
  'app_update_policy_v2',
];

const positiveInteger = (value: unknown) => {
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
};

export type VersionCheckPayload =
  | {
      platform: 'android';
      version: number;
      distribution_channel: 'play' | 'direct';
      api_contract_version: 1;
      capabilities: string[];
    }
  | {
      platform: 'ios';
      version: string;
      build_number: number;
      distribution_channel: 'appstore';
      api_contract_version: 1;
      capabilities: string[];
    };

type ParsedUrl = URL & {
  protocol: string;
  hostname: string;
  username: string;
  password: string;
};

const cleanText = (value: unknown, maxLength: number) =>
  typeof value === 'string' && value.trim()
    ? value.trim().replace(/\s+/g, ' ').slice(0, maxLength)
    : null;

const cleanMultilineText = (value: unknown, maxLength: number) => {
  if (typeof value !== 'string') return null;
  const text = cleanUnicodeText(value).slice(0, maxLength);
  return text ? formatAuthoredDisplayText(text) : null;
};

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

export const createVersionCheckPayload = ({
  platform,
  version,
  androidVersionCode,
  iosBuildNumber,
  distributionChannel,
}: {
  platform: string;
  version: unknown;
  androidVersionCode: unknown;
  iosBuildNumber: unknown;
  distributionChannel: DistributionChannel;
}): VersionCheckPayload | null => {
  const compatibility = {
    api_contract_version: 1 as const,
    capabilities: MOBILE_API_CAPABILITIES,
  };
  if (platform === 'android') {
    const versionCode = Number(androidVersionCode);
    return Number.isInteger(versionCode) &&
      versionCode > 0 &&
      (distributionChannel === 'play' || distributionChannel === 'direct')
      ? {
          platform,
          version: versionCode,
          distribution_channel: distributionChannel,
          ...compatibility,
        }
      : null;
  }
  if (platform === 'ios') {
    const versionName = typeof version === 'string' ? version.trim() : '';
    const buildNumber = Number(iosBuildNumber);
    return versionName &&
      Number.isInteger(buildNumber) &&
      buildNumber > 0 &&
      distributionChannel === 'appstore'
      ? {
          platform,
          version: versionName,
          build_number: buildNumber,
          distribution_channel: distributionChannel,
          ...compatibility,
        }
      : null;
  }
  return null;
};

export const trustedUpdateUrl = (
  value: unknown,
  channel: DistributionChannel,
) => {
  if (typeof value !== 'string' || !value.trim()) return null;
  try {
    const url = new URL(value.trim()) as unknown as ParsedUrl;
    if (url.protocol !== 'https:' || url.username || url.password) {
      return null;
    }

    const host = url.hostname.toLowerCase();
    const path = url.pathname;
    const valid =
      channel === 'play'
        ? host === 'play.google.com' &&
          path.replace(/\/+$/, '') === '/store/apps/details' &&
          url.searchParams.get('id') === 'com.rokn'
        : channel === 'appstore'
        ? host === 'apps.apple.com' &&
          /^\/(?:[a-z]{2}\/)?app\/(?:[^/]+\/)?id\d+\/?$/i.test(path)
        : (host === 'rokn.app' || host === 'www.rokn.app') &&
          path.toLowerCase().endsWith('.apk');
    return valid && !url.hash ? url.toString() : null;
  } catch {
    return null;
  }
};

/** Maps the AppVersionController response after URL validation. */
export const parseAppVersionResponse = (
  payload: unknown,
  channel: DistributionChannel,
): AppUpdateNotice | null => {
  const envelope = asRecord(payload) ?? {};
  const nested = asRecord(envelope.data);
  const data = asRecord(nested?.data) ?? nested ?? envelope;
  if (data.update_required !== true) return null;

  const rawDownloadUrl = cleanText(data.download_url, 2048);
  const downloadUrl = trustedUpdateUrl(rawDownloadUrl, channel);
  const requestedForceUpdate = data.is_force_update === true;

  return {
    contractVersion: positiveInteger(data.contract_version) ?? 1,
    latestVersion: cleanText(data.latest_version, 40),
    latestVersionCode: positiveInteger(data.latest_version_code),
    latestBuildNumber: positiveInteger(data.latest_build_number),
    minimumSupportedVersionCode: positiveInteger(
      data.minimum_supported_version_code,
    ),
    minimumSupportedBuildNumber: positiveInteger(
      data.minimum_supported_build_number,
    ),
    message:
      cleanMultilineText(data.update_message, 240) || 'نسخة أحدث من ركن جاهزة',
    releaseNotes: cleanMultilineText(data.release_notes, 600),
    downloadUrl,
    // Invalid download URLs cannot activate a blocking screen. The policy
    // remains visible as a recoverable configuration warning.
    isBlocking: requestedForceUpdate && downloadUrl !== null,
    hasUnsafeDownloadUrl: rawDownloadUrl !== null && downloadUrl === null,
  };
};
