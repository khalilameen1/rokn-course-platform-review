import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

let mockBoundary = {scope: 'user-7', epoch: 1};
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: async () => true,
  removeLearnerDraftFile: async () => undefined,
}));
jest.mock('../src/screens/feedback/pickFeedbackScreenshot', () => ({
  pickFeedbackScreenshot: jest.fn(),
}));

import {publicRequest} from '../src/constants/api';
import {loadProductFeedbackCases} from '../src/services/productFeedback';
import {useFeedbackCases} from '../src/screens/feedback/useFeedbackCases';

const report = (index: number) => ({
  public_id: `01ARZ3NDEKTSV4RRFF${String(index).padStart(8, '0')}`,
  case_number: String(index).padStart(8, '0'),
  category: 'bug',
  status: 'in_progress',
  message: `بلاغ ${index}`,
  created_at: '2026-09-09T12:00:00Z',
  updated_at: new Date(Date.UTC(2026, 8, 9, 12) - index * 1000).toISOString(),
  attachments: [],
  messages: [],
});
const page = (
  current: number,
  last: number,
  items: ReturnType<typeof report>[],
) => ({
  data: {
    data: {
      items,
      pagination: {
        current_page: current,
        last_page: last,
        has_more: current < last,
      },
    },
  },
});
const firstPage = Array.from({length: 20}, (_, index) => report(index + 1));
const older = report(21);
const requestedPage = (config: Parameters<typeof publicRequest.get>[1]) => {
  const params = config?.params as {page?: number} | undefined;
  return params?.page || 1;
};
const flush = async () => {
  for (let index = 0; index < 100; index += 1) await Promise.resolve();
};

describe('support history across account index pages', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-7', epoch: 1};
    await AsyncStorage.clear();
  });

  it('opens the requested older case on a device with no local receipts', async () => {
    jest
      .mocked(publicRequest.get)
      .mockImplementation(async (_url, config) =>
        requestedPage(config) === 1
          ? page(1, 2, firstPage)
          : page(2, 2, [older]),
      );
    let cases!: ReturnType<typeof useFeedbackCases>;
    const Harness = () => {
      cases = useFeedbackCases('user-7', older.public_id);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
        await flush();
      });
      expect(cases.casesBusy).toBe(false);
      expect(cases.supportCases).toHaveLength(21);
      expect(cases.selectedCase?.publicId).toBe(older.public_id);
      expect(cases.replyReady).toBe(true);
      expect(publicRequest.get).toHaveBeenCalledTimes(2);
    } finally {
      await act(async () => {
        renderer?.unmount();
      });
    }
  });

  it('rejects a partial history when a later page fails', async () => {
    jest.mocked(publicRequest.get).mockImplementation(async (_url, config) => {
      if (requestedPage(config) === 2) throw new Error('network lost');
      return page(1, 2, firstPage);
    });
    await expect(loadProductFeedbackCases()).rejects.toThrow('network lost');
  });

  it('keeps the selected history visible until a failed refresh can complete', async () => {
    let failLaterPage = false;
    jest.mocked(publicRequest.get).mockImplementation(async (_url, config) => {
      if (requestedPage(config) === 1) return page(1, 2, firstPage);
      if (failLaterPage) throw new Error('network lost');
      return page(2, 2, [older]);
    });
    let cases!: ReturnType<typeof useFeedbackCases>;
    const Harness = () => {
      cases = useFeedbackCases('user-7', older.public_id);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
        await flush();
      });
      failLaterPage = true;
      await act(async () => {
        await cases.reloadCases();
      });
      expect(cases.supportCases).toHaveLength(21);
      expect(cases.selectedCase?.publicId).toBe(older.public_id);
      expect(cases.casesError).toBe('تعذّر تحديث الحالات الآن');
      expect(cases.casesBusy).toBe(false);
      failLaterPage = false;
      await act(async () => {
        await cases.reloadCases();
      });
      expect(cases.casesError).toBe('');
      expect(cases.supportCases).toHaveLength(21);
    } finally {
      await act(async () => {
        renderer?.unmount();
      });
    }
  });

  it.each(['wrong_page', 'repeated_case', 'empty_more', 'missing_pagination'])(
    'rejects %s instead of adopting a partial or repeated page',
    async problem => {
      jest
        .mocked(publicRequest.get)
        .mockImplementation(async (_url, config) => {
          if (requestedPage(config) === 1) return page(1, 2, firstPage);
          if (problem === 'wrong_page') return page(1, 2, [older]);
          if (problem === 'repeated_case') return page(2, 2, [firstPage[0]]);
          if (problem === 'empty_more') return page(2, 3, []);
          return {data: {data: {items: [older]}}};
        });
      await expect(loadProductFeedbackCases()).rejects.toThrow(
        problem === 'repeated_case'
          ? 'SUPPORT_CASES_CHANGED_DURING_READ'
          : 'INVALID_SUPPORT_CASES_PAGINATION',
      );
      expect(publicRequest.get).toHaveBeenCalledTimes(2);
    },
  );

  it('does not read another page or adopt the old account after replacement', async () => {
    jest.mocked(publicRequest.get).mockImplementation(async (_url, config) => {
      if (requestedPage(config) === 1) return page(1, 3, firstPage);
      mockBoundary = {scope: 'user-8', epoch: 2};
      return page(2, 3, [older]);
    });
    await expect(loadProductFeedbackCases()).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(publicRequest.get).toHaveBeenCalledTimes(2);
  });

  it('accepts an empty final page after the server list shrinks', async () => {
    jest
      .mocked(publicRequest.get)
      .mockImplementation(async (_url, config) =>
        requestedPage(config) === 1 ? page(1, 2, firstPage) : page(2, 1, []),
      );
    await expect(loadProductFeedbackCases()).resolves.toHaveLength(20);
    expect(publicRequest.get).toHaveBeenCalledTimes(2);
  });

  it('returns an empty account history without requesting another page', async () => {
    jest.mocked(publicRequest.get).mockResolvedValue(page(1, 1, []));
    await expect(loadProductFeedbackCases()).resolves.toEqual([]);
    expect(publicRequest.get).toHaveBeenCalledTimes(1);
  });

  it('does not request an individually remembered case already present on page two', async () => {
    await AsyncStorage.setItem(
      '@rokn/product-feedback-receipts/v1:user-7',
      JSON.stringify([{publicId: older.public_id, updatedAt: Date.now()}]),
    );
    jest
      .mocked(publicRequest.get)
      .mockImplementation(async (_url, config) =>
        requestedPage(config) === 1
          ? page(1, 2, firstPage)
          : page(2, 2, [older]),
      );
    await expect(loadProductFeedbackCases()).resolves.toHaveLength(21);
    expect(publicRequest.get).toHaveBeenCalledTimes(2);
    expect(publicRequest.get).not.toHaveBeenCalledWith(
      `feedback/${older.public_id}`,
      expect.anything(),
    );
  });
});
