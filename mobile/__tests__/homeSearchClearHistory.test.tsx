import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetSearchHistory = jest.fn();
const mockClearSearchHistory = jest.fn();
const mockRememberSearch = jest.fn();
jest.mock('../src/services/searchHistory', () => ({
  getSearchHistory: () => mockGetSearchHistory(),
  clearSearchHistory: () => mockClearSearchHistory(),
  rememberSearch: (query: string) => mockRememberSearch(query),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(async () => undefined),
}));

import {useHomeSearch} from '../src/screens/home/useHomeSearch';

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return {promise, resolve, reject};
}

describe('Home search history clearing', () => {
  let search!: ReturnType<typeof useHomeSearch>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let alertSpy: jest.SpyInstance;
  const Harness = ({identity}: {identity: string}) => {
    search = useHomeSearch(identity);
    return null;
  };
  const mount = async (identity = 'learner-7') => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness identity={identity} />);
    });
  };

  beforeEach(() => {
    mockGetSearchHistory.mockReset().mockResolvedValue(['تسويق', 'تصميم']);
    mockClearSearchHistory.mockReset().mockResolvedValue(undefined);
    mockRememberSearch
      .mockReset()
      .mockImplementation(async (query: string) => [query]);
    alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer?.unmount());
    renderer = undefined;
    alertSpy.mockRestore();
  });

  it('clears the visible history without a notice when storage succeeds', async () => {
    await mount();
    expect(search.history).toEqual(['تسويق', 'تصميم']);
    await act(async () => search.clearHistory());
    expect(search.history).toEqual([]);
    expect(alertSpy).not.toHaveBeenCalled();
  });

  it('restores the stored history and tells the learner when clearing fails', async () => {
    await mount();
    mockClearSearchHistory.mockRejectedValueOnce(new Error('STORAGE_FAILED'));
    await act(async () => search.clearHistory());
    expect(alertSpy).toHaveBeenCalledWith('متمسحش', 'جرب تاني');
    expect(search.history).toEqual(['تسويق', 'تصميم']);
  });

  it('leaves a newer search in charge of the history when an older clear fails', async () => {
    await mount();
    const clearing = deferred<void>();
    mockClearSearchHistory.mockReturnValueOnce(clearing.promise);
    await act(async () => search.clearHistory());
    await act(async () => search.commit('برمجة'));
    await act(async () => {
      clearing.reject(new Error('STORAGE_FAILED'));
    });
    expect(alertSpy).not.toHaveBeenCalled();
    expect(search.history).toEqual(['برمجة']);
  });

  it('does not alert after Home has left', async () => {
    await mount();
    const clearing = deferred<void>();
    mockClearSearchHistory.mockReturnValueOnce(clearing.promise);
    await act(async () => search.clearHistory());
    await act(async () => renderer?.unmount());
    renderer = undefined;
    await act(async () => {
      clearing.reject(new Error('STORAGE_FAILED'));
    });
    expect(alertSpy).not.toHaveBeenCalled();
  });
});
