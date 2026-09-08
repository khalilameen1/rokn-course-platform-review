import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (base: string) => `${base}:learner-a`,
  captureAccountSessionBoundary: async () => ({scope: 'learner-a', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
  getCurrentAccountStorageScope: async () => 'learner-a',
}));

import {
  clearProjectSubmissionDraft,
  copyProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../src/services/projectSubmissionDraft';
import {learnerDraftFileIsReadable} from '../src/services/learnerDraftFiles';

const boundary = {scope: 'learner-a', epoch: 1};
const directory = '/tmp/rokn-cache/rokn_learner_drafts/learner-a';
const oldPath = `${directory}/project/old.pdf`;
const newPath = `${directory}/project/new.pdf`;
const oldFile = {
  uri: `file://${oldPath}`,
  name: 'old.pdf',
  type: 'application/pdf',
  size: 100,
};
const newFile = {
  uri: `file://${newPath}`,
  name: 'new.pdf',
  type: 'application/pdf',
  size: 100,
};
const destinationKey = '@rokn/project-editor-draft/v1:learner-a:42';
const storageSetItem = jest
  .mocked(AsyncStorage.setItem)
  .getMockImplementation()!;

describe('project draft durable file references', () => {
  const nativeFiles = new Map<string, string>();

  beforeEach(async () => {
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.setItem).mockImplementation(storageSetItem);
    await AsyncStorage.clear();
    nativeFiles.clear();
    nativeFiles.set(oldPath, 'original PDF bytes');
    nativeFiles.set(newPath, 'replacement PDF bytes');
    jest
      .mocked(RNFS.exists)
      .mockImplementation(async path => nativeFiles.has(path));
    jest.mocked(RNFS.readFile).mockImplementation(async path => {
      const content = nativeFiles.get(path);
      if (content === undefined) throw new Error('ENOENT');
      return content;
    });
    jest.mocked(RNFS.writeFile).mockImplementation(async (path, content) => {
      nativeFiles.set(path, content);
    });
    jest.mocked(RNFS.moveFile).mockImplementation(async (from, to) => {
      const content = nativeFiles.get(from);
      if (content === undefined) throw new Error('ENOENT');
      nativeFiles.set(to, content);
      nativeFiles.delete(from);
    });
    jest.mocked(RNFS.unlink).mockImplementation(async path => {
      nativeFiles.delete(path);
    });
    jest.mocked(RNFS.stat).mockImplementation(async path => {
      if (!nativeFiles.has(path))
        throw Object.assign(new Error('ENOENT'), {code: 'ENOENT'});
      return {size: 100, isFile: () => true} as Awaited<
        ReturnType<typeof RNFS.stat>
      >;
    });
  });

  it.each(['ordinary save', 'confirmed conflict replacement'])(
    'keeps files of the last durable destination after failed %s and source cleanup',
    async operation => {
      const oldDraft = {
        note: 'original work',
        files: [oldFile],
        updatedAt: Date.now(),
      };
      const replacement = {
        note: 'replacement work',
        files: [newFile],
        updatedAt: Date.now(),
      };
      // A previous legitimate draft transition creates two durable owners of
      // the same file, as the editor intentionally preserves its source copy.
      await copyProjectSubmissionDraft('40', '42', oldDraft, boundary);
      await saveProjectSubmissionDraft('41', replacement, boundary);
      const savedDestination = await AsyncStorage.getItem(destinationKey);
      let expectedDestination: string | undefined;
      if (operation === 'confirmed conflict replacement') {
        const conflict = await copyProjectSubmissionDraft(
          '41',
          '42',
          replacement,
          boundary,
        );
        expect(conflict.kind).toBe('conflict');
        if (conflict.kind === 'conflict')
          expectedDestination = conflict.destinationSnapshot;
      }
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (key === destinationKey) throw new Error('DISK_FULL');
          return storageSetItem(key, value);
        });
      const failedSave =
        operation === 'ordinary save'
          ? saveProjectSubmissionDraft('42', replacement, boundary)
          : copyProjectSubmissionDraft(
              '41',
              '42',
              replacement,
              boundary,
              expectedDestination,
            );
      await expect(failedSave).rejects.toThrow('DISK_FULL');
      expect(await AsyncStorage.getItem(destinationKey)).toBe(savedDestination);

      // Removing one source must not remove the file still named by the
      // destination's unchanged durable record. These are the real registry
      // and cleanup helpers, not a mocked retain-files contract.
      await clearProjectSubmissionDraft('40', [], boundary);
      expect(await learnerDraftFileIsReadable(oldFile)).toBe(true);
      expect(nativeFiles.has(oldPath)).toBe(true);
      expect(
        JSON.parse(nativeFiles.get(`${directory}/.references.json`) || '{}')[
          'project-submission:42'
        ].paths,
      ).toContain(oldPath);
    },
  );

  it.each([false, true])(
    'commits the new draft and treats trim failure=%s as maintenance',
    async trimFails => {
      const oldDraft = {
        note: 'original work',
        files: [oldFile],
        updatedAt: Date.now(),
      };
      const replacement = {
        note: 'replacement work',
        files: [newFile],
        updatedAt: Date.now(),
      };
      await copyProjectSubmissionDraft('40', '42', oldDraft, boundary);
      let trimAttempted = false;
      jest.mocked(RNFS.writeFile).mockImplementation(async (path, content) => {
        const registry = JSON.parse(content);
        const paths = registry['project-submission:42']?.paths;
        if (
          Array.isArray(paths) &&
          paths.length === 1 &&
          paths[0] === newPath
        ) {
          trimAttempted = true;
          expect(
            JSON.parse((await AsyncStorage.getItem(destinationKey)) || '{}'),
          ).toMatchObject(replacement);
          if (trimFails) throw new Error('REGISTRY_TRIM_FAILED');
        }
        nativeFiles.set(path, content);
      });
      await expect(
        saveProjectSubmissionDraft('42', replacement, boundary),
      ).resolves.toBeUndefined();
      expect(trimAttempted).toBe(true);
      expect(
        JSON.parse((await AsyncStorage.getItem(destinationKey)) || '{}'),
      ).toMatchObject(replacement);
      const retained = JSON.parse(
        nativeFiles.get(`${directory}/.references.json`) || '{}',
      )['project-submission:42'].paths;
      expect(retained).toEqual(trimFails ? [oldPath, newPath] : [newPath]);
      // A successful trim releases only the obsolete file; failed maintenance
      // may retain it longer but cannot invalidate the committed replacement.
      jest.mocked(RNFS.writeFile).mockImplementation(async (path, content) => {
        nativeFiles.set(path, content);
      });
      await clearProjectSubmissionDraft('40', [], boundary);
      expect(await learnerDraftFileIsReadable(oldFile)).toBe(trimFails);
      expect(await learnerDraftFileIsReadable(newFile)).toBe(true);
    },
  );

  it('does not release the old files when saving an empty draft fails to remove its durable record', async () => {
    await copyProjectSubmissionDraft(
      '40',
      '42',
      {note: 'original', files: [oldFile], updatedAt: Date.now()},
      boundary,
    );
    const saved = await AsyncStorage.getItem(destinationKey);
    jest
      .mocked(AsyncStorage.removeItem)
      .mockRejectedValueOnce(new Error('DELETE_FAILED'));
    await expect(
      saveProjectSubmissionDraft(
        '42',
        {note: '', files: [], updatedAt: Date.now()},
        boundary,
      ),
    ).rejects.toThrow('DELETE_FAILED');
    expect(await AsyncStorage.getItem(destinationKey)).toBe(saved);
    await clearProjectSubmissionDraft('40', [], boundary);
    expect(await learnerDraftFileIsReadable(oldFile)).toBe(true);
  });
});
