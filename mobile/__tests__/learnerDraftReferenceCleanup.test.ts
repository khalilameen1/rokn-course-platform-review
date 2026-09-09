jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: async () => ({epoch: 1, scope: 'account-1'}),
  getCurrentAccountStorageScope: async () => 'account-1',
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';

import {
  cacheLearnerDraftFile,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from '../src/services/learnerDraftFiles';

const directory = '/tmp/rokn-cache/rokn_learner_drafts/account-1';
const filePath = `${directory}/feedback/unsent.png`;
const file = {uri: `file://${filePath}`, size: 20, type: 'image/png'};
const registryPath = `${directory}/.references.json`;
const key = '@rokn/product-feedback-draft/v1:account-1';
const conflictsKey = '@rokn/product-feedback-draft-conflicts/v1:account-1';
const nativeFiles = new Map<string, string>();
const getAllKeys = jest
  .mocked(AsyncStorage.getAllKeys)
  .getMockImplementation()!;
const multiGet = jest.mocked(AsyncStorage.multiGet).getMockImplementation()!;
const oldRegistry = JSON.stringify({
  editor: {paths: [filePath], updatedAt: Date.now() - 10 * 60 * 1000},
});
const pick = () =>
  cacheLearnerDraftFile(
    'feedback',
    {
      uri: '/picker/new.png',
      type: 'image/png',
      size: 20,
    },
    100,
  );

beforeEach(async () => {
  jest.clearAllMocks();
  jest.mocked(AsyncStorage.getAllKeys).mockImplementation(getAllKeys);
  jest.mocked(AsyncStorage.multiGet).mockImplementation(multiGet);
  await AsyncStorage.clear();
  nativeFiles.clear();
  nativeFiles.set(filePath, 'saved learner bytes');
  jest
    .mocked(RNFS.exists)
    .mockImplementation(
      async path => path === directory || nativeFiles.has(path),
    );
  jest.mocked(RNFS.readFile).mockImplementation(async path => {
    const raw = nativeFiles.get(path);
    if (raw === undefined)
      throw Object.assign(new Error('missing'), {code: 'ENOENT'});
    return raw;
  });
  jest.mocked(RNFS.readDir).mockImplementation(async path => {
    const item = (child: string, isDirectory: boolean) => ({
      path: child,
      name: child.split('/').pop()!,
      size: 20,
      mtime: new Date(Date.now() - 40 * 24 * 60 * 60 * 1000),
      ctime: undefined,
      isDirectory: () => isDirectory,
      isFile: () => !isDirectory,
    });
    if (path === directory) return [item(`${directory}/feedback`, true)];
    return [...nativeFiles.keys()]
      .filter(child => child.startsWith(`${path}/`))
      .map(child => item(child, false));
  });
  jest.mocked(RNFS.copyFile).mockImplementation(async (_from, to) => {
    nativeFiles.set(to, 'new learner bytes');
  });
  jest
    .mocked(RNFS.stat)
    .mockResolvedValue({size: 20, isFile: () => true} as Awaited<
      ReturnType<typeof RNFS.stat>
    >);
  jest.mocked(RNFS.writeFile).mockImplementation(async (path, raw) => {
    nativeFiles.set(path, raw);
  });
  jest.mocked(RNFS.moveFile).mockImplementation(async (from, to) => {
    if (!nativeFiles.has(from)) throw new Error('missing source');
    nativeFiles.set(to, nativeFiles.get(from)!);
    nativeFiles.delete(from);
  });
  jest.mocked(RNFS.unlink).mockImplementation(async path => {
    nativeFiles.delete(path);
  });
});

it.each([
  'key listing',
  'batch read',
  'incomplete batch',
  'invalid active JSON',
])(
  'does not evict a durable file or rewrite owners after unavailable %s',
  async fault => {
    nativeFiles.set(registryPath, oldRegistry);
    await AsyncStorage.setItem(key, JSON.stringify({attachment: file}));
    if (fault === 'key listing')
      jest
        .mocked(AsyncStorage.getAllKeys)
        .mockRejectedValue(new Error('storage unavailable'));
    if (fault === 'batch read')
      jest
        .mocked(AsyncStorage.multiGet)
        .mockRejectedValue(new Error('storage unavailable'));
    if (fault === 'incomplete batch')
      jest.mocked(AsyncStorage.multiGet).mockResolvedValue([]);
    if (fault === 'invalid active JSON')
      await AsyncStorage.setItem(key, '{incomplete');
    await expect(pick()).rejects.toThrow();
    expect(nativeFiles.has(filePath)).toBe(true);
    expect(nativeFiles.get(registryPath)).toBe(oldRegistry);
    expect(RNFS.copyFile).not.toHaveBeenCalled();
  },
);

it.each(['support draft', 'restorable conflict'])(
  'protects an old %s even without a reference registry',
  async kind => {
    await AsyncStorage.setItem(
      kind === 'support draft' ? key : conflictsKey,
      JSON.stringify(
        kind === 'support draft'
          ? {attachment: file}
          : [
              {
                id: 'conflict-1',
                baseKey: '@rokn/product-feedback-draft/v1',
                raw: JSON.stringify({attachment: file}),
              },
            ],
      ),
    );
    await pick();
    expect(nativeFiles.has(filePath)).toBe(true);
    expect(nativeFiles.has(registryPath)).toBe(false);
  },
);

it('reclaims a real orphan despite unrelated, scalar, and quarantined storage entries', async () => {
  nativeFiles.set(registryPath, oldRegistry);
  await AsyncStorage.setItem(`${key}:corrupt`, '{retired');
  await AsyncStorage.setItem('@rokn/prompt:account-1', '1');
  await AsyncStorage.setItem('@rokn/default-folder:account-1', '12');
  await AsyncStorage.setItem('@rokn/foreign:account-11', '{not this account');
  await pick();
  expect(nativeFiles.has(filePath)).toBe(false);
  expect(JSON.parse(nativeFiles.get(registryPath)!)).toEqual({});
});

it('accepts a listed key removed before the batch read as absent', async () => {
  nativeFiles.set(registryPath, oldRegistry);
  jest.mocked(AsyncStorage.getAllKeys).mockResolvedValue([key]);
  jest.mocked(AsyncStorage.multiGet).mockResolvedValue([[key, null]]);
  await pick();
  expect(nativeFiles.has(filePath)).toBe(false);
});

it('does not count failed orphan deletion as reclaimed storage', async () => {
  const readDir = jest.mocked(RNFS.readDir).getMockImplementation()!;
  jest.mocked(RNFS.readDir).mockImplementation(async path =>
    (await readDir(path)).map(item => ({
      ...item,
      size: item.path === filePath ? 193 * 1024 * 1024 : item.size,
    })),
  );
  const unlink = jest.mocked(RNFS.unlink).getMockImplementation()!;
  jest.mocked(RNFS.unlink).mockImplementation(async path => {
    if (path === filePath) throw new Error('EIO');
    await unlink(path);
  });
  await expect(pick()).rejects.toThrow('LEARNER_DRAFT_STORAGE_FULL');
  expect(nativeFiles.has(filePath)).toBe(true);
  expect(RNFS.copyFile).not.toHaveBeenCalled();
});

it.each(['unreadable', 'malformed', 'invalid owner'])(
  'never replaces a %s registry with just the newest owner',
  async fault => {
    const original =
      fault === 'malformed'
        ? '{broken'
        : fault === 'invalid owner'
        ? JSON.stringify({editor: {paths: [filePath], updatedAt: 'invalid'}})
        : oldRegistry;
    nativeFiles.set(registryPath, original);
    // A stale backup must not substitute for an unreadable current registry.
    nativeFiles.set(`${registryPath}.backup`, '{}');
    if (fault === 'unreadable')
      jest.mocked(RNFS.readFile).mockRejectedValue(new Error('EIO'));
    await expect(
      retainLearnerDraftFiles('new-owner', [file], 'account-1'),
    ).rejects.toThrow();
    expect(nativeFiles.get(registryPath)).toBe(original);
    expect(RNFS.writeFile).not.toHaveBeenCalled();
  },
);

it('recovers the backup only when the atomic rename left the target absent', async () => {
  nativeFiles.set(`${registryPath}.backup`, oldRegistry);
  await retainLearnerDraftFiles('new-owner', [file], 'account-1');
  expect(Object.keys(JSON.parse(nativeFiles.get(registryPath)!))).toEqual([
    'editor',
    'new-owner',
  ]);
});

it('does not remove a file whose registry cannot be read', async () => {
  nativeFiles.set(registryPath, oldRegistry);
  jest.mocked(RNFS.readFile).mockRejectedValue(new Error('EIO'));
  await removeLearnerDraftFile(file).catch(() => undefined);
  expect(nativeFiles.has(filePath)).toBe(true);
});

it('defers explicit cleanup until a registry-free durable owner releases the file', async () => {
  await AsyncStorage.setItem(key, JSON.stringify({attachment: file}));
  await removeLearnerDraftFile(file);
  expect(nativeFiles.has(filePath)).toBe(true);
  await AsyncStorage.removeItem(key);
  await removeLearnerDraftFile(file);
  expect(nativeFiles.has(filePath)).toBe(false);
});
