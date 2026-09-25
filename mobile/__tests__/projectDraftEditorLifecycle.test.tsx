import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'learner-a', epoch: 1};
const mockLoad = jest.fn();
const mockSave = jest.fn(async (..._args: unknown[]) => undefined);
const mockClear = jest.fn(async (..._args: unknown[]) => undefined);
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
jest.mock('../src/services/projectSubmissionDraft', () => ({
  loadProjectSubmissionDraft: (...args: unknown[]) => mockLoad(...args),
  saveProjectSubmissionDraft: (...args: unknown[]) => mockSave(...args),
  clearProjectSubmissionDraft: (...args: unknown[]) => mockClear(...args),
}));

import {useProjectDraftEditor} from '../src/components/VideoPlayer/projectTransition/useProjectDraftEditor';
import type {ProjectStatus} from '../src/components/VideoPlayer/types';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('project draft editor owns persistence without submission or picker state', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let current: ReturnType<typeof useProjectDraftEditor>;
  function Harness({
    id = '41',
    status = 'draft',
    background = false,
  }: {
    id?: string;
    status?: ProjectStatus;
    background?: boolean;
  }) {
    current = useProjectDraftEditor({
      projectId: id,
      status,
      active: true,
      appIsActive: !background,
    });
    return null;
  }

  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'learner-a', epoch: 1};
    mockLoad.mockReset().mockResolvedValue(null);
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
  });

  it('flushes the latest outgoing snapshot without writing into a pending next editor', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    act(() => current.setNote('عملي قبل تغيير المشروع'));
    const previous = current.session;
    const previousSetNote = current.setNote;
    const previousSetFiles = current.setFiles;
    const loading = deferred<{
      files: never[];
      note: string;
      updatedAt: number;
    }>();
    mockLoad.mockReturnValueOnce(loading.promise);
    await act(async () => renderer!.update(<Harness id="42" background />));
    expect(current.ready).toBe(false);
    expect(mockSave).toHaveBeenCalledWith(
      '41',
      expect.objectContaining({note: 'عملي قبل تغيير المشروع'}),
      mockBoundary,
    );
    expect(mockSave.mock.calls.some(call => call[0] === '42')).toBe(false);
    await act(async () => {
      jest.advanceTimersByTime(300);
    });
    expect(mockSave.mock.calls.some(call => call[0] === '42')).toBe(false);
    await act(async () => {
      loading.resolve({
        files: [],
        note: 'مسودة المشروع الثاني',
        updatedAt: Date.now(),
      });
    });
    expect(current.note).toBe('مسودة المشروع الثاني');
    act(() => {
      previousSetNote('لا يخص المشروع الحالي');
      previousSetFiles([
        {
          uri: 'file:///old.pdf',
          name: 'old.pdf',
          type: 'application/pdf',
          size: 100,
        },
      ]);
    });
    expect(current.note).toBe('مسودة المشروع الثاني');
    expect(current.files).toEqual([]);
    await expect(previous.persist()).rejects.toThrow('PROJECT_DRAFT_NOT_READY');
    act(() => previous.consume('evaluating', []));
    expect(current.note).toBe('مسودة المشروع الثاني');
    expect(mockClear).not.toHaveBeenCalled();
  });

  it('does not hydrate or delete new work when the server reports an earlier accepted attempt', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    const session = current.session;
    act(() => current.setNote('تعديلات أحدث من رد المحاولة السابقة'));
    await act(async () => renderer!.update(<Harness status="passed" />));
    expect(current.session).toBe(session);
    expect(current.note).toBe('تعديلات أحدث من رد المحاولة السابقة');
    expect(mockLoad).toHaveBeenCalledTimes(1);
    expect(mockClear).not.toHaveBeenCalled();
  });

  it('consumes only a matching accepted editor and leaves an empty editable replacement', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    act(() => current.setNote('تسليم مكتمل'));
    await act(async () => current.session.persist());
    act(() => current.session.consume('evaluating', []));
    expect(current.note).toBe('');
    expect(current.ready).toBe(true);
    expect(mockClear).toHaveBeenCalledWith('41', [], mockBoundary);
    await act(async () => renderer!.update(<Harness status="needs_changes" />));
    act(() => current.setNote('المحاولة الجديدة بعد التقرير'));
    await act(async () => current.session.persist());
    expect(mockSave).toHaveBeenLastCalledWith(
      '41',
      expect.objectContaining({note: 'المحاولة الجديدة بعد التقرير'}),
      mockBoundary,
    );
    expect(mockLoad).toHaveBeenCalledTimes(1);
  });

  it('keeps the session owner pinned when an old caller runs after account replacement', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    act(() => current.setNote('شغل الحساب الأول'));
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await expect(current.session.persist()).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(() => current.session.consume('evaluating', [])).toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(current.note).toBe('شغل الحساب الأول');
    expect(mockSave).not.toHaveBeenCalled();
    expect(mockClear).not.toHaveBeenCalled();
  });
});
