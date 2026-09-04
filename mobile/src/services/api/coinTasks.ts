import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../constants/api';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  nonNegativeNumber,
  payload,
  resourceList,
  requireNonNegativeNumber,
} from './common';

const COIN_TASK_ACTIONS_KEY = '@rokn/coin-task-actions/v1';
let storageTail: Promise<void> = Promise.resolve();
const isPositiveIntegerId = (value: string) =>
  /^\d+$/.test(value) &&
  Number.isSafeInteger(Number(value)) &&
  Number(value) > 0;

const withStorageLock = <T>(operation: () => Promise<T>) => {
  const result = storageTail.then(operation, operation);
  storageTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const readActionUrls = async (
  boundary?: AccountSessionBoundary,
): Promise<Record<string, string>> => {
  if (boundary) assertAccountSessionBoundary(boundary);
  const key = await accountScopedStorageKey(COIN_TASK_ACTIONS_KEY, boundary);
  const raw = await AsyncStorage.getItem(key);
  if (boundary) assertAccountSessionBoundary(boundary);
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    if (!isApiRecord(parsed)) return {};
    return Object.fromEntries(
      Object.entries(parsed).flatMap(([taskId, value]) =>
        isPositiveIntegerId(taskId) &&
        typeof value === 'string' &&
        value.length <= 4096
          ? [[taskId, value]]
          : [],
      ),
    );
  } catch {
    return {};
  }
};

const rememberActionUrl = (
  taskId: string,
  url: string | undefined,
  boundary: AccountSessionBoundary,
) =>
  withStorageLock(async () => {
    if (!isPositiveIntegerId(taskId) || !url) return;
    assertAccountSessionBoundary(boundary);
    const key = await accountScopedStorageKey(COIN_TASK_ACTIONS_KEY, boundary);
    const urls = await readActionUrls(boundary);
    urls[taskId] = url;
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(key, JSON.stringify(urls));
    assertAccountSessionBoundary(boundary);
  });

const forgetActionUrl = (taskId: string, boundary: AccountSessionBoundary) =>
  withStorageLock(async () => {
    if (!isPositiveIntegerId(taskId)) return;
    assertAccountSessionBoundary(boundary);
    const key = await accountScopedStorageKey(COIN_TASK_ACTIONS_KEY, boundary);
    const urls = await readActionUrls(boundary);
    if (!(taskId in urls)) return;
    delete urls[taskId];
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(key, JSON.stringify(urls));
    assertAccountSessionBoundary(boundary);
  });

type CoinTaskDto = {
  id?: unknown;
  task_state?: unknown;
  action_key?: unknown;
  requires_external_visit?: unknown;
  title_ar?: unknown;
  title_en?: unknown;
  coins_amount?: unknown;
  action_url?: unknown;
};

export type CoinTask = {
  id: string;
  serverId: string;
  title: string;
  description: string;
  reward: number;
  url?: string;
  status: 'available' | 'started' | 'claimed';
  actionKey: string;
  requiresExternalVisit: boolean;
};

type CoinTaskStartResult = {status: string; url?: string};
type CoinTaskClaimResult = {balance: number; amount: number};

const startFlights = new Map<string, Promise<CoinTaskStartResult>>();
const claimFlights = new Map<string, Promise<CoinTaskClaimResult>>();

const taskDescription = (actionKey: string) => {
  if (actionKey === 'link_whatsapp') return 'تواصل مع ركن من واتساب';
  if (actionKey.toLowerCase().includes('coin_guide')) {
    return 'اعرف كيف تستخدم عملاتك';
  }
  return '';
};

const coinTaskTitle = (item: CoinTaskDto) =>
  [item.title_ar, item.title_en]
    .map(value => String(value ?? '').trim())
    .find(Boolean) ?? '';

export const getCoinTasks = async (): Promise<CoinTask[]> => {
  const boundary = await captureAccountSessionBoundary();
  const data = payload<CoinTaskDto[] | {data?: CoinTaskDto[]}>(
    await publicRequest.get('coin-earning-methods'),
  );
  assertAccountSessionBoundary(boundary);
  if (!isResourceListPayload(data)) {
    throw new Error('API_CONTRACT_INVALID_COIN_TASKS');
  }
  const items = resourceList<CoinTaskDto>(data);
  const rememberedUrls = await readActionUrls(boundary);
  const seenTaskIds = new Set<string>();
  if (
    items.some(item => {
      if (!isApiRecord(item)) return true;
      const serverId = String(item.id ?? '').trim();
      const reward = nonNegativeNumber(item.coins_amount);
      const state = String(item.task_state ?? '');
      const actionKey = String(item.action_key ?? '').trim();
      const title = coinTaskTitle(item);
      if (
        !isPositiveIntegerId(serverId) ||
        seenTaskIds.has(serverId) ||
        reward === null ||
        !Number.isSafeInteger(reward) ||
        reward <= 0 ||
        !actionKey ||
        !title ||
        !['available', 'started', 'ready_to_claim', 'claimed'].includes(
          state,
        ) ||
        firstBoolean(item.requires_external_visit) === undefined
      ) {
        return true;
      }
      seenTaskIds.add(serverId);
      return false;
    })
  ) {
    throw new Error('API_CONTRACT_INVALID_COIN_TASKS');
  }

  const tasks = items.map<CoinTask>(item => {
    const serverId = String(item.id ?? '').trim();
    const state = String(item.task_state || 'available');
    const actionKey = String(item.action_key).trim();
    return {
      id: `production-${serverId}`,
      serverId,
      title: coinTaskTitle(item),
      description: taskDescription(actionKey),
      reward: Number(item.coins_amount),
      url: item.action_url ? String(item.action_url) : rememberedUrls[serverId],
      status:
        state === 'claimed'
          ? 'claimed'
          : state === 'started' || state === 'ready_to_claim'
          ? 'started'
          : 'available',
      actionKey,
      requiresExternalVisit:
        firstBoolean(item.requires_external_visit) ?? false,
    };
  });
  const activeIds = new Set(
    tasks.filter(task => task.status !== 'claimed').map(task => task.serverId),
  );
  Object.keys(rememberedUrls).forEach(taskId => {
    if (!activeIds.has(taskId)) {
      void forgetActionUrl(taskId, boundary).catch(() => undefined);
    }
  });
  return tasks;
};

export const startCoinTask = async (
  task: CoinTask,
  ownerBoundary?: AccountSessionBoundary,
): Promise<CoinTaskStartResult> => {
  if (!isPositiveIntegerId(task.serverId)) {
    throw new Error('INVALID_COIN_TASK_ID');
  }
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const flightKey = `${boundary.scope}:${boundary.epoch}:${task.serverId}`;
  const existing = startFlights.get(flightKey);
  if (existing) return existing;
  const flight = (async () => {
    assertAccountSessionBoundary(boundary);
    const data = payload(
      await publicRequest.post(`coin-earning-methods/${task.serverId}/start`),
    );
    assertAccountSessionBoundary(boundary);
    if (!isApiRecord(data)) {
      throw new Error('API_CONTRACT_INVALID_COIN_TASK_START');
    }
    const status = String(data.task_state || '');
    if (!['started', 'ready_to_claim', 'claimed'].includes(status)) {
      throw new Error('API_CONTRACT_INVALID_COIN_TASK_START');
    }
    const actionUrl =
      typeof data.action_url === 'string' ? data.action_url.trim() : '';
    const url = actionUrl || task.url;
    if (
      status !== 'claimed' &&
      (String(data.attempt_id || '').trim() === '' ||
        (task.requiresExternalVisit && !actionUrl))
    ) {
      throw new Error('API_CONTRACT_INVALID_COIN_TASK_START');
    }
    if (status === 'claimed') {
      await forgetActionUrl(task.serverId, boundary).catch(() => undefined);
    } else {
      await rememberActionUrl(task.serverId, url, boundary).catch(
        () => undefined,
      );
    }
    assertAccountSessionBoundary(boundary);
    return {status, url};
  })().finally(() => {
    if (startFlights.get(flightKey) === flight) startFlights.delete(flightKey);
  });
  startFlights.set(flightKey, flight);
  return flight;
};

export const claimCoinTask = async (
  task: CoinTask,
  ownerBoundary?: AccountSessionBoundary,
): Promise<CoinTaskClaimResult> => {
  if (!isPositiveIntegerId(task.serverId)) {
    throw new Error('INVALID_COIN_TASK_ID');
  }
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const flightKey = `${boundary.scope}:${boundary.epoch}:${task.serverId}`;
  const existing = claimFlights.get(flightKey);
  if (existing) return existing;
  const flight = (async () => {
    assertAccountSessionBoundary(boundary);
    const data = payload(
      await publicRequest.post('claim-coins', {
        method_id: Number(task.serverId),
      }),
    );
    assertAccountSessionBoundary(boundary);
    if (!isApiRecord(data) || String(data.task_state || '') !== 'claimed') {
      throw new Error('API_CONTRACT_INVALID_COIN_TASK_CLAIM');
    }
    const result = {
      balance: requireNonNegativeNumber(
        data.new_balance,
        'COIN_TASK_NEW_BALANCE',
      ),
      amount: requireNonNegativeNumber(
        data.earned_amount,
        'COIN_TASK_EARNED_AMOUNT',
      ),
    };
    await forgetActionUrl(task.serverId, boundary).catch(() => undefined);
    assertAccountSessionBoundary(boundary);
    return result;
  })().finally(() => {
    if (claimFlights.get(flightKey) === flight) claimFlights.delete(flightKey);
  });
  claimFlights.set(flightKey, flight);
  return flight;
};
