import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';
import type {QueryClient} from '@tanstack/react-query';
import {
  createTestQueryClient,
  withAppQueryProvider,
  withQueryClient,
} from './helpers/queryClient';

let mockScope = 'user-a';
const mockGetPortfolio = jest.fn();
const mockHasSession = jest.fn(async () => true);

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    jest.requireActual('react').useEffect(effect, [effect]);
  },
}));
jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  captureAccountSessionBoundary: async () => ({scope: mockScope, epoch: 1}),
  assertAccountSessionBoundary: (boundary: {scope: string}) => {
    if (boundary.scope !== mockScope) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/services/roknApi', () => ({
  getPortfolio: (...args: unknown[]) => mockGetPortfolio(...args),
  getPortfolioItem: jest.fn(),
  hasSession: () => mockHasSession(),
}));

import {usePortfolioLibrary} from '../src/screens/Profile/gallery/usePortfolioLibrary';
import {
  fallbackPortfolioCover,
  type Project,
} from '../src/screens/Profile/gallery/portfolioModel';
import {attachQueryPersistence} from '../src/services/query/queryPersistence';
import {invalidatePortfolio} from '../src/services/query/portfolioInvalidation';
import {queryClient as appQueryClient} from '../src/services/query/queryClient';
import type {PortfolioItem} from '../src/services/roknApi';

const item = (id: string, live = true): PortfolioItem => ({
  id,
  publicId: `00000000-0000-4000-8000-00000000000${id}`,
  title: `مشروع ${id}`,
  summary: '',
  coverUri: `https://cdn.example/${id}.jpg`,
  tools: [],
  featured: false,
  live,
  media: [
    {
      id: `${id}1`,
      type: 'image',
      uri: `https://cdn.example/${id}.jpg`,
      status: 'ready',
    },
  ],
  publicationState: 'published',
  uploadedMediaCount: 1,
  expectedMediaCount: 1,
});
const project = (id: string, shareReady = true): Project => ({
  id,
  publicId: `00000000-0000-4000-8000-00000000000${id}`,
  title: `مشروع ${id}`,
  summary: '',
  cover: {uri: `https://cdn.example/${id}.jpg`},
  tools: [],
  featured: false,
  source: 'remote',
  media: [
    {
      id: `${id}1`,
      type: 'image',
      uri: `https://cdn.example/${id}.jpg`,
      status: 'ready',
    },
  ],
  shareReady,
  uploadedMediaCount: 1,
  expectedMediaCount: 1,
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};
const offline = () =>
  Object.assign(new Error('Network Error'), {code: 'ERR_NETWORK'});
const settle = (ms = 20) =>
  act(async () => {
    await new Promise(resolve => setTimeout(resolve, ms));
  });
const boundary = () => ({scope: mockScope, epoch: 1});
const captureBoundary = async () => boundary();
const isLoadBlocked = () => false;

/** What a previous process left on the device for `scope`. */
const seedDeviceSnapshot = async (scope: string, projects: Project[]) => {
  const previousScope = mockScope;
  mockScope = scope;
  const previous = createTestQueryClient();
  const persistence = attachQueryPersistence(previous, {throttleTime: 0});
  await persistence.restorePublic();
  await persistence.activateAccountScope(scope);
  previous.setQueryData([scope, 'portfolio'], projects);
  await new Promise(resolve => setTimeout(resolve, 60));
  persistence.dispose();
  previous.clear();
  mockScope = previousScope;
};

describe('portfolio library query', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let client: QueryClient;
  const mountedRef = {current: true};
  let library!: ReturnType<typeof usePortfolioLibrary>;
  const Harness = ({identityKey = 'account-a'}: {identityKey?: string}) => {
    library = usePortfolioLibrary({
      identityKey,
      captureBoundary,
      isLoadBlocked,
      mountedRef,
    });
    return null;
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockScope = 'user-a';
    mountedRef.current = true;
    client = createTestQueryClient();
    mockHasSession.mockResolvedValue(true);
    mockGetPortfolio.mockResolvedValue([item('7')]);
  });
  afterEach(async () => {
    mountedRef.current = false;
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  });

  it('reads the list once on mount and keeps publication state on each project', async () => {
    mockGetPortfolio.mockResolvedValue([item('7'), item('8', false)]);
    await act(async () => {
      renderer = TestRenderer.create(withQueryClient(<Harness />, client));
    });
    await settle();
    expect(mockGetPortfolio).toHaveBeenCalledTimes(1);
    expect(library.loading).toBe(false);
    expect(library.serverSession).toBe(true);
    expect(library.projects.map(entry => [entry.id, entry.shareReady])).toEqual(
      [
        ['7', true],
        ['8', false],
      ],
    );
  });

  it('never shows the previous account’s projects after a switch', async () => {
    const frames: string[][] = [];
    const Probe = ({identityKey}: {identityKey: string}) => {
      const result = usePortfolioLibrary({
        identityKey,
        captureBoundary,
        isLoadBlocked,
        mountedRef,
      });
      frames.push(result.projects.map(entry => entry.id));
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<Probe identityKey="account-a" />, client),
      );
    });
    await settle();
    expect(frames.at(-1)).toEqual(['7']);

    const next = deferred<PortfolioItem[]>();
    mockGetPortfolio.mockReturnValue(next.promise);
    mockScope = 'user-b';
    const switchedAt = frames.length;
    await act(async () => {
      renderer!.update(
        withQueryClient(<Probe identityKey="account-b" />, client),
      );
    });
    await settle();
    expect(frames.slice(switchedAt).length).toBeGreaterThan(0);
    frames.slice(switchedAt).forEach(frame => expect(frame).not.toContain('7'));
    expect(client.getQueryData(['user-a', 'portfolio'])).toBeUndefined();
    await act(async () => next.resolve([item('9')]));
    await settle();
    expect(frames.at(-1)).toEqual(['9']);
  });

  it('does not let a pre-delete list response restore the deleted work', async () => {
    await act(async () => {
      renderer = TestRenderer.create(withQueryClient(<Harness />, client));
    });
    await settle();
    const oldRead = deferred<PortfolioItem[]>();
    mockGetPortfolio.mockReturnValueOnce(oldRead.promise);
    await act(async () => {
      void library.loadProjects();
    });
    await settle();
    expect(library.loading).toBe(true);
    // The mutation hook: cancel the read, then write the acknowledged result.
    act(() => {
      library.cancelLoad();
      library.setProjects(current => current.filter(entry => entry.id !== '7'));
    });
    await settle();
    expect(library.projects).toEqual([]);
    expect(library.loading).toBe(false);
    await act(async () => oldRead.resolve([item('7')]));
    await settle();
    expect(library.projects).toEqual([]);
    expect(client.getQueryData(['user-a', 'portfolio'])).toEqual([]);
  });

  it('reads again when a confirmed delivery invalidates the list', async () => {
    attachQueryPersistence(appQueryClient, {throttleTime: 0});
    appQueryClient.clear();
    await act(async () => {
      renderer = TestRenderer.create(
        withQueryClient(<Harness />, appQueryClient),
      );
    });
    await settle();
    expect(mockGetPortfolio).toHaveBeenCalledTimes(1);
    mockGetPortfolio.mockResolvedValue([item('7'), item('8')]);
    act(() => invalidatePortfolio('user-a'));
    await settle();
    expect(mockGetPortfolio).toHaveBeenCalledTimes(2);
    expect(library.projects.map(entry => entry.id)).toEqual(['7', '8']);
    appQueryClient.clear();
  });

  it('shows the saved portfolio with its offline note on a cold start, and never a deleted work', async () => {
    await seedDeviceSnapshot('user-a', [project('7'), project('8', false)]);
    const failLater = () =>
      new Promise<PortfolioItem[]>((_resolve, reject) =>
        setTimeout(() => reject(offline()), 30),
      );
    mockGetPortfolio.mockImplementation(failLater);
    const errors: string[] = [];
    const Screen = () => {
      library = usePortfolioLibrary({
        identityKey: 'account-a',
        captureBoundary,
        isLoadBlocked,
        mountedRef,
      });
      errors.push(library.loadError);
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screen />, client, 'account-a'),
      );
    });
    await settle(15);
    // Restored before the network answered: shown at once with its cover.
    expect(library.projects.map(entry => [entry.id, entry.shareReady])).toEqual(
      [
        ['7', true],
        ['8', false],
      ],
    );
    expect(library.projects[0].cover).toEqual({
      uri: 'https://cdn.example/7.jpg',
    });
    await settle(80);
    expect(library.projects.map(entry => entry.id)).toEqual(['7', '8']);
    expect(library.loadError).toBe('تعذّر تحميل البورتفوليو\nمشروعاتك محفوظة');
    errors.forEach(error => {
      expect(
        error === '' || error === 'تعذّر تحميل البورتفوليو\nمشروعاتك محفوظة',
      ).toBe(true);
    });

    // A confirmed deletion leaves the snapshot too: reopening offline later
    // does not redisplay the deleted work.
    act(() => {
      library.setProjects(current => current.filter(entry => entry.id !== '7'));
    });
    await settle(60);
    await act(async () => renderer!.unmount());
    renderer = undefined;
    const reopened = createTestQueryClient();
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Screen />, reopened, 'account-a'),
      );
    });
    await settle(120);
    expect(library.projects.map(entry => entry.id)).toEqual(['8']);
    expect(library.projects[0].cover).toEqual({
      uri: 'https://cdn.example/8.jpg',
    });
  });

  it('restores the bundled cover for a project whose snapshot has no remote cover', async () => {
    await seedDeviceSnapshot('user-a', [
      {...project('7'), cover: fallbackPortfolioCover},
    ]);
    mockGetPortfolio.mockImplementation(
      () =>
        new Promise<PortfolioItem[]>((_resolve, reject) =>
          setTimeout(() => reject(offline()), 30),
        ),
    );
    await act(async () => {
      renderer = TestRenderer.create(
        withAppQueryProvider(<Harness />, client, 'account-a'),
      );
    });
    await settle(120);
    expect(library.projects).toHaveLength(1);
    expect(library.projects[0].cover).toBe(fallbackPortfolioCover);
  });

  it('shows nothing and the signed-out state without a session', async () => {
    mockHasSession.mockResolvedValue(false);
    await act(async () => {
      renderer = TestRenderer.create(withQueryClient(<Harness />, client));
    });
    await settle();
    expect(mockGetPortfolio).not.toHaveBeenCalled();
    expect(library.serverSession).toBe(false);
    expect(library.projects).toEqual([]);
    expect(library.loading).toBe(false);
    expect(library.loadError).toBe('');
  });
});
