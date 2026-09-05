import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetPortfolioItem = jest.fn();

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  getPortfolioItem: (...args: unknown[]) => mockGetPortfolioItem(...args),
}));

import {usePortfolioProjectSelection} from '../src/screens/Profile/gallery/usePortfolioProjectSelection';
import type {Project} from '../src/screens/Profile/gallery/portfolioModel';

const boundary = {epoch: 1, scope: 'account-a'};
const image = {
  id: '71',
  type: 'image' as const,
  uri: 'https://cdn.example/expired.jpg',
  status: 'ready' as const,
};
const project: Project = {
  id: '9',
  title: 'مشروع',
  summary: '',
  cover: {uri: image.uri},
  skills: [],
  source: 'remote',
  media: [image],
  shareReady: true,
  uploadedMediaCount: 1,
};
const itemWithUri = (uri: string) => ({
  id: '9',
  title: 'مشروع',
  summary: '',
  skills: [],
  featured: false,
  media: [{...image, uri, urlExpiresAt: '2099-01-01T00:00:00.000Z'}],
  publicationState: 'published' as const,
  uploadedMediaCount: 1,
  expectedMediaCount: 1,
});
const freshImage = {...image, uri: 'https://cdn.example/fresh.jpg'};

const settle = async (action: () => void) => {
  await act(async () => {
    action();
    await Promise.resolve();
    await Promise.resolve();
  });
};

const mountProject = async () => {
  const mountedRef = {current: true};
  const mutationFlightRef = {current: null as symbol | null};
  const setLibraryProjects = jest.fn();
  let owner!: ReturnType<typeof usePortfolioProjectSelection>;
  const Harness = () => {
    owner = usePortfolioProjectSelection({
      captureBoundary: async () => boundary,
      isCreateBusy: () => false,
      mountedRef,
      mutationFlightRef,
      setLibraryProjects,
    });
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  mockGetPortfolioItem.mockResolvedValue(itemWithUri(image.uri));
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
  });
  await settle(() => owner.openSelection(project));
  mockGetPortfolioItem.mockClear();
  mockGetPortfolioItem.mockResolvedValue(itemWithUri(freshImage.uri));
  return {
    get owner() {
      return owner;
    },
    mutationFlightRef,
    close: () => settle(() => renderer.unmount()),
  };
};

describe('portfolio signed media recovery', () => {
  beforeEach(() => jest.clearAllMocks());

  it('coalesces failures and stops when a new URL also fails to display', async () => {
    const view = await mountProject();
    await settle(() => {
      view.owner.handleMediaDeliveryError(image);
      view.owner.handleMediaDeliveryError(image);
    });
    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(1);
    expect(mockGetPortfolioItem).toHaveBeenCalledWith('9', boundary);
    expect(view.owner.previewMedia?.uri).toBe(freshImage.uri);

    await settle(() => view.owner.handleMediaDeliveryError(freshImage));
    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(1);
    await view.close();
  });

  it('allows a later expiry only after the renewed media actually displayed', async () => {
    const view = await mountProject();
    await settle(() => view.owner.handleMediaDeliveryError(image));
    await settle(() => view.owner.handleMediaDeliverySuccess(freshImage));
    const laterUri = 'https://cdn.example/later.jpg';
    mockGetPortfolioItem.mockResolvedValue(itemWithUri(laterUri));
    await settle(() => view.owner.handleMediaDeliveryError(freshImage));

    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(2);
    expect(view.owner.previewMedia?.uri).toBe(laterUri);
    await view.close();
  });

  it('ignores late success and error callbacks belonging to an older URL', async () => {
    const view = await mountProject();
    await settle(() => view.owner.handleMediaDeliveryError(image));
    await settle(() => {
      view.owner.handleMediaDeliverySuccess(image);
      view.owner.handleMediaDeliveryError(image);
      view.owner.handleMediaDeliveryError(freshImage);
    });
    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(1);
    expect(view.owner.previewMedia?.uri).toBe(freshImage.uri);
    await view.close();
  });

  it('does not spend a recovery attempt while a mutation blocks refresh', async () => {
    const view = await mountProject();
    view.mutationFlightRef.current = Symbol('upload');
    await settle(() => view.owner.handleMediaDeliveryError(image));
    expect(mockGetPortfolioItem).not.toHaveBeenCalled();
    view.mutationFlightRef.current = null;
    await settle(() => view.owner.handleMediaDeliveryError(image));
    expect(mockGetPortfolioItem).toHaveBeenCalledTimes(1);
    await view.close();
  });
});
