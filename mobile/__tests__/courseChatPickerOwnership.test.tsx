import React, {useCallback, useEffect, useRef, useState} from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import * as DocumentPicker from 'expo-document-picker';
import type {Dispatch, SetStateAction} from 'react';

let mockBoundary = {scope: 'user-a', epoch: 1};
const mockCapture = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: () => mockCapture(),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary.scope !== mockBoundary.scope || boundary.epoch !== mockBoundary.epoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: jest.fn(),
  removeLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/services/mediaPickerErrors', () => ({showMediaPickerFailure: jest.fn()}));
jest.mock('../src/services/operationalTelemetry', () => ({reportClientError: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({secureRandomUuid: jest.fn()}));

import {useCourseChatAttachments} from '../src/components/VideoPlayer/courseChat/useCourseChatAttachments';
import {cacheLearnerDraftFile, removeLearnerDraftFile} from '../src/services/learnerDraftFiles';
import {showMediaPickerFailure} from '../src/services/mediaPickerErrors';
import {reportClientError} from '../src/services/operationalTelemetry';
import {secureRandomUuid} from '../src/utils/secureRandom';
import type {ChatAttachmentDraft} from '../src/components/VideoPlayer/types';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {resolve = done;});
  return {promise, resolve};
};
const selection = (name = 'work.pdf'): DocumentPicker.DocumentPickerResult => ({
  canceled: false,
  assets: [{uri: `file:///picker/${name}`, name, mimeType: 'application/pdf', size: 100, lastModified: 1}],
});
type Props = {
  scope?: string; visible?: boolean; appIsActive?: boolean;
  enabled?: boolean; limit?: number; sending?: boolean;
};

describe('course chat native picker belongs to one conversation visit', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let picker: ReturnType<typeof useCourseChatAttachments>;
  let files: ChatAttachmentDraft[];
  let commitFiles: Dispatch<SetStateAction<ChatAttachmentDraft[]>>;
  let sendInFlight = false;
  function Harness({
    scope = 'user-a:3:lesson-1', visible = true, appIsActive = true,
    enabled = true, limit = 3, sending = false,
  }: Props) {
    const filesRef = useRef<ChatAttachmentDraft[]>([]);
    const [attachments, setAttachments] = useState<ChatAttachmentDraft[]>([]);
    // Match the real conversation owner's synchronous commit, not a fake
    // delayed React updater that has no account/conversation ownership.
    commitFiles = useCallback(update => {
      const next = typeof update === 'function' ? update(filesRef.current) : update;
      filesRef.current = next;
      setAttachments(next);
    }, []);
    useEffect(() => commitFiles([]), [scope]);
    files = attachments;
    picker = useCourseChatAttachments({
      appIsActive, attachmentsRef: filesRef, conversationScope: scope, enabled, limit,
      sending, isSendInFlight: () => sendInFlight, setAttachments: commitFiles, visible,
    });
    return null;
  }
  const mount = async (props: Props = {}) => {
    await act(async () => {renderer = TestRenderer.create(<Harness {...props} />);});
  };
  const update = async (props: Props) => {
    await act(async () => {renderer!.update(<Harness {...props} />);});
  };
  const depart = async (kind: string) => {
    if (kind === 'unmount') await act(async () => {renderer!.unmount();});
    else if (kind === 'lesson') await update({scope: 'user-a:3:lesson-2'});
    else if (kind === 'course') await update({scope: 'user-a:4:lesson-1'});
    else if (kind === 'account') {
      mockBoundary = {scope: 'user-b', epoch: 2};
      await update({scope: 'user-b:3:lesson-1'});
    } else if (kind === 'disabled') await update({enabled: false});
    else if (kind === 'limit changed') await update({limit: 1});
    else {
      await update({visible: false});
      if (kind === 'close and reopen') await update({visible: true});
    }
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-a', epoch: 1};
    sendInFlight = false;
    mockCapture.mockReset().mockImplementation(async () => ({...mockBoundary}));
    jest.mocked(DocumentPicker.getDocumentAsync).mockReset().mockResolvedValue(selection());
    jest.mocked(cacheLearnerDraftFile).mockReset().mockImplementation(async (_kind, source) => ({
      ...source, uri: `file:///managed/${source.fileName}`,
    }));
    jest.mocked(removeLearnerDraftFile).mockReset().mockResolvedValue(undefined);
    let id = 0;
    jest.mocked(secureRandomUuid).mockReset().mockImplementation(() => `draft-${++id}`);
  });
  afterEach(async () => {
    await act(async () => {renderer?.unmount();});
    renderer = undefined;
  });

  it.each(['unmount', 'lesson', 'course', 'account', 'disabled', 'close', 'close and reopen'])(
    'does not launch the native picker after %s during account capture', async kind => {
      await mount();
      const capture = deferred<typeof mockBoundary>();
      mockCapture.mockReturnValueOnce(capture.promise);
      let picking!: Promise<void>;
      await act(async () => {picking = picker.pickAttachments();});
      await depart(kind);
      await act(async () => {capture.resolve({scope: 'user-a', epoch: 1}); await picking;});
      expect(DocumentPicker.getDocumentAsync).not.toHaveBeenCalled();
      expect(cacheLearnerDraftFile).not.toHaveBeenCalled();
      expect(showMediaPickerFailure).not.toHaveBeenCalled();
    },
  );

  it('retires pre-launch preparation on background without reviving it on return', async () => {
    await mount();
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    let oldPicking!: Promise<void>;
    await act(async () => {oldPicking = picker.pickAttachments();});
    await update({appIsActive: false});
    await update({appIsActive: true});
    await act(async () => {await picker.pickAttachments();});
    await act(async () => {capture.resolve({...mockBoundary}); await oldPicking;});
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(1);
    expect(files).toHaveLength(1);
  });

  it.each(['unmount', 'lesson', 'course', 'account', 'disabled', 'limit changed', 'close and reopen'])(
    'discards the native selection after %s without copying into another composer', async kind => {
      await mount();
      const native = deferred<DocumentPicker.DocumentPickerResult>();
      jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(native.promise);
      let picking!: Promise<void>;
      await act(async () => {picking = picker.pickAttachments();});
      await depart(kind);
      await act(async () => {native.resolve(selection()); await picking;});
      expect(cacheLearnerDraftFile).not.toHaveBeenCalled();
      expect(files).toEqual([]);
      expect(showMediaPickerFailure).not.toHaveBeenCalled();
    },
  );

  it.each(['lesson', 'account', 'close and reopen', 'unmount'])(
    'cleans a completed managed copy made obsolete by %s', async kind => {
      await mount();
      const cache = deferred<Awaited<ReturnType<typeof cacheLearnerDraftFile>>>();
      jest.mocked(cacheLearnerDraftFile).mockReturnValueOnce(cache.promise);
      let picking!: Promise<void>;
      await act(async () => {picking = picker.pickAttachments();});
      await depart(kind);
      await act(async () => {
        cache.resolve({uri: 'file:///managed/old.pdf', fileName: 'old.pdf', type: 'application/pdf', size: 100});
        await picking;
      });
      expect(files).toEqual([]);
      // Array.map supplies index/list as well; the unary file owner consumes
      // only the file. Assert its exact cleanup batch, not callback arity.
      expect(jest.mocked(removeLearnerDraftFile).mock.calls.map(([file]) => file))
        .toEqual([expect.objectContaining({uri: 'file:///managed/old.pdf'})]);
      expect(showMediaPickerFailure).not.toHaveBeenCalled();
    },
  );

  it('keeps native selection through the picker background and return', async () => {
    await mount();
    const native = deferred<DocumentPicker.DocumentPickerResult>();
    jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(native.promise);
    let picking!: Promise<void>;
    await act(async () => {picking = picker.pickAttachments();});
    await update({appIsActive: false});
    expect(picker.pickerIsActive()).toBe(true);
    await update({appIsActive: true});
    await act(async () => {native.resolve(selection()); await picking;});
    expect(files).toEqual([expect.objectContaining({uri: 'file:///managed/work.pdf', name: 'work.pdf'})]);
    expect(picker.pickerIsActive()).toBe(false);
  });

  it('an old cleanup finally cannot unlock a new conversation picker', async () => {
    await mount();
    const cache = deferred<Awaited<ReturnType<typeof cacheLearnerDraftFile>>>();
    jest.mocked(cacheLearnerDraftFile).mockReturnValueOnce(cache.promise);
    let oldPicking!: Promise<void>;
    await act(async () => {oldPicking = picker.pickAttachments();});
    await update({scope: 'user-a:3:lesson-2'});
    const native = deferred<DocumentPicker.DocumentPickerResult>();
    jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(native.promise);
    let newPicking!: Promise<void>;
    await act(async () => {newPicking = picker.pickAttachments();});
    await act(async () => {
      cache.resolve({uri: 'file:///managed/old.pdf', fileName: 'old.pdf'});
      await oldPicking;
      await picker.pickAttachments();
    });
    expect(picker.pickerIsActive()).toBe(true);
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(2);
    await act(async () => {native.resolve(selection('new.pdf')); await newPicking;});
    expect(files).toEqual([expect.objectContaining({name: 'new.pdf'})]);
    expect(picker.pickerIsActive()).toBe(false);
  });

  it('rejects an action retained from another lesson before any account capture', async () => {
    await mount();
    const oldAction = picker.pickAttachments;
    await update({scope: 'user-a:3:lesson-2'});
    await act(async () => {await oldAction();});
    expect(mockCapture).not.toHaveBeenCalled();
    expect(DocumentPicker.getDocumentAsync).not.toHaveBeenCalled();
  });

  it('serializes taps and permits a fresh attempt after native cancellation', async () => {
    await mount();
    const native = deferred<DocumentPicker.DocumentPickerResult>();
    jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(native.promise);
    let picking!: Promise<void>;
    await act(async () => {picking = picker.pickAttachments(); await picker.pickAttachments();});
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(1);
    await act(async () => {native.resolve({canceled: true, assets: null}); await picking;});
    await act(async () => {await picker.pickAttachments();});
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(2);
    expect(files).toHaveLength(1);
  });

  it('enforces the composer file limit and reuses the owned boundary for each copy', async () => {
    await mount({limit: 2});
    const result = selection();
    if (result.canceled) throw new Error('invalid fixture');
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValue({
      canceled: false, assets: [...result.assets, {...result.assets[0], name: 'second.pdf'}, {...result.assets[0], name: 'third.pdf'}],
    });
    await act(async () => {await picker.pickAttachments(); await picker.pickAttachments();});
    expect(files).toHaveLength(2);
    expect(DocumentPicker.getDocumentAsync).toHaveBeenCalledTimes(1);
    expect(cacheLearnerDraftFile).toHaveBeenCalledTimes(2);
    expect(cacheLearnerDraftFile).toHaveBeenCalledWith(
      'course_chat', expect.any(Object), 8 * 1024 * 1024, {scope: 'user-a', epoch: 1},
    );
  });

  it('keeps next-question files if an older turn recovers while native selection is pending', async () => {
    await mount();
    const native = deferred<DocumentPicker.DocumentPickerResult>();
    jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(native.promise);
    let picking!: Promise<void>;
    await act(async () => {picking = picker.pickAttachments();});
    sendInFlight = true;
    await act(async () => {native.resolve(selection()); await picking;});
    expect(cacheLearnerDraftFile).toHaveBeenCalledTimes(1);
    expect(files).toEqual([expect.objectContaining({name: 'work.pdf'})]);
    expect(picker.pickerIsActive()).toBe(false);
  });

  it('does not launch a new picker when a turn starts during pre-launch capture', async () => {
    await mount();
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    let picking!: Promise<void>;
    await act(async () => {picking = picker.pickAttachments();});
    sendInFlight = true;
    await act(async () => {capture.resolve({...mockBoundary}); await picking;});
    expect(DocumentPicker.getDocumentAsync).not.toHaveBeenCalled();
    expect(picker.pickerIsActive()).toBe(false);
  });

  it('does not show an old native failure after an account switch before its screen rerenders', async () => {
    await mount();
    let reject!: (error: Error) => void;
    jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(new Promise((_resolve, no) => {reject = no;}));
    let picking!: Promise<void>;
    await act(async () => {picking = picker.pickAttachments();});
    mockBoundary = {scope: 'user-b', epoch: 2};
    await act(async () => {reject(new Error('native failed')); await picking;});
    expect(showMediaPickerFailure).not.toHaveBeenCalled();
    expect(files).toEqual([]);
  });

  it('cleans earlier copies if a later file fails and leaves a working retry', async () => {
    await mount();
    const result = selection();
    if (result.canceled) throw new Error('invalid fixture');
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValueOnce({
      canceled: false, assets: [...result.assets, {...result.assets[0], name: 'second.pdf'}],
    });
    jest.mocked(cacheLearnerDraftFile)
      .mockResolvedValueOnce({uri: 'file:///managed/first.pdf'})
      .mockRejectedValueOnce(new Error('LEARNER_DRAFT_STORAGE_FULL'));
    await act(async () => {await picker.pickAttachments();});
    expect(files).toEqual([]);
    expect(jest.mocked(removeLearnerDraftFile).mock.calls.map(([file]) => file))
      .toEqual([expect.objectContaining({uri: 'file:///managed/first.pdf'})]);
    expect(showMediaPickerFailure).toHaveBeenCalledWith('LEARNER_DRAFT_STORAGE_FULL');
    expect(picker.pickerIsActive()).toBe(false);
    await act(async () => {await picker.pickAttachments();});
    expect(files).toHaveLength(1);
  });

  it('releases only its flight even when obsolete-file cleanup fails', async () => {
    await mount();
    const cache = deferred<Awaited<ReturnType<typeof cacheLearnerDraftFile>>>();
    jest.mocked(cacheLearnerDraftFile).mockReturnValueOnce(cache.promise);
    jest.mocked(removeLearnerDraftFile).mockRejectedValueOnce(new Error('registry unavailable'));
    let picking!: Promise<void>;
    await act(async () => {picking = picker.pickAttachments();});
    await update({visible: false});
    await act(async () => {cache.resolve({uri: 'file:///managed/old.pdf'}); await picking;});
    expect(reportClientError).toHaveBeenCalledWith(
      expect.any(Error), {source: 'course_chat_attachment_cleanup'},
    );
    expect(showMediaPickerFailure).not.toHaveBeenCalled();
    await update({visible: true});
    await act(async () => {await picker.pickAttachments();});
    expect(files).toHaveLength(1);
  });
});
