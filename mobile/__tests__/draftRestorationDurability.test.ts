import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';

let mockEpoch = 1;
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (base: string) => `${base}:learner-a`,
  captureAccountSessionBoundary: async () => ({
    scope: 'learner-a',
    epoch: mockEpoch,
  }),
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  getCurrentAccountStorageScope: async () => 'learner-a',
}));

import {
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../src/services/projectSubmissionDraft';
import {learnerDraftFileIsReadable} from '../src/services/learnerDraftFiles';
import {
  loadProjectFeedbackDraft,
  saveProjectFeedbackDraft,
} from '../src/services/projectFeedbackDraft';
import {
  readPortfolioEditorDraft,
  writePortfolioEditorDraft,
} from '../src/services/portfolioDraft';
import {
  loadCourseChatHistory,
  saveCourseChatHistory,
} from '../src/components/VideoPlayer/courseChat/persistence';
import {
  listPortfolioMediaUploads,
  stagePortfolioMediaUpload,
} from '../src/services/portfolioMediaOutbox';
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
import {
  loadProductFeedbackDraft,
  loadProductFeedbackReplyDraft,
  saveProductFeedbackDraft,
  saveProductFeedbackReplyDraft,
} from '../src/services/productFeedback';

const boundary = {scope: 'learner-a', epoch: 1};
const directory = '/tmp/rokn-cache/rokn_learner_drafts/learner-a';
const readablePath = `${directory}/project/work.pdf`;
const missingPath = `${directory}/project/missing.pdf`;
const file = (path: string) => ({
  uri: `file://${path}`,
  name: path.split('/').pop()!,
  type: 'application/pdf',
  size: 100,
});
const readableFile = file(readablePath);
const missingFile = file(missingPath);
const key = '@rokn/project-editor-draft/v1:learner-a:42';
const storageSetItem = jest
  .mocked(AsyncStorage.setItem)
  .getMockImplementation()!;
const storageGetItem = jest
  .mocked(AsyncStorage.getItem)
  .getMockImplementation()!;
const storageRemoveItem = jest
  .mocked(AsyncStorage.removeItem)
  .getMockImplementation()!;
const requestId = '11111111-1111-4111-8111-111111111111';
const chatFile = {...readableFile, uploadId: 'upload-local'};
const restorations = [
  {
    name: 'project submission',
    key,
    save: () =>
      saveProjectSubmissionDraft(
        '42',
        {note: 'Saved work', files: [readableFile], updatedAt: Date.now()},
        boundary,
      ),
    load: () => loadProjectSubmissionDraft('42', boundary),
  },
  {
    name: 'project feedback',
    key: '@rokn/project-feedback-draft/v1:learner-a:thread-1',
    save: () =>
      saveProjectFeedbackDraft(
        'thread-1',
        {
          text: 'Saved reply',
          attachments: [chatFile],
          requestId,
          fingerprint: 'original-body',
          updatedAt: Date.now(),
        },
        boundary,
      ),
    load: () => loadProjectFeedbackDraft('thread-1', boundary),
  },
  {
    name: 'portfolio editor',
    key: '@rokn/portfolio-editor-draft/v1:learner-a',
    save: () =>
      writePortfolioEditorDraft(
        {
          title: 'Saved title',
          summary: 'Saved summary',
          clientRequestId: requestId,
          media: [readableFile],
          updatedAt: Date.now(),
        },
        boundary,
      ),
    load: () => readPortfolioEditorDraft(boundary),
  },
  {
    name: 'course chat',
    key: '@rokn/course-chat-history/v2:learner-a:7:course',
    save: () =>
      saveCourseChatHistory(
        '7',
        [
          {
            id: 'local-question',
            role: 'user',
            text: 'Saved question',
            createdAt: Date.now(),
            clientRequestId: requestId,
            deliveryStatus: 'failed',
            attachments: [chatFile],
          },
        ],
        undefined,
        boundary,
      ),
    load: () => loadCourseChatHistory('7', undefined, boundary),
  },
  {
    name: 'portfolio upload receipt',
    key: '@rokn/portfolio-media-outbox/v1:learner-a',
    save: () =>
      stagePortfolioMediaUpload(
        {
          projectId: '42',
          clientRequestId: requestId,
          file: readableFile,
          createdAt: Date.now(),
        },
        boundary,
      ),
    load: () => listPortfolioMediaUploads('42', boundary),
  },
  {
    name: 'support message',
    key: '@rokn/product-feedback-draft/v1:learner-a',
    save: () =>
      saveProductFeedbackDraft(
        {
          category: 'problem',
          message: 'Saved support message',
          clientRequestId: requestId,
          includeDiagnostics: false,
          attachment: readableFile,
          updatedAt: Date.now(),
        },
        boundary,
      ),
    load: () => loadProductFeedbackDraft(boundary),
  },
  {
    name: 'support reply',
    key: '@rokn/product-feedback-reply/v1:case-1:learner-a',
    save: () =>
      saveProductFeedbackReplyDraft(
        'case-1',
        {
          message: 'Saved follow-up',
          clientRequestId: requestId,
          attachment: readableFile,
        },
        boundary,
      ),
    load: () => loadProductFeedbackReplyDraft('case-1', boundary),
  },
];

describe('draft restoration preserves durable work under uncertain I/O', () => {
  const nativeFiles = new Map<string, string>();
  const writeNative = async (path: string, content: string) => {
    nativeFiles.set(path, content);
  };
  const statNative = async (path: string) => {
    if (!nativeFiles.has(path))
      throw Object.assign(new Error('ENOENT'), {code: 'ENOENT'});
    return {size: 100, isFile: () => true} as Awaited<
      ReturnType<typeof RNFS.stat>
    >;
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    mockEpoch = 1;
    jest.mocked(AsyncStorage.setItem).mockImplementation(storageSetItem);
    jest.mocked(AsyncStorage.getItem).mockImplementation(storageGetItem);
    jest.mocked(AsyncStorage.removeItem).mockImplementation(storageRemoveItem);
    await AsyncStorage.clear();
    nativeFiles.clear();
    nativeFiles.set(readablePath, 'saved project bytes');
    jest
      .mocked(RNFS.exists)
      .mockImplementation(async path => nativeFiles.has(path));
    jest.mocked(RNFS.readFile).mockImplementation(async path => {
      const content = nativeFiles.get(path);
      if (content === undefined) throw new Error('ENOENT');
      return content;
    });
    jest.mocked(RNFS.writeFile).mockImplementation(writeNative);
    jest.mocked(RNFS.moveFile).mockImplementation(async (from, to) => {
      const content = nativeFiles.get(from);
      if (content === undefined) throw new Error('ENOENT');
      nativeFiles.set(to, content);
      nativeFiles.delete(from);
    });
    jest.mocked(RNFS.unlink).mockImplementation(async path => {
      nativeFiles.delete(path);
    });
    jest.mocked(RNFS.stat).mockImplementation(statNative);
  });

  it.each(['repair write', 'reference write', 'account replacement'])(
    'does not erase valid project work after %s fails during restoration',
    async failure => {
      const draft = {
        note: 'My prepared work',
        files: [readableFile, missingFile],
        updatedAt: Date.now(),
      };
      await saveProjectSubmissionDraft('42', draft, boundary);
      const raw = await AsyncStorage.getItem(key);
      if (failure === 'repair write') {
        jest
          .mocked(AsyncStorage.setItem)
          .mockRejectedValueOnce(new Error('DISK_FULL'));
      } else if (failure === 'reference write') {
        jest
          .mocked(RNFS.writeFile)
          .mockRejectedValueOnce(new Error('REGISTRY_UNAVAILABLE'));
      } else {
        jest.mocked(RNFS.stat).mockImplementation(async path => {
          const result = await statNative(path);
          mockEpoch = 2;
          return result;
        });
      }

      const result = await loadProjectSubmissionDraft('42', boundary).then(
        restored => ({restored, error: null}),
        error => ({restored: undefined, error}),
      );
      expect(await AsyncStorage.getItem(key)).not.toBeNull();
      expect(await learnerDraftFileIsReadable(readableFile)).toBe(true);
      expect(result.error).toBeTruthy();
      if (failure !== 'reference write')
        expect(await AsyncStorage.getItem(key)).toBe(raw);
    },
  );

  it.each(restorations)(
    'does not confuse temporary native I/O with a missing file in $name',
    async restoration => {
      await restoration.save();
      const raw = await AsyncStorage.getItem(restoration.key);
      expect(raw).not.toBeNull();
      jest.mocked(RNFS.stat).mockRejectedValueOnce(
        Object.assign(new Error('Storage temporarily unavailable'), {
          code: 'EIO',
        }),
      );
      const result = await restoration.load().then(
        restored => ({restored, error: null}),
        error => ({restored: undefined, error}),
      );
      expect(result.error).toMatchObject({code: 'EIO'});
      expect(await AsyncStorage.getItem(restoration.key)).toBe(raw);
      expect(nativeFiles.has(readablePath)).toBe(true);
    },
  );

  it('preserves project feedback text and server attachment identity after a registry write failure', async () => {
    const draft = {
      text: 'Prepared reply',
      attachments: [
        chatFile,
        {...chatFile, uri: '', serverId: '91', uploadId: 'already-uploaded'},
      ],
      requestId,
      fingerprint: 'same-attempt',
      updatedAt: Date.now(),
    };
    await saveProjectFeedbackDraft('thread-1', draft, boundary);
    const draftKey = restorations[1].key;
    const raw = await AsyncStorage.getItem(draftKey);
    const references = nativeFiles.get(`${directory}/.references.json`);
    jest
      .mocked(RNFS.writeFile)
      .mockRejectedValueOnce(new Error('REGISTRY_UNAVAILABLE'));
    await expect(
      loadProjectFeedbackDraft('thread-1', boundary),
    ).rejects.toThrow('REGISTRY_UNAVAILABLE');
    expect(await AsyncStorage.getItem(draftKey)).toBe(raw);
    expect(nativeFiles.get(`${directory}/.references.json`)).toBe(references);
    await expect(
      loadProjectFeedbackDraft('thread-1', boundary),
    ).resolves.toEqual(draft);
  });

  it.each([restorations[2], restorations[5]])(
    'keeps $name after a failed missing-file repair write',
    async restoration => {
      await restoration.save();
      const raw = await AsyncStorage.getItem(restoration.key);
      nativeFiles.delete(readablePath);
      jest
        .mocked(AsyncStorage.setItem)
        .mockRejectedValueOnce(new Error('REPAIR_FAILED'));
      await expect(restoration.load()).rejects.toThrow('REPAIR_FAILED');
      expect(await AsyncStorage.getItem(restoration.key)).toBe(raw);
    },
  );

  it.each(restorations)(
    'keeps $name retryable when its durable read fails',
    async restoration => {
      await restoration.save();
      const raw = await AsyncStorage.getItem(restoration.key);
      const references = nativeFiles.get(`${directory}/.references.json`);
      jest.mocked(AsyncStorage.getItem).mockImplementationOnce(async () => {
        throw new Error('STORAGE_BUSY');
      });
      await expect(restoration.load()).rejects.toThrow('STORAGE_BUSY');
      expect(await AsyncStorage.getItem(restoration.key)).toBe(raw);
      expect(nativeFiles.get(`${directory}/.references.json`)).toBe(references);
      expect(JSON.stringify(await restoration.load())).toContain(readablePath);
    },
  );

  it.each(restorations)(
    'restores $name without losing text, file or retry identity',
    async restoration => {
      await restoration.save();
      const raw = await AsyncStorage.getItem(restoration.key);
      const restored = await restoration.load();
      expect(JSON.stringify(restored)).toContain(readablePath);
      expect(await AsyncStorage.getItem(restoration.key)).toBe(raw);
      if (restoration.name !== 'project submission')
        expect(JSON.stringify(restored)).toContain(requestId);
    },
  );

  it.each(restorations)(
    'still omits confirmed missing files from $name',
    async restoration => {
      await restoration.save();
      nativeFiles.delete(readablePath);
      expect(JSON.stringify(await restoration.load())).not.toContain(
        readablePath,
      );
    },
  );

  it.each([
    {code: 'ENOENT'},
    {code: 'ENOTDIR'},
    {code: 'ENSCOCOAERRORDOMAIN260'},
    {code: 'ENSPOSIXERRORDOMAIN2'},
    {code: 'EUNSPECIFIED', message: 'File does not exist'},
  ])(
    'recognizes the actual native missing-file response $code / $message',
    async error => {
      jest.mocked(RNFS.stat).mockRejectedValueOnce(error);
      await expect(learnerDraftFileIsReadable(readableFile)).resolves.toBe(
        false,
      );
    },
  );

  it.each(['EIO', 'EACCES', 'EUNSPECIFIED', 'ENSCOCOAERRORDOMAIN257'])(
    'does not treat uncertain native %s as absence',
    async code => {
      const error = Object.assign(new Error('Temporary read failure'), {code});
      jest.mocked(RNFS.stat).mockRejectedValueOnce(error);
      await expect(learnerDraftFileIsReadable(readableFile)).rejects.toBe(
        error,
      );
    },
  );

  it('still omits an empty file without claiming a successful readable attachment', async () => {
    jest
      .mocked(RNFS.stat)
      .mockResolvedValueOnce({size: 0} as Awaited<
        ReturnType<typeof RNFS.stat>
      >);
    await expect(learnerDraftFileIsReadable(readableFile)).resolves.toBe(false);
    await expect(learnerDraftFileIsReadable(null)).resolves.toBe(false);
  });

  it.each(['expired', 'invalid schema', 'invalid JSON'])(
    'cleans a confirmed %s project draft but retains it if durable deletion fails',
    async kind => {
      await restorations[0].save();
      const saved = JSON.parse((await AsyncStorage.getItem(key))!);
      const raw =
        kind === 'invalid JSON'
          ? '{invalid'
          : JSON.stringify({
              ...saved,
              ...(kind === 'expired' ? {updatedAt: 1} : {note: null}),
            });
      await AsyncStorage.setItem(key, raw);
      const references = nativeFiles.get(`${directory}/.references.json`);
      jest
        .mocked(AsyncStorage.removeItem)
        .mockRejectedValueOnce(new Error('DELETE_FAILED'));
      await expect(loadProjectSubmissionDraft('42', boundary)).rejects.toThrow(
        'DELETE_FAILED',
      );
      expect(await AsyncStorage.getItem(key)).toBe(raw);
      expect(nativeFiles.get(`${directory}/.references.json`)).toBe(references);
      expect(nativeFiles.has(readablePath)).toBe(true);
      await expect(
        loadProjectSubmissionDraft('42', boundary),
      ).resolves.toBeNull();
      expect(await AsyncStorage.getItem(key)).toBeNull();
      expect(
        JSON.parse(nativeFiles.get(`${directory}/.references.json`) || '{}')[
          'project-submission:42'
        ],
      ).toBeUndefined();
    },
  );

  it.each(
    restorations.filter(
      restoration => restoration.name !== 'portfolio upload receipt',
    ),
  )(
    'rejects late $name inspection after account replacement without mutating its draft',
    async restoration => {
      await restoration.save();
      const raw = await AsyncStorage.getItem(restoration.key);
      const references = nativeFiles.get(`${directory}/.references.json`);
      jest.mocked(RNFS.stat).mockImplementationOnce(async path => {
        const stat = await statNative(path);
        mockEpoch = 2;
        return stat;
      });
      await expect(restoration.load()).rejects.toThrow(
        'ACCOUNT_CHANGED_DURING_REQUEST',
      );
      expect(await AsyncStorage.getItem(restoration.key)).toBe(raw);
      expect(nativeFiles.get(`${directory}/.references.json`)).toBe(references);
    },
  );
});
