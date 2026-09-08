import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockStorage = new Map<string, unknown>();
const mockGet = jest.fn();
const mockDelete = jest.fn();
const mockPost = jest.fn();
const mockSave = jest.fn();
let mockBoundary = {epoch: 1, scope: 'portfolio-owner'};

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (callback: () => void | (() => void)) => {
    const ReactModule = require('react');
    ReactModule.useEffect(callback, [callback]);
  },
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary !== mockBoundary) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
  getItem: async (key: string) => mockStorage.get(key) ?? null,
  saveItem: (...args: unknown[]) => mockSave(...args),
}));
jest.mock('../src/services/portfolioVideoUpload', () => ({
  uploadPortfolioVideo: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/portfolio'),
  hasSession: async () => true,
}));

import {
  deletePortfolioItem,
  deletePortfolioMedia,
  createPortfolioItem,
  updatePortfolioItem,
  finalizePortfolioItem,
  appendPortfolioMedia,
  getCachedPortfolio,
  getPortfolio,
} from '../src/services/api/portfolio';
import {usePortfolioLibrary} from '../src/screens/Profile/gallery/usePortfolioLibrary';

const item = {
  id: 7,
  title: 'العمل المنشور',
  description: '',
  media: [{id: 71, file_type: 'image', status: 'ready'}],
  upload_state: 'ready',
  uploaded_media_count: 1,
  expected_media_count: 1,
};
const listResponse = {data: {data: [item]}};
const captureBoundary = async () => mockBoundary;
const isLoadBlocked = () => false;
const mountedRef = {current: true};
let library!: ReturnType<typeof usePortfolioLibrary>;
const Harness = () => {
  library = usePortfolioLibrary({captureBoundary, isLoadBlocked, mountedRef});
  return null;
};

describe('portfolio confirmed mutations versus offline library', () => {
  beforeEach(() => {
    mockBoundary = {
      epoch: mockBoundary.epoch + 1,
      scope: `owner-${mockBoundary.epoch + 1}`,
    };
    mockStorage.clear();
    mockSave
      .mockReset()
      .mockImplementation(async (key: string, value: unknown) => {
        mockStorage.set(key, value);
        return true;
      });
    mockGet.mockReset().mockResolvedValue(listResponse);
    mockDelete.mockReset().mockResolvedValue({data: {success: true}});
    mockPost.mockReset().mockResolvedValue({data: {data: item}});
  });

  it('does not redisplay a deleted published work after reopening offline', async () => {
    await getPortfolio(mockBoundary);
    await deletePortfolioItem('7', mockBoundary);
    mockGet.mockRejectedValue(new Error('offline'));

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    try {
      expect(library.loadError).not.toBe('');
      expect(library.projects).toEqual([]);
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it('does not let a pre-delete list response restore the deleted cached work', async () => {
    let resolve!: (value: typeof listResponse) => void;
    mockGet.mockReturnValueOnce(
      new Promise<typeof listResponse>(done => {
        resolve = done;
      }),
    );
    const oldRead = getPortfolio(mockBoundary);
    await Promise.resolve();
    await deletePortfolioItem('7', mockBoundary);
    mockGet.mockResolvedValue({data: {data: []}});
    resolve(listResponse);
    expect(await oldRead).toEqual([]);
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(await getCachedPortfolio(mockBoundary)).toEqual([]);
  });

  it('retains the previous cache when deletion is rejected', async () => {
    await getPortfolio(mockBoundary);
    mockDelete.mockRejectedValue(new Error('offline'));
    await expect(deletePortfolioItem('7', mockBoundary)).rejects.toThrow(
      'offline',
    );
    expect(
      (await getCachedPortfolio(mockBoundary)).map(work => work.id),
    ).toEqual(['7']);
  });

  it.each(['edit', 'create', 'publish', 'append image', 'remove media'])(
    'does not reuse old publication metadata after acknowledged %s',
    async operation => {
      await getPortfolio(mockBoundary);
      expect(await getCachedPortfolio(mockBoundary)).toHaveLength(1);
      if (operation === 'edit') {
        await updatePortfolioItem(
          '7',
          {title: 'عمل جديد', summary: ''},
          mockBoundary,
        );
      } else if (operation === 'create') {
        await createPortfolioItem(
          {
            title: 'عمل جديد',
            summary: '',
            clientRequestId: 'create',
            expectedMediaCount: 1,
          },
          mockBoundary,
        );
      } else if (operation === 'publish') {
        await finalizePortfolioItem('7', mockBoundary);
      } else if (operation === 'append image') {
        mockPost.mockResolvedValue({data: {data: item.media[0]}});
        await appendPortfolioMedia(
          '7',
          {uri: 'file:///image.jpg', type: 'image/jpeg'},
          'upload',
          mockBoundary,
        );
      } else {
        await deletePortfolioMedia('7', '71', mockBoundary);
      }
      expect(await getCachedPortfolio(mockBoundary)).toEqual([]);
      const currentItem = {
        ...item,
        title: 'العنوان الحالي',
        upload_state: 'uploading',
      };
      mockGet.mockResolvedValue({data: {data: [currentItem]}});
      await getPortfolio(mockBoundary);
      expect(await getCachedPortfolio(mockBoundary)).toEqual([
        expect.objectContaining({
          title: 'العنوان الحالي',
          publicationState: 'uploading',
        }),
      ]);
    },
  );

  it('rejects an old account read without retrying it under the replacement account', async () => {
    let resolve!: (value: typeof listResponse) => void;
    mockGet.mockReturnValueOnce(
      new Promise<typeof listResponse>(done => {
        resolve = done;
      }),
    );
    const oldRead = getPortfolio(mockBoundary);
    await Promise.resolve();
    mockBoundary = {epoch: mockBoundary.epoch + 1, scope: 'replacement-owner'};
    resolve(listResponse);
    await expect(oldRead).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockGet).toHaveBeenCalledTimes(1);
    expect(await getCachedPortfolio(mockBoundary)).toEqual([]);
  });

  it('bounds offline reads and keeps a successful delete settled while a native cache write hangs', async () => {
    jest.useFakeTimers();
    let release!: () => void;
    const blockedWrite = new Promise<void>(resolve => {
      release = resolve;
    });
    mockSave.mockImplementationOnce(async (key: string, value: unknown) => {
      await blockedWrite;
      mockStorage.set(key, value);
      return true;
    });
    try {
      await getPortfolio(mockBoundary);
      await Promise.resolve();
      await deletePortfolioItem('7', mockBoundary);
      const offline = getCachedPortfolio(mockBoundary);
      await jest.advanceTimersByTimeAsync(751);
      expect(await offline).toEqual([]);
      release();
      expect(await getCachedPortfolio(mockBoundary)).toEqual([]);
      expect([...mockStorage.values()]).toEqual([{version: 1, items: []}]);
    } finally {
      release();
      jest.useRealTimers();
    }
  });
});
