import AsyncStorage from '@react-native-async-storage/async-storage';
import type {QueryClient} from '@tanstack/react-query';
import {createTestQueryClient} from './helpers/queryClient';

let mockScope = 'user-a';
jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  captureAccountSessionBoundary: async () => ({scope: mockScope, epoch: 1}),
}));

import {clearAccountScopedStorage} from '../src/constants/helpers';
import {isRestoredSnapshot} from '../src/services/query/queryClient';
import {
  accountQueryKeys,
  publicQueryKeys,
} from '../src/services/query/queryKeys';
import {
  accountQueryCacheKey,
  attachQueryPersistence,
  discardSnapshotWith,
  PUBLIC_QUERY_CACHE_KEY,
  QUERY_CACHE_BUSTER,
} from '../src/services/query/queryPersistence';

const HOUR = 60 * 60 * 1000;
const settle = () => new Promise<void>(resolve => setTimeout(resolve, 25));

const coursePage = (ids: string[], page = 1, revision = 1, extra = {}) => ({
  courses: ids.map(id => ({
    id,
    title: `كورس ${id}`,
    image: {uri: 'https://cdn.example.test/cover.jpg'},
    owned: true,
    progress: 70,
  })),
  page,
  hasMore: true,
  total: 200,
  revision,
  query: '',
  ...extra,
});
const catalogueData = (pages: ReturnType<typeof coursePage>[]) => ({
  pages,
  pageParams: pages.map(page => ({page: page.page, revision: page.revision})),
});
const wallet = {
  balance: 30,
  paidBalance: 10,
  rewardBalance: 20,
  rewardContributionCap: 100,
  spendableBalance: 30,
  spendPolicy: 'reward_first_then_paid',
  coinRules: [],
  transactions: [],
};
const dashboard = {
  courses: [
    {
      id: '3',
      title: 'كورس',
      progress: 20,
      completedSections: 1,
      totalSections: 5,
      category: 'freelance',
      accessType: 'paid',
      chatAvailable: false,
      certificateAvailable: true,
    },
  ],
  paths: [],
  badges: [],
  activityDays: [],
  currentStreakDays: 2,
};
const details = (id: string) => ({
  course: {
    id,
    publishedRevision: 1,
    title: `كورس ${id}`,
    accessPlans: [],
    owned: false,
    fromCache: false,
  },
  learning: {heavy: true},
  learningRead: {receivedAt: 1},
});

/** Writes a snapshot as a previous process would have, `ageMs` ago. */
const writeSnapshot = async (
  key: string,
  queries: Array<{queryKey: unknown[]; data: unknown; ageMs?: number}>,
  buster = QUERY_CACHE_BUSTER,
) => {
  const now = Date.now();
  await AsyncStorage.setItem(
    key,
    JSON.stringify({
      buster,
      timestamp: now,
      clientState: {
        mutations: [],
        queries: queries.map(({queryKey, data, ageMs = 0}) => ({
          queryKey,
          queryHash: JSON.stringify(queryKey),
          serverUpdatedAt: now - ageMs,
          state: {
            data,
            dataUpdatedAt: now - ageMs,
            dataUpdateCount: 1,
            error: null,
            errorUpdateCount: 0,
            errorUpdatedAt: 0,
            fetchFailureCount: 0,
            fetchFailureReason: null,
            fetchMeta: null,
            isInvalidated: false,
            status: 'success',
            fetchStatus: 'idle',
          },
        })),
      },
    }),
  );
};

const readSnapshot = async (key: string) => {
  const raw = await AsyncStorage.getItem(key);
  return raw ? JSON.parse(raw) : null;
};

describe('device snapshot of reads (persistQueryClient)', () => {
  let client: QueryClient;
  beforeEach(async () => {
    jest.restoreAllMocks();
    mockScope = 'user-a';
    await AsyncStorage.clear();
    client = createTestQueryClient();
  });
  afterEach(() => {
    attachQueryPersistence(client).dispose();
    client.clear();
  });

  it('restores the public catalogue and an account snapshot on cold start', async () => {
    await writeSnapshot(PUBLIC_QUERY_CACHE_KEY, [
      {
        queryKey: [...publicQueryKeys.cataloguePages('')],
        data: catalogueData([coursePage(['1'])]),
      },
    ]);
    await writeSnapshot(accountQueryCacheKey('user-a'), [
      {queryKey: [...accountQueryKeys('user-a').wallet()], data: wallet},
      {
        queryKey: [...accountQueryKeys('user-a').learningDashboard()],
        data: dashboard,
      },
    ]);
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.restorePublic();
    await persistence.activateAccountScope('user-a');

    expect(
      client.getQueryData(accountQueryKeys('user-a').wallet()),
    ).toMatchObject({balance: 30});
    expect(
      client.getQueryData(accountQueryKeys('user-a').learningDashboard()),
    ).toMatchObject({currentStreakDays: 2});
    const catalogue = client
      .getQueryCache()
      .find({queryKey: publicQueryKeys.cataloguePages(''), exact: true});
    expect(isRestoredSnapshot(catalogue)).toBe(true);
    // Restored data never counts as fresh for a new process.
    expect(
      (client.getDefaultOptions().queries!.refetchOnMount as Function)(
        catalogue,
      ),
    ).toBe('always');
    // Ownership is the account's, never part of the public snapshot.
    expect(
      (client.getQueryData(publicQueryKeys.cataloguePages('')) as any).pages[0]
        .courses[0],
    ).toMatchObject({id: '1', owned: false});
  });

  it.each([
    ['catalogue', [...publicQueryKeys.cataloguePages('')], 2 * HOUR],
    ['learning dashboard', ['user-a', 'learning-dashboard'], 6 * HOUR],
    ['guest course details', ['guest-x', 'course-details', '4'], 24 * HOUR],
  ])(
    'drops a %s snapshot older than its previous max age',
    async (_label, queryKey, maxAge) => {
      const data =
        queryKey[1] === 'catalogue'
          ? catalogueData([coursePage(['1'])])
          : queryKey[1] === 'learning-dashboard'
          ? dashboard
          : details('4');
      const scope = String(queryKey[0]);
      const key =
        scope === 'public'
          ? PUBLIC_QUERY_CACHE_KEY
          : accountQueryCacheKey(scope);
      mockScope = scope === 'public' ? 'user-a' : scope;
      await writeSnapshot(key, [{queryKey, data, ageMs: maxAge + 60_000}]);
      const persistence = attachQueryPersistence(client, {throttleTime: 0});
      await (scope === 'public'
        ? persistence.restorePublic()
        : persistence.activateAccountScope(scope));
      expect(client.getQueryData(queryKey)).toBeUndefined();
      persistence.dispose();
      await settle();

      await writeSnapshot(key, [{queryKey, data, ageMs: maxAge - 60_000}]);
      const fresh = createTestQueryClient();
      const second = attachQueryPersistence(fresh, {throttleTime: 0});
      await (scope === 'public'
        ? second.restorePublic()
        : second.activateAccountScope(scope));
      expect(fresh.getQueryData(queryKey)).toBeDefined();
      second.dispose();
    },
  );

  it('keeps the wallet snapshot without an age limit, as before', async () => {
    await writeSnapshot(accountQueryCacheKey('user-a'), [
      {
        queryKey: [...accountQueryKeys('user-a').wallet()],
        data: wallet,
        ageMs: 90 * 24 * HOUR,
      },
    ]);
    await attachQueryPersistence(client).activateAccountScope('user-a');
    expect(client.getQueryData(accountQueryKeys('user-a').wallet())).toEqual(
      wallet,
    );
  });

  it('ignores a future-dated snapshot (device clock moved back)', async () => {
    await writeSnapshot(accountQueryCacheKey('user-a'), [
      {
        queryKey: [...accountQueryKeys('user-a').wallet()],
        data: wallet,
        ageMs: -HOUR,
      },
    ]);
    await attachQueryPersistence(client).activateAccountScope('user-a');
    expect(
      client.getQueryData(accountQueryKeys('user-a').wallet()),
    ).toBeUndefined();
  });

  it('discards a snapshot from another API base or schema version', async () => {
    await writeSnapshot(
      accountQueryCacheKey('user-a'),
      [{queryKey: [...accountQueryKeys('user-a').wallet()], data: wallet}],
      'https://staging.example.test/api/v1/#query-cache-v1',
    );
    await attachQueryPersistence(client).activateAccountScope('user-a');
    expect(
      client.getQueryData(accountQueryKeys('user-a').wallet()),
    ).toBeUndefined();
    expect(await AsyncStorage.getItem(accountQueryCacheKey('user-a'))).toBe(
      null,
    );
  });

  it('survives and removes a damaged snapshot', async () => {
    await AsyncStorage.setItem(accountQueryCacheKey('user-a'), '{damaged');
    const errors = jest.spyOn(console, 'error').mockImplementation(() => {});
    const warnings = jest.spyOn(console, 'warn').mockImplementation(() => {});
    await expect(
      attachQueryPersistence(client).activateAccountScope('user-a'),
    ).resolves.toBeUndefined();
    expect(await AsyncStorage.getItem(accountQueryCacheKey('user-a'))).toBe(
      null,
    );
    errors.mockRestore();
    warnings.mockRestore();
  });

  it('drops invalid records and keeps only one catalogue revision from page one', async () => {
    await writeSnapshot(accountQueryCacheKey('user-a'), [
      {
        queryKey: [...accountQueryKeys('user-a').wallet()],
        data: {...wallet, balance: 999},
      },
    ]);
    await writeSnapshot(PUBLIC_QUERY_CACHE_KEY, [
      {
        queryKey: [...publicQueryKeys.cataloguePages('')],
        data: catalogueData([
          coursePage(['1'], 1, 4),
          coursePage(['2'], 2, 4),
          coursePage(['3'], 3, 5),
        ]),
      },
    ]);
    const persistence = attachQueryPersistence(client);
    await persistence.restorePublic();
    await persistence.activateAccountScope('user-a');
    expect(
      client.getQueryData(accountQueryKeys('user-a').wallet()),
    ).toBeUndefined();
    const pages = (
      client.getQueryData(publicQueryKeys.cataloguePages('')) as any
    ).pages;
    expect(pages.map((page: {page: number}) => page.page)).toEqual([1, 2]);
  });

  it('writes the first four browse pages only, without ownership or search results', async () => {
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.restorePublic();
    client.setQueryData(
      publicQueryKeys.cataloguePages(''),
      catalogueData(
        [1, 2, 3, 4, 5].map(page => coursePage([String(page)], page)),
      ),
    );
    client.setQueryData(
      publicQueryKeys.cataloguePages('تصميم'),
      catalogueData([coursePage(['9'])]),
    );
    await settle();
    const snapshot = await readSnapshot(PUBLIC_QUERY_CACHE_KEY);
    expect(snapshot.clientState.queries).toHaveLength(1);
    const persisted = snapshot.clientState.queries[0].state.data;
    expect(persisted.pages).toHaveLength(4);
    expect(persisted.pages[0].courses[0]).not.toHaveProperty('owned');
    expect(persisted.pages[0].courses[0]).not.toHaveProperty('progress');
  });

  it('keeps eight guest course details, without their learning envelope, and none for a signed-in account', async () => {
    mockScope = 'guest-x';
    const guest = attachQueryPersistence(client, {throttleTime: 0});
    await guest.activateAccountScope('guest-x');
    for (let id = 1; id <= 9; id++) {
      client.setQueryData(
        accountQueryKeys('guest-x').courseDetails(String(id)),
        details(String(id)),
        {updatedAt: Date.now() - (10 - id) * 1000},
      );
    }
    await settle();
    const snapshot = await readSnapshot(accountQueryCacheKey('guest-x'));
    const ids = snapshot.clientState.queries.map(
      (query: {queryKey: string[]}) => query.queryKey[2],
    );
    expect(ids.sort()).toEqual(['2', '3', '4', '5', '6', '7', '8', '9']);
    expect(snapshot.clientState.queries[0].state.data).toMatchObject({
      learning: null,
      learningRead: null,
    });

    mockScope = 'user-a';
    await guest.activateAccountScope('user-a');
    client.setQueryData(
      accountQueryKeys('user-a').courseDetails('1'),
      details('1'),
    );
    client.setQueryData(accountQueryKeys('user-a').wallet(), wallet);
    await settle();
    const account = await readSnapshot(accountQueryCacheKey('user-a'));
    expect(
      account.clientState.queries.map(
        (query: {queryKey: string[]}) => query.queryKey[1],
      ),
    ).toEqual(['wallet']);
  });

  it('stops carrying data an authoritative error proved wrong', async () => {
    mockScope = 'guest-x';
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.activateAccountScope('guest-x');
    const key = accountQueryKeys('guest-x').courseDetails('5');
    client.setQueryData(key, details('5'));
    await settle();
    expect(
      (await readSnapshot(accountQueryCacheKey('guest-x'))).clientState.queries,
    ).toHaveLength(1);
    await client
      .fetchQuery({
        queryKey: key,
        queryFn: () => Promise.reject(discardSnapshotWith({status: 404})),
        staleTime: 0,
      })
      .catch(() => undefined);
    await settle();
    expect(
      (await readSnapshot(accountQueryCacheKey('guest-x'))).clientState.queries,
    ).toHaveLength(0);
  });

  it('keeps each account in its own key, which logout cleanup removes', async () => {
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.activateAccountScope('user-a');
    client.setQueryData(accountQueryKeys('user-a').wallet(), wallet);
    await settle();
    expect(await readSnapshot(accountQueryCacheKey('user-a'))).not.toBeNull();

    await clearAccountScopedStorage('user-a');
    expect(await AsyncStorage.getItem(accountQueryCacheKey('user-a'))).toBe(
      null,
    );
  });

  it('never writes an account snapshot once that account no longer owns the session', async () => {
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.activateAccountScope('user-a');
    mockScope = 'guest-b';
    client.setQueryData(accountQueryKeys('user-a').wallet(), wallet);
    await settle();
    expect(await AsyncStorage.getItem(accountQueryCacheKey('user-a'))).toBe(
      null,
    );
  });

  it('lands a stalled older snapshot write before the newer one', async () => {
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.activateAccountScope('user-a');
    await settle();
    const nativeSet = jest.mocked(AsyncStorage.setItem);
    const rawSet = nativeSet.getMockImplementation()!;
    let release!: () => void;
    const stalled = new Promise<void>(resolve => {
      release = resolve;
    });
    nativeSet.mockImplementationOnce(async (key, value) => {
      await stalled;
      return rawSet(key, value);
    });
    client.setQueryData(accountQueryKeys('user-a').wallet(), wallet);
    await settle();
    client.setQueryData(accountQueryKeys('user-a').wallet(), {
      ...wallet,
      balance: 40,
      paidBalance: 20,
      spendableBalance: 40,
    });
    await settle();
    release();
    await new Promise(resolve => setTimeout(resolve, 1100));
    expect(
      (await readSnapshot(accountQueryCacheKey('user-a'))).clientState
        .queries[0].state.data,
    ).toMatchObject({balance: 40});
  });

  it('undoes a write that lands after logout removed the account', async () => {
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.activateAccountScope('user-a');
    await settle();
    const nativeSet = jest.mocked(AsyncStorage.setItem);
    const rawSet = nativeSet.getMockImplementation()!;
    let release!: () => void;
    const stalled = new Promise<void>(resolve => {
      release = resolve;
    });
    nativeSet.mockImplementationOnce(async (key, value) => {
      await stalled;
      return rawSet(key, value);
    });
    client.setQueryData(accountQueryKeys('user-a').wallet(), wallet);
    await settle();
    mockScope = 'guest-next';
    await clearAccountScopedStorage('user-a');
    release();
    await settle();
    expect(await AsyncStorage.getItem(accountQueryCacheKey('user-a'))).toBe(
      null,
    );
  });

  it('does not stop later writes after a failed native write', async () => {
    const persistence = attachQueryPersistence(client, {throttleTime: 0});
    await persistence.activateAccountScope('user-a');
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockRejectedValueOnce(new Error('disk unavailable'));
    client.setQueryData(accountQueryKeys('user-a').wallet(), wallet);
    await settle();
    client.setQueryData(accountQueryKeys('user-a').wallet(), {
      ...wallet,
      balance: 40,
      paidBalance: 20,
      spendableBalance: 40,
    });
    await settle();
    expect(
      (await readSnapshot(accountQueryCacheKey('user-a'))).clientState
        .queries[0].state.data,
    ).toMatchObject({balance: 40});
  });
});
