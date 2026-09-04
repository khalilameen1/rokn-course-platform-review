import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {IS_STORE_DISTRIBUTION} from '../../constants/distribution';
import type {CoinPackage} from './coinPackageMapper';
import {mapCoinPackages} from './coinPackageMapper';
import {
  isApiRecord,
  isResourceListPayload,
  nonNegativeNumber,
  payload,
  requireNonNegativeNumber,
} from './common';

type WalletTransactionDto = {
  id?: unknown;
  amount?: unknown;
  direction?: unknown;
  occurred_at?: string;
  category?: string;
  label_ar?: unknown;
};

type WalletDto = {
  total_balance?: unknown;
  purchased_balance?: unknown;
  reward_balance?: unknown;
  course_spendable_balance?: unknown;
  reward_contribution_cap_per_course?: unknown;
  spend_policy?: unknown;
  coin_rules?: unknown;
  recent_transactions?: WalletTransactionDto[];
};

export type WalletSnapshot = {
  balance: number;
  spendableBalance: number;
  paidBalance: number;
  rewardBalance: number;
  rewardContributionCap: number;
  spendPolicy: string;
  coinRules: string[];
  transactions: Array<{
    id: string;
    amount: number;
    occurred_at?: string;
    label: string;
  }>;
};

export type RewardResult = {
  awarded: number;
  balance: number;
  rewardBalance: number;
};

const financialSnapshot = (data: WalletDto) => {
  const balance = requireNonNegativeNumber(
    data.total_balance,
    'WALLET_TOTAL_BALANCE',
  );
  const paidBalance = requireNonNegativeNumber(
    data.purchased_balance,
    'WALLET_PAID_BALANCE',
  );
  const rewardBalance = requireNonNegativeNumber(
    data.reward_balance,
    'WALLET_REWARD_BALANCE',
  );
  const spendableBalance = requireNonNegativeNumber(
    data.course_spendable_balance,
    'WALLET_SPENDABLE_BALANCE',
  );
  if (
    paidBalance > balance ||
    rewardBalance > balance ||
    paidBalance + rewardBalance !== balance ||
    spendableBalance > balance
  ) {
    throw new Error('API_CONTRACT_INVALID_WALLET_BREAKDOWN');
  }
  return {balance, paidBalance, rewardBalance, spendableBalance};
};

const dailyRewardFlights = new Map<string, Promise<RewardResult>>();

export const claimDailyReward = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<RewardResult> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const flightKey = `${boundary.scope}:${boundary.epoch}`;
  const existing = dailyRewardFlights.get(flightKey);
  if (existing) return existing;
  const flight = (async () => {
    assertAccountSessionBoundary(boundary);
    const data = payload(await publicRequest.post('rewards/daily'));
    assertAccountSessionBoundary(boundary);
    return {
      awarded: requireNonNegativeNumber(data.awarded, 'REWARD_AWARDED'),
      balance: requireNonNegativeNumber(data.balance, 'REWARD_BALANCE'),
      rewardBalance: requireNonNegativeNumber(
        data.reward_balance,
        'REWARD_BUCKET_BALANCE',
      ),
    };
  })().finally(() => {
    if (dailyRewardFlights.get(flightKey) === flight) {
      dailyRewardFlights.delete(flightKey);
    }
  });
  dailyRewardFlights.set(flightKey, flight);
  return flight;
};

export const getWallet = async (): Promise<WalletSnapshot> => {
  const boundary = await captureAccountSessionBoundary();
  const data = payload<WalletDto>(await publicRequest.get('wallet'));
  assertAccountSessionBoundary(boundary);
  const {balance, paidBalance, rewardBalance, spendableBalance} =
    financialSnapshot(data);
  const rewardContributionCap = requireNonNegativeNumber(
    data.reward_contribution_cap_per_course,
    'WALLET_REWARD_CONTRIBUTION_CAP',
  );
  const spendPolicy = String(data.spend_policy || '').trim();
  if (
    spendPolicy !== 'reward_first_then_paid' ||
    spendableBalance !==
      paidBalance + Math.min(rewardBalance, rewardContributionCap)
  ) {
    throw new Error('API_CONTRACT_INVALID_WALLET_BREAKDOWN');
  }

  const seenTransactionIds = new Set<string>();
  if (
    !Array.isArray(data.recent_transactions) ||
    data.recent_transactions.some(item => {
      if (!isApiRecord(item)) return true;
      const id = String(item.id ?? '').trim();
      const direction = String(item.direction ?? '').toLowerCase();
      const malformed =
        id === '' ||
        seenTransactionIds.has(id) ||
        !['credit', 'debit'].includes(direction) ||
        nonNegativeNumber(item.amount) === null ||
        typeof item.label_ar !== 'string' ||
        item.label_ar.trim() === '' ||
        (item.category !== undefined &&
          item.category !== null &&
          typeof item.category !== 'string') ||
        (item.occurred_at !== undefined &&
          item.occurred_at !== null &&
          typeof item.occurred_at !== 'string');
      if (!malformed) seenTransactionIds.add(id);
      return malformed;
    })
  ) {
    throw new Error('API_CONTRACT_INVALID_WALLET_TRANSACTIONS');
  }

  const snapshot: WalletSnapshot = {
    balance,
    paidBalance,
    rewardBalance,
    spendableBalance,
    rewardContributionCap,
    spendPolicy,
    coinRules: Array.isArray(data.coin_rules)
      ? data.coin_rules.map(String).filter(Boolean)
      : typeof data.coin_rules === 'string'
      ? data.coin_rules
          .split(/\r?\n|[.!؟]\s+/)
          .map(rule => rule.trim())
          .filter(Boolean)
      : [],
    transactions: data.recent_transactions.map(item => {
      const amount = Number(item.amount);
      return {
        id: String(item.id).trim(),
        amount:
          String(item.direction).toLowerCase() === 'debit' ? -amount : amount,
        occurred_at: item.occurred_at,
        label: String(item.label_ar).trim(),
      };
    }),
  };
  assertAccountSessionBoundary(boundary);
  return snapshot;
};

export const getCoinPackages = async (): Promise<CoinPackage[]> => {
  const boundary = await captureAccountSessionBoundary();
  const data = payload<unknown>(await publicRequest.get('packages'));
  assertAccountSessionBoundary(boundary);
  const items = Array.isArray(data)
    ? data
    : isApiRecord(data)
    ? data.packages
    : null;
  if (!isResourceListPayload(items)) {
    throw new Error('API_CONTRACT_INVALID_COIN_PACKAGES');
  }
  const packages = mapCoinPackages(items, 'API_CONTRACT_INVALID_COIN_PACKAGES');
  assertAccountSessionBoundary(boundary);
  if (!IS_STORE_DISTRIBUTION) return packages;

  const {hydrateNativeStorePackages} = await import('../nativeStoreBilling');
  assertAccountSessionBoundary(boundary);
  const hydrated = await hydrateNativeStorePackages(packages);
  assertAccountSessionBoundary(boundary);
  return hydrated;
};
