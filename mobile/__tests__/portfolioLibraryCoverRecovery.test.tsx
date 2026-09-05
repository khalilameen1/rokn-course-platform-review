import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetCachedPortfolio = jest.fn();
const mockGetPortfolio = jest.fn();
const mockGetPortfolioItem = jest.fn();
const mockHasSession = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(effect, [effect]);
  },
}));

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));

jest.mock('../src/services/roknApi', () => ({
  getCachedPortfolio: (...args: unknown[]) => mockGetCachedPortfolio(...args),
  getPortfolio: (...args: unknown[]) => mockGetPortfolio(...args),
  getPortfolioItem: (...args: unknown[]) => mockGetPortfolioItem(...args),
  hasSession: (...args: unknown[]) => mockHasSession(...args),
}));

import {usePortfolioLibrary} from '../src/screens/Profile/gallery/usePortfolioLibrary';
import {portfolioProjectCoverUri} from '../src/screens/Profile/gallery/portfolioModel';

const boundary = {epoch: 1, scope: 'account-a'};
const item = (coverUri: string) => ({
  id: '9',
  title: 'مشروع',
  summary: '',
  coverUri,
  skills: [],
  featured: false,
  media: [
    {
      id: '71',
      type: 'image' as const,
      uri: coverUri,
      status: 'ready' as const,
    },
  ],
  publicationState: 'published' as const,
  uploadedMediaCount: 1,
  expectedMediaCount: 1,
});

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

const flush = async () => {
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
};

describe('portfolio library cover recovery', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockHasSession.mockResolvedValue(true);
    mockGetCachedPortfolio.mockResolvedValue([]);
    mockGetPortfolio.mockResolvedValue([
      item('https://cdn.example/expired.jpg'),
    ]);
  });

  it('shows a local fallback and renews one broken grid cover without a retry loop', async () => {
    let library!: ReturnType<typeof usePortfolioLibrary>;
    const mountedRef = {current: true};
    const captureBoundary = async () => boundary;
    const isLoadBlocked = () => false;
    const Harness = () => {
      library = usePortfolioLibrary({
        captureBoundary,
        isLoadBlocked,
        mountedRef,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    const failedProject = library.projects[0];
    const renewal = deferred<ReturnType<typeof item>>();
    mockGetPortfolioItem.mockReturnValueOnce(renewal.promise);

    await act(async () => {
      library.handleProjectCoverError(failedProject);
      await flush();
    });
    expect(portfolioProjectCoverUri(library.projects[0])).toBeUndefined();
    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(1);
    expect(mockGetPortfolioItem).toHaveBeenCalledWith('9', boundary);

    await act(async () => {
      library.handleProjectCoverError(failedProject);
      renewal.resolve(item('https://cdn.example/fresh.jpg'));
      await flush();
    });
    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(1);
    expect(portfolioProjectCoverUri(library.projects[0])).toBe(
      'https://cdn.example/fresh.jpg',
    );

    await act(async () => {
      library.handleProjectCoverLoad(library.projects[0]);
    });
    mockGetPortfolioItem.mockResolvedValueOnce(
      item('https://cdn.example/newer.jpg'),
    );
    await act(async () => {
      library.handleProjectCoverError(library.projects[0]);
      await flush();
    });
    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(2);
    expect(portfolioProjectCoverUri(library.projects[0])).toBe(
      'https://cdn.example/newer.jpg',
    );

    mountedRef.current = false;
    await act(async () => renderer.unmount());
  });

  it('does not let an older cover renewal replace a newer library reload', async () => {
    let library!: ReturnType<typeof usePortfolioLibrary>;
    const mountedRef = {current: true};
    const captureBoundary = async () => boundary;
    const isLoadBlocked = () => false;
    const Harness = () => {
      library = usePortfolioLibrary({
        captureBoundary,
        isLoadBlocked,
        mountedRef,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    const failedProject = library.projects[0];
    const oldRenewal = deferred<ReturnType<typeof item>>();
    mockGetPortfolioItem.mockReturnValueOnce(oldRenewal.promise);
    await act(async () => {
      library.handleProjectCoverError(failedProject);
      await flush();
    });

    mockGetPortfolio.mockResolvedValueOnce([
      item('https://cdn.example/catalogue-new.jpg'),
    ]);
    await act(async () => {
      await library.loadProjects();
    });
    oldRenewal.resolve(item('https://cdn.example/stale-renewal.jpg'));
    await act(flush);

    expect(portfolioProjectCoverUri(library.projects[0])).toBe(
      'https://cdn.example/catalogue-new.jpg',
    );

    mountedRef.current = false;
    await act(async () => renderer.unmount());
  });

  it('does not replace newer project metadata while a cover renewal is pending', async () => {
    let library!: ReturnType<typeof usePortfolioLibrary>;
    const mountedRef = {current: true};
    const captureBoundary = async () => boundary;
    const isLoadBlocked = () => false;
    const Harness = () => {
      library = usePortfolioLibrary({
        captureBoundary,
        isLoadBlocked,
        mountedRef,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    const failedProject = library.projects[0];
    const oldRenewal = deferred<ReturnType<typeof item>>();
    mockGetPortfolioItem.mockReturnValueOnce(oldRenewal.promise);
    await act(async () => {
      library.handleProjectCoverError(failedProject);
      await flush();
    });

    await act(async () => {
      library.setProjects(current =>
        current.map(project =>
          project.id === failedProject.id
            ? {...project, title: 'عنوان أحدث', shareReady: false}
            : project,
        ),
      );
    });
    oldRenewal.resolve(item('https://cdn.example/stale-metadata.jpg'));
    await act(flush);

    expect(library.projects[0]).toMatchObject({
      title: 'عنوان أحدث',
      shareReady: false,
    });
    expect(portfolioProjectCoverUri(library.projects[0])).toBeUndefined();

    mountedRef.current = false;
    await act(async () => renderer.unmount());
  });
});
