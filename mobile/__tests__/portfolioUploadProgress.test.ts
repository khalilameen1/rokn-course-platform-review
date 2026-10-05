import {createPortfolioUploadProgress} from '../src/services/portfolioUploadProgress';

describe('portfolio transfer progress projection', () => {
  it('weights a small image and a large video by payload, not file count', () => {
    const notify = jest.fn();
    const progress = createPortfolioUploadProgress([10, 90], notify);
    progress.transfer(0, {loaded: 20, total: 20, phase: 'saving'});
    expect(notify).toHaveBeenLastCalledWith({
      completed: 0,
      total: 2,
      percentage: 10,
      phase: 'saving',
    });
    progress.settle(0, true);
    progress.transfer(1, {loaded: 45, total: 90, phase: 'uploading'});
    expect(notify).toHaveBeenLastCalledWith({
      completed: 1,
      total: 2,
      percentage: 55,
      phase: 'uploading',
    });
    progress.transfer(1, {loaded: 90, total: 90, phase: 'saving'});
    expect(notify.mock.calls.at(-1)?.[0]).toMatchObject({
      completed: 1,
      phase: 'saving',
    });
    progress.settle(1, true);
    expect(notify).toHaveBeenLastCalledWith({
      completed: 2,
      total: 2,
      percentage: null,
      phase: 'finalizing',
    });
  });

  it('uses no fabricated percentage when the runtime lacks transport length', () => {
    const notify = jest.fn();
    const progress = createPortfolioUploadProgress([100], notify);
    progress.transfer(0, {loaded: 40, total: null, phase: 'uploading'});
    expect(notify.mock.calls.at(-1)?.[0].percentage).toBeNull();
    progress.transfer(0, {loaded: 50, total: 100, phase: 'uploading'});
    expect(notify.mock.calls.at(-1)?.[0].percentage).toBe(50);
  });

  it('does not invent retained bytes after a rejected chunk and retry', () => {
    const notify = jest.fn();
    const progress = createPortfolioUploadProgress([100], notify);
    progress.transfer(0, {loaded: 75, total: 100, phase: 'uploading'});
    progress.transfer(0, {loaded: 50, total: 100, phase: 'uploading'});
    expect(notify.mock.calls.at(-1)?.[0].percentage).toBe(50);
    progress.transfer(0, {loaded: Number.NaN, total: 100, phase: 'uploading'});
    expect(notify.mock.calls.at(-1)?.[0].percentage).toBe(50);
  });

  it('does not count discarded files as successfully uploaded and ignores late progress', () => {
    const notify = jest.fn();
    const progress = createPortfolioUploadProgress([50, 50], notify);
    progress.settle(0, false);
    progress.transfer(1, {loaded: 25, total: 50, phase: 'uploading'});
    expect(notify.mock.calls.at(-1)?.[0].percentage).toBeNull();
    progress.settle(1, true);
    expect(notify.mock.calls.at(-1)?.[0]).toMatchObject({
      completed: 1,
      total: 2,
      phase: 'finalizing',
      percentage: null,
    });
    const calls = notify.mock.calls.length;
    progress.transfer(1, {loaded: 50, total: 50, phase: 'saving'});
    expect(notify).toHaveBeenCalledTimes(calls);
  });
});
