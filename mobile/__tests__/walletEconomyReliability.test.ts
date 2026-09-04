import fs from 'fs';
import path from 'path';

let mockAccountEpoch = 1;

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(async () => null),
  setItem: jest.fn(async () => undefined),
}));

jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'direct',
  IS_STORE_DISTRIBUTION: false,
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope ?? 'user-1'}`,
  ),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: mockAccountEpoch,
    scope: `user-${mockAccountEpoch}`,
  })),
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockAccountEpoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  }),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {mapCoinPackages} from '../src/services/api/coinPackageMapper';
import {getCoinPackages, getCoinTasks} from '../src/services/api/economy';

const mockGet = publicRequest.get as jest.Mock;

const directPackage = (overrides: Record<string, unknown> = {}) => ({
  id: 4,
  coins: 4200,
  price: 249,
  direct_price: 224.1,
  name: '٤٢٠٠ عملة',
  name_ar: '٤٢٠٠ عملة',
  name_en: '4200 coins',
  channels: {direct: true, google: false, apple: false},
  store_products: {google: null, apple: null},
  ...overrides,
});

describe('wallet economy reliability', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockAccountEpoch = 1;
  });

  it('maps only the canonical direct package price and dashboard label', () => {
    expect(mapCoinPackages([directPackage()], 'INVALID')).toEqual([
      expect.objectContaining({
        id: '4',
        coins: 4200,
        price: 224.1,
        label: '٤٢٠٠ عملة',
      }),
    ]);
  });

  it('uses the first non-empty localized package label', () => {
    expect(
      mapCoinPackages(
        [directPackage({name_ar: '   ', name_en: '4200 coins'})],
        'INVALID',
      ),
    ).toEqual([expect.objectContaining({label: '4200 coins'})]);
  });

  it.each([
    ['missing direct price', {direct_price: null}],
    ['fractional coin amount', {coins: 4200.5}],
    ['missing package label', {name: '', name_ar: '', name_en: ''}],
    ['missing channel contract', {channels: undefined}],
  ])('rejects %s instead of hiding or inventing a package', (_case, patch) => {
    expect(() =>
      mapCoinPackages([directPackage(patch)], 'INVALID_COIN_PACKAGES'),
    ).toThrow('INVALID_COIN_PACKAGES');
  });

  it('drops a package response that returns after an account switch', async () => {
    let resolveRequest: (value: unknown) => void = () => undefined;
    mockGet.mockReturnValueOnce(
      new Promise(resolve => {
        resolveRequest = resolve;
      }),
    );

    const request = getCoinPackages();
    await Promise.resolve();
    mockAccountEpoch = 2;
    resolveRequest({data: {data: [directPackage()]}});

    await expect(request).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });

  it('renders the canonical dashboard task title without client rewriting', async () => {
    mockGet.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: 11,
            action_key: 'follow_instagram',
            title_ar: 'تابع حساب ركن على إنستغرام',
            coins_amount: 75,
            task_state: 'available',
            action_url: 'https://instagram.com/rokn.app',
            requires_external_visit: true,
          },
        ],
      },
    });

    await expect(getCoinTasks()).resolves.toEqual([
      expect.objectContaining({title: 'تابع حساب ركن على إنستغرام'}),
    ]);
  });

  it('rejects a task without an action identity', async () => {
    mockGet.mockResolvedValueOnce({
      data: {
        data: [
          {
            id: 11,
            action_key: '',
            title_ar: 'تابع حساب ركن',
            coins_amount: 75,
            task_state: 'available',
            requires_external_visit: true,
          },
        ],
      },
    });

    await expect(getCoinTasks()).rejects.toThrow(
      'API_CONTRACT_INVALID_COIN_TASKS',
    );
  });

  it('uses a new cache schema and keeps stale data visibly in error state', () => {
    const cache = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/wallet/walletCache.ts'),
      'utf8',
    );
    const walletData = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/wallet/useWalletData.ts'),
      'utf8',
    );

    expect(cache).toContain("const WALLET_CACHE_KEY = '@rokn/wallet-cache/v2'");
    expect(cache).not.toContain(
      "const WALLET_CACHE_KEY = '@rokn/wallet-cache/v1'",
    );
    expect(walletData).toContain(
      "setPackagesStatus(packagesReadFailed ? 'error' : 'ready')",
    );
    expect(walletData).toContain(
      "setTasksStatus(tasksReadFailed ? 'error' : 'ready')",
    );
  });
});
