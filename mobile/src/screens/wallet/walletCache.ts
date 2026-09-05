import type {AccountSessionBoundary} from '../../constants/helpers';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  getItem,
  saveItem,
} from '../../constants/helpers';
import type {CoinPackage} from '../../services/api/coinPackageMapper';
import type {CoinTask, WalletSnapshot} from '../../services/roknApi';

const WALLET_CACHE_KEY = '@rokn/wallet-cache/v2';
let walletCacheWriteTail: Promise<void> = Promise.resolve();

export type WalletCache = {
  version: 2;
  wallet?: WalletSnapshot;
  packages?: CoinPackage[];
  tasks?: CoinTask[];
};

const isNonNegativeFinite = (value: unknown) =>
  Number.isFinite(Number(value)) && Number(value) >= 0;

const validCachedWallet = (value: unknown): value is WalletSnapshot => {
  if (!value || typeof value !== 'object') return false;
  const wallet = value as WalletSnapshot;
  return (
    isNonNegativeFinite(wallet.balance) &&
    isNonNegativeFinite(wallet.paidBalance) &&
    isNonNegativeFinite(wallet.rewardBalance) &&
    isNonNegativeFinite(wallet.spendableBalance) &&
    isNonNegativeFinite(wallet.rewardContributionCap) &&
    Number(wallet.paidBalance) + Number(wallet.rewardBalance) ===
      Number(wallet.balance) &&
    wallet.spendPolicy === 'reward_first_then_paid' &&
    Number(wallet.spendableBalance) ===
      Number(wallet.paidBalance) +
        Math.min(
          Number(wallet.rewardBalance),
          Number(wallet.rewardContributionCap),
        ) &&
    Array.isArray(wallet.coinRules) &&
    wallet.coinRules.every(rule => typeof rule === 'string') &&
    Array.isArray(wallet.transactions) &&
    wallet.transactions.every(
      item =>
        item &&
        typeof item.id === 'string' &&
        item.id.length > 0 &&
        Number.isFinite(Number(item.amount)) &&
        typeof item.label === 'string' &&
        item.label.trim().length > 0 &&
        (item.occurred_at === undefined ||
          typeof item.occurred_at === 'string'),
    )
  );
};

const validCachedPackages = (value: unknown): value is CoinPackage[] =>
  Array.isArray(value) &&
  value.every(
    item =>
      item &&
      typeof item.id === 'string' &&
      /^\d+$/.test(item.id) &&
      Number.isSafeInteger(Number(item.id)) &&
      Number(item.id) > 0 &&
      isNonNegativeFinite(item.coins) &&
      Number.isSafeInteger(Number(item.coins)) &&
      Number(item.coins) > 0 &&
      isNonNegativeFinite(item.price) &&
      Number(item.price) > 0 &&
      typeof item.label === 'string' &&
      item.label.trim().length > 0,
  );

const validCachedTasks = (value: unknown): value is CoinTask[] =>
  Array.isArray(value) &&
  value.every(
    item =>
      item &&
      typeof item.id === 'string' &&
      /^\d+$/.test(item.serverId) &&
      Number.isSafeInteger(Number(item.serverId)) &&
      Number(item.serverId) > 0 &&
      item.id === `production-${item.serverId}` &&
      typeof item.title === 'string' &&
      item.title.trim().length > 0 &&
      isNonNegativeFinite(item.reward) &&
      Number.isSafeInteger(Number(item.reward)) &&
      Number(item.reward) > 0 &&
      ['available', 'started', 'ready_to_claim', 'claimed'].includes(
        item.status,
      ) &&
      typeof item.actionKey === 'string' &&
      item.actionKey.trim().length > 0 &&
      typeof item.requiresExternalVisit === 'boolean' &&
      (item.url === undefined || typeof item.url === 'string'),
  );

export const readWalletCache = async (boundary: AccountSessionBoundary) => {
  await walletCacheWriteTail.catch(() => undefined);
  assertAccountSessionBoundary(boundary);
  const key = await accountScopedStorageKey(WALLET_CACHE_KEY, boundary);
  const cached = await getItem<Partial<WalletCache>>(key);
  assertAccountSessionBoundary(boundary);
  if (cached?.version !== 2) return null;
  return {
    version: 2 as const,
    ...(validCachedWallet(cached.wallet) ? {wallet: cached.wallet} : {}),
    ...(validCachedPackages(cached.packages)
      ? {packages: cached.packages}
      : {}),
    ...(validCachedTasks(cached.tasks) ? {tasks: cached.tasks} : {}),
  };
};

export const saveWalletCache = async (
  boundary: AccountSessionBoundary,
  cache: WalletCache,
) => {
  const write = walletCacheWriteTail
    .catch(() => undefined)
    .then(async () => {
      assertAccountSessionBoundary(boundary);
      const key = await accountScopedStorageKey(WALLET_CACHE_KEY, boundary);
      await saveItem(key, cache);
      assertAccountSessionBoundary(boundary);
    });
  walletCacheWriteTail = write.catch(() => undefined);
  return write;
};
