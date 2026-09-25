import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {notifyWalletSettlement} from '../walletSettlement';
import {
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  nonNegativeNumber,
  payload,
  resourceList,
  requireNonNegativeNumber,
} from './common';

const isPositiveIntegerId = (value: string) =>
  /^\d+$/.test(value) &&
  Number.isSafeInteger(Number(value)) &&
  Number(value) > 0;

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
  status: 'available' | 'started' | 'ready_to_claim' | 'claimed';
  actionKey: string;
  requiresExternalVisit: boolean;
};

type CoinTaskStartResult = {
  status: 'started' | 'ready_to_claim' | 'claimed';
  url?: string;
};
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

  // The server supplies the current destination. Display recovery belongs to
  // the complete wallet snapshot, not a second independently reconciled URL map.
  return items.map<CoinTask>(item => {
    const serverId = String(item.id ?? '').trim();
    const state = String(item.task_state || 'available');
    const actionKey = String(item.action_key).trim();
    return {
      id: `production-${serverId}`,
      serverId,
      title: coinTaskTitle(item),
      description: taskDescription(actionKey),
      reward: Number(item.coins_amount),
      url:
        typeof item.action_url === 'string' && item.action_url.trim()
          ? item.action_url.trim()
          : undefined,
      status:
        state === 'claimed'
          ? 'claimed'
          : state === 'ready_to_claim'
          ? 'ready_to_claim'
          : state === 'started'
          ? 'started'
          : 'available',
      actionKey,
      requiresExternalVisit:
        firstBoolean(item.requires_external_visit) ?? false,
    };
  });
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
      await publicRequest.post(`coin-earning-methods/${task.serverId}/start`, {
        supports_ready_claim: true,
      }),
    );
    assertAccountSessionBoundary(boundary);
    if (!isApiRecord(data)) {
      throw new Error('API_CONTRACT_INVALID_COIN_TASK_START');
    }
    const status = String(
      data.task_state || '',
    ) as CoinTaskStartResult['status'];
    if (!['started', 'ready_to_claim', 'claimed'].includes(status)) {
      throw new Error('API_CONTRACT_INVALID_COIN_TASK_START');
    }
    const actionUrl =
      typeof data.action_url === 'string' ? data.action_url.trim() : '';
    const url =
      status === 'started'
        ? actionUrl || task.url
        : status === 'ready_to_claim' && actionUrl
        ? actionUrl
        : undefined;
    const requiresActionUrl =
      task.requiresExternalVisit &&
      (status === 'started' ||
        (status === 'ready_to_claim' && task.actionKey !== 'link_whatsapp'));
    if (
      status !== 'claimed' &&
      (String(data.attempt_id || '').trim() === '' ||
        (requiresActionUrl && !actionUrl))
    ) {
      throw new Error('API_CONTRACT_INVALID_COIN_TASK_START');
    }
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
    // This shared claim can outlive its Wallet. A valid replay also confirms
    // the ledger after a lost acknowledgement without awarding coins again.
    notifyWalletSettlement(boundary);
    assertAccountSessionBoundary(boundary);
    return result;
  })().finally(() => {
    if (claimFlights.get(flightKey) === flight) claimFlights.delete(flightKey);
  });
  claimFlights.set(flightKey, flight);
  return flight;
};
