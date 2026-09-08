import React from 'react';
import {Image, Pressable} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetPortfolioItem = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  getPortfolioItem: (...args: unknown[]) => mockGetPortfolioItem(...args),
}));

import type {PortfolioItem, PortfolioMedia} from '../src/services/roknApi';
import {toPortfolioProject} from '../src/screens/Profile/gallery/portfolioModel';
import {usePortfolioProjectSelection} from '../src/screens/Profile/gallery/usePortfolioProjectSelection';

const media = (id: string, revision = 'old'): PortfolioMedia => ({
  id,
  type: 'image',
  status: 'ready',
  uri: `https://cdn.example/${id}-${revision}.jpg`,
});
const item = (
  id = 'work',
  files = [media('first'), media('second')],
): PortfolioItem => ({
  id,
  title: id,
  summary: '',
  featured: false,
  skills: [],
  media: files,
  publicationState: 'published',
  uploadedMediaCount: files.length,
  expectedMediaCount: files.length,
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const settle = async (action: () => void) => {
  await act(async () => {
    action();
    await Promise.resolve();
    await Promise.resolve();
  });
};
const mount = async () => {
  let owner!: ReturnType<typeof usePortfolioProjectSelection>;
  let renderer!: TestRenderer.ReactTestRenderer;
  const mountedRef = {current: true};
  const mutationFlightRef = {current: null as symbol | null};
  const library = jest.fn();
  const Harness = () => {
    owner = usePortfolioProjectSelection({
      captureBoundary: async () => ({epoch: 1, scope: 'student'}),
      isCreateBusy: () => false,
      mountedRef,
      mutationFlightRef,
      setLibraryProjects: library,
    });
    return (
      <>
        <Image testID="preview" source={{uri: owner.previewMedia?.uri}} />
        {owner.selected?.media.map(file => (
          <Pressable
            key={file.id}
            testID={file.id}
            onPress={() => owner.selectPreviewMedia(file)}
          />
        ))}
      </>
    );
  };
  await settle(() => {
    renderer = TestRenderer.create(<Harness />);
  });
  return {
    get owner() {
      return owner;
    },
    get uri() {
      return renderer.root.findByProps({testID: 'preview'}).props.source.uri;
    },
    select: (id: string) =>
      settle(() => renderer.root.findByProps({testID: id}).props.onPress()),
    press: (id: string) =>
      renderer.root.findByProps({testID: id}).props.onPress(),
    close: () =>
      settle(() => {
        mountedRef.current = false;
        renderer.unmount();
      }),
    library,
  };
};

describe('portfolio preview choice while a remote snapshot arrives', () => {
  beforeEach(() => jest.clearAllMocks());

  it.each(['open', 'refresh'] as const)(
    '%s keeps the newly selected image and renews its URL without another request',
    async path => {
      const pending = deferred<PortfolioItem>();
      mockGetPortfolioItem.mockResolvedValue(item());
      const view = await mount();
      if (path === 'open')
        mockGetPortfolioItem.mockReturnValue(pending.promise);
      await settle(() => view.owner.openSelection(toPortfolioProject(item())));
      if (path === 'refresh') {
        mockGetPortfolioItem.mockReturnValue(pending.promise);
        await settle(() => {
          void view.owner.refreshOpenProject(true);
        });
      }
      await view.select('second');
      expect(view.uri).toBe(media('second').uri);
      const callsBeforeDelivery = mockGetPortfolioItem.mock.calls.length;
      await settle(() =>
        pending.resolve(
          item('work', [media('first', 'new'), media('second', 'new')]),
        ),
      );
      expect(view.uri).toBe(media('second', 'new').uri);
      expect(view.owner.detailLoading).toBe(false);
      expect(mockGetPortfolioItem).toHaveBeenCalledTimes(callsBeforeDelivery);
      await view.close();
    },
  );

  it.each(['open', 'refresh'] as const)(
    '%s preserves a choice made in the same render batch as the response',
    async path => {
      const pending = deferred<PortfolioItem>();
      mockGetPortfolioItem.mockResolvedValue(item());
      const view = await mount();
      if (path === 'open')
        mockGetPortfolioItem.mockReturnValue(pending.promise);
      await settle(() => view.owner.openSelection(toPortfolioProject(item())));
      if (path === 'refresh') {
        mockGetPortfolioItem.mockReturnValue(pending.promise);
        await settle(() => {
          void view.owner.refreshOpenProject(true);
        });
      }
      await settle(() => {
        view.press('second');
        pending.resolve(
          item('work', [media('first', 'new'), media('second', 'new')]),
        );
      });
      expect(view.uri).toBe(media('second', 'new').uri);
      await view.close();
    },
  );

  it.each(['removed', 'unavailable', 'all removed'])(
    'uses a valid fallback when the selected image is %s on the server',
    async disposition => {
      mockGetPortfolioItem.mockResolvedValue(item());
      const view = await mount();
      await settle(() => view.owner.openSelection(toPortfolioProject(item())));
      await view.select('second');
      const pending = deferred<PortfolioItem>();
      mockGetPortfolioItem.mockReturnValue(pending.promise);
      await settle(() => {
        void view.owner.refreshOpenProject(true);
      });
      const remaining =
        disposition === 'all removed' ? [] : [media('first', 'new')];
      if (disposition === 'unavailable')
        remaining.push({...media('second'), uri: ''});
      await settle(() => pending.resolve(item('work', remaining)));
      expect(view.uri).toBe(remaining[0]?.uri);
      await view.close();
    },
  );

  it('does not replace another work with a late refresh from the previous one', async () => {
    mockGetPortfolioItem.mockResolvedValue(item());
    const view = await mount();
    await settle(() => view.owner.openSelection(toPortfolioProject(item())));
    const pending = deferred<PortfolioItem>();
    mockGetPortfolioItem.mockReturnValueOnce(pending.promise);
    await settle(() => {
      void view.owner.refreshOpenProject(true);
    });
    const next = item('other', [media('other-image')]);
    mockGetPortfolioItem.mockResolvedValue(next);
    await settle(() => view.owner.openSelection(toPortfolioProject(next)));
    await settle(() => pending.resolve(item('work', [media('first', 'new')])));
    expect(view.owner.selected?.id).toBe('other');
    expect(view.uri).toBe(media('other-image').uri);
    await view.close();
  });

  it('retains the selected image after a failed refresh and permits an explicit retry', async () => {
    mockGetPortfolioItem.mockResolvedValue(item());
    const view = await mount();
    await settle(() => view.owner.openSelection(toPortfolioProject(item())));
    const pending = deferred<PortfolioItem>();
    mockGetPortfolioItem.mockReturnValueOnce(pending.promise);
    await settle(() => {
      void view.owner.refreshOpenProject(true);
    });
    await view.select('second');
    await settle(() => pending.reject(new Error('offline')));
    expect(view.uri).toBe(media('second').uri);
    mockGetPortfolioItem.mockResolvedValue(
      item('work', [media('first', 'new'), media('second', 'new')]),
    );
    await settle(() => {
      void view.owner.refreshOpenProject(true);
    });
    expect(view.uri).toBe(media('second', 'new').uri);
    await view.close();
  });
});
