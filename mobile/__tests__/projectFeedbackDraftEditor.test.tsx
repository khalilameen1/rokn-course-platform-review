import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'learner-a', epoch: 1};
const mockLoad = jest.fn();
const mockSave = jest.fn();
const mockClear = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/projectFeedbackDraft', () => ({
  loadProjectFeedbackDraft: (...args: unknown[]) => mockLoad(...args),
  saveProjectFeedbackDraft: (...args: unknown[]) => mockSave(...args),
  clearProjectFeedbackDraft: (...args: unknown[]) => mockClear(...args),
}));

import {useProjectFeedbackDraftEditor} from '../src/components/VideoPlayer/projectTransition/useProjectFeedbackDraftEditor';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const staged = {
  text: 'سؤال محفوظ',
  attachments: [],
  requestId: 'request-1',
  fingerprint: 'fingerprint-1',
};

describe('feedback draft visit ownership and durable status', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let current: ReturnType<typeof useProjectFeedbackDraftEditor>;
  function Harness({
    id = '41',
    background = false,
  }: {
    id?: string;
    background?: boolean;
  }) {
    current = useProjectFeedbackDraftEditor({
      projectId: id,
      threadId: `thread-${id}`,
      active: true,
      appIsActive: !background,
    });
    return null;
  }
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  beforeEach(() => {
    jest.useFakeTimers();
    mockBoundary = {scope: 'learner-a', epoch: 1};
    mockLoad.mockReset().mockResolvedValue(null);
    mockSave.mockReset().mockResolvedValue(undefined);
    mockClear.mockReset().mockResolvedValue(undefined);
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('flushes the departing visit and never writes it into an unread next thread', async () => {
    await mount();
    act(() => current.setDraft('مسودة المشروع الأول'));
    const previous = current;
    const reading = deferred<typeof staged>();
    mockLoad.mockReturnValueOnce(reading.promise);
    await act(async () => renderer!.update(<Harness id="42" background />));
    expect(current.ready).toBe(false);
    expect(current.draft).toBe('');
    expect(mockSave).toHaveBeenCalledWith(
      'thread-41',
      expect.objectContaining({text: 'مسودة المشروع الأول'}),
      mockBoundary,
    );
    await act(async () => jest.advanceTimersByTime(500));
    expect(mockSave.mock.calls.some(call => call[0] === 'thread-42')).toBe(
      false,
    );
    await act(async () => reading.resolve(staged));
    expect(current.draft).toBe(staged.text);
    act(() => {
      previous.setDraft('رد قديم');
      previous.setAttachments([]);
    });
    await expect(previous.session.stage(staged)).rejects.toThrow(
      'PROJECT_FEEDBACK_DRAFT_NOT_READY',
    );
    expect(() => previous.session.consume('request-1', [])).toThrow(
      'PROJECT_FEEDBACK_DRAFT_NOT_READY',
    );
    expect(current.draft).toBe(staged.text);
    expect(mockClear).not.toHaveBeenCalled();
  });

  it('reports a failed autosave, retains the text and retries without restoring over it', async () => {
    await mount();
    mockSave.mockRejectedValueOnce(new Error('storage full'));
    act(() => current.setDraft('لا تفقد سؤالي'));
    await act(async () => jest.advanceTimersByTime(250));
    expect(current.saveError).toBe(true);
    expect(current.draft).toBe('لا تفقد سؤالي');
    await act(async () => current.retrySave());
    expect(current.saveError).toBe(false);
    expect(mockLoad).toHaveBeenCalledTimes(1);
    expect(mockSave).toHaveBeenLastCalledWith(
      'thread-41',
      expect.objectContaining({text: 'لا تفقد سؤالي'}),
      mockBoundary,
    );
  });

  it('reports background failure without accepting an older save as proof of persistence', async () => {
    await mount();
    const oldWrite = deferred<void>();
    mockSave.mockReturnValueOnce(oldWrite.promise);
    act(() => current.setDraft('نسخة أولى'));
    await act(async () => jest.advanceTimersByTime(250));
    act(() => current.setDraft('نسخة أحدث'));
    mockSave.mockRejectedValueOnce(new Error('storage full'));
    await act(async () => renderer!.update(<Harness background />));
    expect(current.saveError).toBe(true);
    await act(async () => oldWrite.resolve());
    expect(current.saveError).toBe(true);
    expect(current.draft).toBe('نسخة أحدث');
  });

  it('keeps late save failure attached to its old visit', async () => {
    await mount();
    const oldWrite = deferred<void>();
    mockSave.mockReturnValueOnce(oldWrite.promise);
    act(() => current.setDraft('مسودة أولى'));
    await act(async () => jest.advanceTimersByTime(250));
    await act(async () => renderer!.update(<Harness id="42" />));
    await act(async () => oldWrite.reject(new Error('disk full')));
    expect(current.saveError).toBe(false);
    expect(current.draft).toBe('');
  });

  it('cannot edit, stage or consume after account replacement', async () => {
    await mount();
    await act(async () => current.session.stage(staged));
    mockSave.mockClear();
    mockBoundary = {scope: 'learner-b', epoch: 2};
    act(() => {
      current.setDraft('شخص آخر');
      current.setAttachments([]);
    });
    await expect(current.session.stage(staged)).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(() => current.session.consume(staged.requestId, [])).toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(current.draft).toBe(staged.text);
    expect(mockSave).not.toHaveBeenCalled();
    expect(mockClear).not.toHaveBeenCalled();
  });

  it('consumes a matching receipt once and never consumes a subsequent edit with that receipt', async () => {
    await mount();
    await act(async () => current.session.stage(staged));
    act(() => current.setDraft('سؤال جديد بعد المحاولة'));
    act(() => current.session.consume(staged.requestId, []));
    expect(current.draft).toBe('سؤال جديد بعد المحاولة');
    expect(mockClear).not.toHaveBeenCalled();
    await act(async () => current.session.stage(staged));
    await act(async () => current.session.consume(staged.requestId, []));
    act(() => current.session.consume(staged.requestId, []));
    expect(current.draft).toBe('');
    expect(current.ready).toBe(true);
    expect(mockClear).toHaveBeenCalledTimes(1);
  });

  it('keeps staged request identity available after failed mandatory persistence', async () => {
    await mount();
    mockSave.mockRejectedValueOnce(new Error('storage full'));
    await act(async () => {
      await expect(current.session.stage(staged)).rejects.toThrow(
        'storage full',
      );
    });
    expect(current.saveError).toBe(true);
    expect(current.session.snapshot.requestId).toBe(staged.requestId);
    await act(async () => current.retrySave());
    expect(current.saveError).toBe(false);
    expect(mockSave).toHaveBeenLastCalledWith(
      'thread-41',
      expect.objectContaining(staged),
      mockBoundary,
    );
  });
});
