import {publicRequest, type RoknRequestConfig} from '../constants/api';
import {firstBoolean, payload, resourceList} from './api/common';

const SESSION_ID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export type DeviceSession = {
  id: string;
  platform: 'android' | 'ios' | 'web' | 'other';
  device_class: 'phone' | 'tablet' | null;
  app_version?: string | null;
  app_build?: string | null;
  issued_at?: string | null;
  last_used_at?: string | null;
  expires_at?: string | null;
  current: boolean;
};

export const getDeviceSessions = async (): Promise<DeviceSession[]> => {
  const response = await publicRequest.get<{data?: unknown}>('user/sessions');
  const data = payload(response);
  if (!Array.isArray(data)) {
    throw new Error('INVALID_DEVICE_SESSIONS_RESPONSE');
  }
  return resourceList<Record<string, unknown>>(data).flatMap(row => {
    const id = String(row.id ?? '').trim();
    if (!SESSION_ID_PATTERN.test(id)) return [];
    const rawPlatform = String(row.platform || 'other').toLowerCase();
    const platform: DeviceSession['platform'] = [
      'android',
      'ios',
      'web',
    ].includes(rawPlatform)
      ? (rawPlatform as DeviceSession['platform'])
      : 'other';
    const optionalString = (value: unknown) =>
      value === null || value === undefined ? null : String(value);
    const deviceClass = String(row.device_class || '').toLowerCase();
    return [
      {
        id,
        platform,
        device_class:
          deviceClass === 'phone' || deviceClass === 'tablet'
            ? deviceClass
            : null,
        app_version: optionalString(row.app_version),
        app_build: optionalString(row.app_build),
        issued_at: optionalString(row.issued_at),
        last_used_at: optionalString(row.last_used_at),
        expires_at: optionalString(row.expires_at),
        current: firstBoolean(row.current) ?? false,
      },
    ];
  });
};

export const revokeDeviceSession = async (sessionId: string) => {
  const normalizedSessionId = String(sessionId).trim().toLowerCase();
  if (!SESSION_ID_PATTERN.test(normalizedSessionId)) {
    throw new Error('INVALID_DEVICE_SESSION_ID');
  }
  await publicRequest.delete(`user/sessions/${normalizedSessionId}`);
};

export const revokeOtherDeviceSessions = async (): Promise<number> => {
  const response = await publicRequest.delete<{data?: unknown}>(
    'user/sessions',
  );
  const data = payload(response);
  const count = Number(data.revoked_count);
  return Number.isSafeInteger(count) && count >= 0 ? count : 0;
};

export const revokeCurrentDeviceSession = async (
  deviceToken?: string | null,
  options: {
    preservePersistedSessionOnUnauthorized?: boolean;
    session?: {epoch: number; token: string};
  } = {},
) => {
  const sessionToken = options.session?.token.trim();
  const config: RoknRequestConfig = {
    ...(options.preservePersistedSessionOnUnauthorized
      ? {skipPersistedSessionInvalidation: true}
      : {}),
    ...(sessionToken
      ? {
          headers: {Authorization: `Bearer ${sessionToken}`},
          roknSessionBoundAtCall: true,
          roknSessionEpoch: options.session?.epoch,
          roknSessionToken: sessionToken,
        }
      : {}),
  };
  await publicRequest.post(
    'logout',
    deviceToken ? {device_token: deviceToken} : {},
    Object.keys(config).length ? config : undefined,
  );
};
