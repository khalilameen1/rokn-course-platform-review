import {
  LOGIN_RETURN_TO_PARAMLESS_ROUTES,
  type LoginReturnTo,
  type LoginReturnToParamlessRoute,
} from './types';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {serverNowMs} from '../utils/serverClock';
import {safeRoknRouteId} from './deepLinks';
import {
  getCurrentAccountStorageScope,
  getCurrentGuestJourneyScope,
} from '../constants/helpers';

const PENDING_LOGIN_RETURN_KEY = '@rokn/pending-login-return/v1';
const PENDING_LOGIN_RETURN_TTL_MS = 15 * 60 * 1000;

type RouteSnapshot = {
  name?: unknown;
  params?: object;
};

const cleanId = (value: unknown): string | undefined => {
  return safeRoknRouteId(value) || undefined;
};

const cleanPurchasePlanCode = (value: unknown): string | undefined => {
  const normalized =
    typeof value === 'string' ? value.trim().toLowerCase() : '';
  return /^[a-z0-9][a-z0-9_-]{0,63}$/.test(normalized) ? normalized : undefined;
};

const cleanPurchaseCouponCode = (value: unknown): string | undefined => {
  const normalized = typeof value === 'string' ? value.trim() : '';
  return normalized &&
    normalized.length <= 100 &&
    !Array.from(normalized).some(character => {
      const code = character.charCodeAt(0);
      return code <= 31 || code === 127;
    })
    ? normalized
    : undefined;
};

const purchaseReturnFields = (params: Record<string, unknown> | null) => {
  const purchasePlanCode = cleanPurchasePlanCode(params?.purchasePlanCode);
  const purchaseCouponCode = cleanPurchaseCouponCode(
    params?.purchaseCouponCode,
  );
  return {
    ...(purchasePlanCode ? {purchasePlanCode} : {}),
    ...(purchaseCouponCode ? {purchaseCouponCode} : {}),
  };
};

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

/**
 * Accept only the small route grammar the app itself can create. This is used
 * both for live navigation and for the one durable hand-off that survives an
 * OAuth browser process killing the app.
 */
export const safeLoginReturnTo = (
  value: unknown,
): LoginReturnTo | undefined => {
  const candidate = asRecord(value);
  const name = typeof candidate?.name === 'string' ? candidate.name : '';
  if (name === 'Profile') {
    const params = asRecord(candidate?.params);
    if (candidate?.params === undefined) return {name};
    const tab = params?.tab;
    return tab === 'portfolio' || tab === 'certificates' || tab === 'saved'
      ? {name, params: {tab}}
      : undefined;
  }
  if (
    LOGIN_RETURN_TO_PARAMLESS_ROUTES.includes(
      name as LoginReturnToParamlessRoute,
    )
  ) {
    return candidate?.params === undefined
      ? {name: name as LoginReturnToParamlessRoute}
      : undefined;
  }

  if (name !== 'CourseDetails' && name !== 'Reels') return undefined;
  const params = asRecord(candidate?.params);
  const courseId = cleanId(params?.courseId);
  if (!courseId) return undefined;

  if (name === 'CourseDetails') {
    return {
      name,
      params: {
        courseId,
        openCodeRedemption: params?.openCodeRedemption === true,
        openFullTrackUpgrade: params?.openFullTrackUpgrade === true,
        openPurchase: params?.openPurchase === true,
        ...(params?.openPurchase === true ? purchaseReturnFields(params) : {}),
        resumeAfterPreview: params?.resumeAfterPreview === true,
        resumeReelId: cleanId(params?.resumeReelId),
      },
    };
  }

  const previewCount = Number(params?.previewCount);
  return {
    name,
    params: {
      courseId,
      reelId: cleanId(params?.reelId),
      lessonId: cleanId(params?.lessonId),
      projectId: cleanId(params?.projectId),
      preview: params?.preview === true,
      openCourseChatUpgrade: params?.openCourseChatUpgrade === true,
      previewCount:
        Number.isInteger(previewCount) && previewCount > 0
          ? previewCount
          : undefined,
    },
  };
};

/**
 * Keep only the small, non-sensitive route snapshot required to return a
 * learner to the interrupted course. Never persist arbitrary navigation
 * params supplied by a server or deep link.
 */
export const safeLoginReturnToFromRoute = (
  route?: RouteSnapshot,
): LoginReturnTo | undefined => {
  if (route?.name === 'Profile') {
    const params = asRecord(route.params);
    const tab = params?.tab;
    return tab === 'portfolio' || tab === 'certificates' || tab === 'saved'
      ? {name: 'Profile', params: {tab}}
      : {name: 'Profile'};
  }
  if (
    typeof route?.name === 'string' &&
    LOGIN_RETURN_TO_PARAMLESS_ROUTES.includes(
      route.name as LoginReturnToParamlessRoute,
    )
  ) {
    return {name: route.name as LoginReturnToParamlessRoute};
  }

  const params = (route?.params ?? {}) as Record<string, unknown>;
  const courseId = cleanId(params.courseId);
  if (!courseId) return undefined;

  if (route?.name === 'CourseDetails') {
    return {
      name: 'CourseDetails',
      params: {
        courseId,
        openCodeRedemption: params.openCodeRedemption === true,
        openFullTrackUpgrade: params.openFullTrackUpgrade === true,
        openPurchase: params.openPurchase === true,
        ...(params.openPurchase === true ? purchaseReturnFields(params) : {}),
        resumeAfterPreview: params.resumeAfterPreview === true,
        resumeReelId: cleanId(params.resumeReelId),
      },
    };
  }

  if (route?.name === 'Reels') {
    const previewCount = Number(params.previewCount);
    return {
      name: 'Reels',
      params: {
        courseId,
        reelId: cleanId(params.reelId),
        lessonId: cleanId(params.lessonId),
        projectId: cleanId(params.projectId),
        preview: params.preview === true,
        openCourseChatUpgrade: params.openCourseChatUpgrade === true,
        previewCount:
          Number.isInteger(previewCount) && previewCount > 0
            ? previewCount
            : undefined,
      },
    };
  }

  return undefined;
};

export type LoginReturnMode = 'authenticated' | 'guest';
export type LoginReturnDestination = {name: 'Home'} | LoginReturnTo;

/**
 * Only a secure-session restore may replace the guest identity without an
 * explicit navigation action. A direct account switch must start from that
 * account's fresh navigator instead of inheriting the previous learner's
 * visible route.
 */
export const shouldPreserveVisibleJourneyAcrossSessionChange = (
  previousSessionKey: string,
  nextSessionKey: string,
) => previousSessionKey === 'guest' && nextSessionKey !== 'guest';

/**
 * One route policy for the live Login screen and the durable OAuth return.
 * Guest continuation keeps public destinations, strips purchase-only intent,
 * and turns a player return into the course preview instead of another login
 * loop. Authenticated continuation preserves the exact validated journey.
 */
export const resolveLoginReturnDestination = (
  value: unknown,
  mode: LoginReturnMode,
): LoginReturnDestination => {
  const returnTo = safeLoginReturnTo(value);
  if (!returnTo) return {name: 'Home'};
  if (mode === 'authenticated') return returnTo;

  if (
    returnTo.name === 'EditAccount' ||
    returnTo.name === 'DeviceSessions' ||
    returnTo.name === 'Notifications'
  ) {
    return {name: 'Home'};
  }
  if (returnTo.name === 'CourseDetails') {
    return {
      name: 'CourseDetails',
      params: {
        courseId: returnTo.params.courseId,
        resumeReelId: returnTo.params.resumeReelId,
      },
    };
  }
  if (returnTo.name === 'Reels') {
    return {
      name: 'Reels',
      params: {
        courseId: returnTo.params.courseId,
        reelId: returnTo.params.reelId,
        lessonId: returnTo.params.lessonId,
        projectId: returnTo.params.projectId,
        preview: true,
        previewCount: returnTo.params.previewCount,
      },
    };
  }
  return returnTo;
};

export const loginReturnResetState = (
  value: unknown,
  mode: LoginReturnMode,
) => {
  const destination = resolveLoginReturnDestination(value, mode);
  return destination.name === 'Home'
    ? {index: 0, routes: [{name: 'Home' as const}]}
    : {
        index: 1,
        routes: [{name: 'Home' as const}, destination],
      };
};

export const savePendingLoginReturnTo = async (
  value: unknown,
  reason: 'login' | 'reauthentication' = 'login',
) => {
  const returnTo = safeLoginReturnTo(value);
  if (!returnTo) {
    await AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);
    return;
  }
  const [sourceScope, guestJourneyScope] = await Promise.all([
    getCurrentAccountStorageScope(),
    getCurrentGuestJourneyScope(),
  ]);
  await AsyncStorage.setItem(
    PENDING_LOGIN_RETURN_KEY,
    JSON.stringify({
      version: 2,
      returnTo,
      createdAt: serverNowMs(),
      reason,
      sourceKind: sourceScope.startsWith('guest-') ? 'guest' : 'account',
      sourceScope,
      guestJourneyScope,
    }),
  );
};

export const clearPendingLoginReturnTo = () =>
  AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);

export type PendingLoginReturnClaim = {
  returnTo: LoginReturnTo;
  createdAt: number;
  /** The exact stored envelope. It lets acknowledgement avoid deleting a newer intent. */
  receipt: string;
};

/**
 * Read the interrupted journey without deleting it. Navigation acknowledges
 * only after its reset was dispatched, so a process death between storage and
 * navigation replays one harmless canonical route instead of losing it.
 */
export const claimPendingLoginReturnTo = async (): Promise<
  PendingLoginReturnClaim | undefined
> => {
  const raw = await AsyncStorage.getItem(PENDING_LOGIN_RETURN_KEY);
  if (!raw) return undefined;
  try {
    const envelope = asRecord(JSON.parse(raw));
    const createdAt = Number(envelope?.createdAt);
    const age = serverNowMs() - createdAt;
    if (
      !Number.isFinite(createdAt) ||
      age < -60_000 ||
      age > PENDING_LOGIN_RETURN_TTL_MS
    ) {
      await AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);
      return undefined;
    }
    const returnTo = safeLoginReturnTo(envelope?.returnTo);
    if (!returnTo) {
      await AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);
      return undefined;
    }
    const sourceKind = envelope?.sourceKind;
    const sourceScope =
      typeof envelope?.sourceScope === 'string' ? envelope.sourceScope : '';
    const guestJourneyScope =
      typeof envelope?.guestJourneyScope === 'string'
        ? envelope.guestJourneyScope
        : '';
    if (
      envelope?.version !== 2 ||
      (sourceKind !== 'guest' && sourceKind !== 'account') ||
      !sourceScope
    ) {
      await AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);
      return undefined;
    }
    const currentOwner =
      sourceKind === 'guest'
        ? await getCurrentGuestJourneyScope()
        : await getCurrentAccountStorageScope();
    const expectedOwner =
      sourceKind === 'guest' ? guestJourneyScope : sourceScope;
    if (!expectedOwner || currentOwner !== expectedOwner) {
      await AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);
      return undefined;
    }
    return {returnTo, createdAt, receipt: raw};
  } catch {
    await AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);
    return undefined;
  }
};

export const acknowledgePendingLoginReturnTo = async (receipt: string) => {
  const current = await AsyncStorage.getItem(PENDING_LOGIN_RETURN_KEY);
  if (current === receipt) {
    await AsyncStorage.removeItem(PENDING_LOGIN_RETURN_KEY);
    return true;
  }
  return false;
};
