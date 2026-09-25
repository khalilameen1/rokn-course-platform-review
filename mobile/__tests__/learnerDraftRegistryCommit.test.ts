import RNFS from 'react-native-fs';

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: async () => ({scope: 'account-a', epoch: 1}),
  getCurrentAccountStorageScope: async () => 'account-a',
}));

import {retainLearnerDraftFiles} from '../src/services/learnerDraftFiles';

const root = '/tmp/rokn-cache/rokn_learner_drafts';
const target = `${root}/account-a/.references.json`;
const temporary = `${target}.tmp`;
const backup = `${target}.backup`;
const oldPath = `${root}/account-a/project/old.pdf`;
const newPath = `${root}/account-a/project/new.pdf`;
const initial = {old: {paths: [oldPath], updatedAt: 100}};
const disk = new Map<string, string>();
const move = async (from: string, to: string) => {
  if (!disk.has(from)) throw new Error('missing source');
  disk.set(to, disk.get(from)!);
  disk.delete(from);
};
const saveNew = (owner = 'new') =>
  retainLearnerDraftFiles(owner, [{uri: newPath}], 'account-a');
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => (resolve = done));
  return {promise, resolve};
};

describe('learner attachment registry commits', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    disk.clear();
    disk.set(target, JSON.stringify(initial));
    jest.mocked(RNFS.mkdir).mockResolvedValue(undefined);
    jest.mocked(RNFS.exists).mockImplementation(async path => disk.has(path));
    jest.mocked(RNFS.readFile).mockImplementation(async path => {
      if (!disk.has(path)) throw new Error('missing');
      return disk.get(path)!;
    });
    jest.mocked(RNFS.writeFile).mockImplementation(async (path, value) => {
      disk.set(path, value);
    });
    jest.mocked(RNFS.moveFile).mockImplementation(move);
    jest.mocked(RNFS.unlink).mockImplementation(async path => {
      disk.delete(path);
    });
  });

  it.each(['temporary write', 'backup rename', 'publish rename'])(
    'preserves existing owners and allows retry after a failed %s',
    async stage => {
      const failure = new Error('filesystem unavailable');
      if (stage === 'temporary write')
        jest.mocked(RNFS.writeFile).mockRejectedValueOnce(failure);
      else {
        jest.mocked(RNFS.moveFile).mockImplementation(async (from, to) => {
          if (
            (stage === 'backup rename' && from === target) ||
            (stage === 'publish rename' && from === temporary)
          )
            throw failure;
          await move(from, to);
        });
      }
      await expect(saveNew()).rejects.toBe(failure);
      expect(JSON.parse(disk.get(target)!)).toEqual(initial);
      jest.mocked(RNFS.moveFile).mockImplementation(move);
      await saveNew();
      expect(JSON.parse(disk.get(target)!)).toEqual({
        ...initial,
        new: {paths: [newPath], updatedAt: expect.any(Number)},
      });
      expect(disk.has(backup)).toBe(false);
      expect(disk.has(temporary)).toBe(false);
    },
  );

  it('retains a recoverable backup when both publication and rollback fail', async () => {
    jest.mocked(RNFS.moveFile).mockImplementation(async (from, to) => {
      if (to === target) throw new Error('rename unavailable');
      await move(from, to);
    });
    await expect(saveNew()).rejects.toThrow('rename unavailable');
    expect(disk.has(target)).toBe(false);
    expect(JSON.parse(disk.get(backup)!)).toEqual(initial);
    jest.mocked(RNFS.moveFile).mockImplementation(move);
    await saveNew();
    expect(JSON.parse(disk.get(target)!)).toEqual({
      ...initial,
      new: {paths: [newPath], updatedAt: expect.any(Number)},
    });
    expect(disk.has(backup)).toBe(false);
  });

  it('does not let two reference owners overwrite each other while a commit is pending', async () => {
    const gate = deferred();
    const started = deferred();
    jest.mocked(RNFS.writeFile).mockImplementationOnce(async (path, value) => {
      started.resolve();
      await gate.promise;
      disk.set(path, value);
    });
    const first = saveNew('first');
    let second: Promise<void> | undefined;
    try {
      await started.promise;
      second = saveNew('second');
      for (let turn = 0; turn < 20; turn += 1) await Promise.resolve();
      expect(RNFS.readFile).toHaveBeenCalledTimes(1);
      expect(RNFS.writeFile).toHaveBeenCalledTimes(1);
    } finally {
      gate.resolve();
      await first;
      await second;
    }
    expect(Object.keys(JSON.parse(disk.get(target)!))).toEqual([
      'old',
      'first',
      'second',
    ]);
  });

  it('does not hold another account behind a pending registry commit', async () => {
    const gate = deferred();
    const started = deferred();
    jest.mocked(RNFS.writeFile).mockImplementationOnce(async (path, value) => {
      started.resolve();
      await gate.promise;
      disk.set(path, value);
    });
    const first = saveNew();
    let second: Promise<void> | undefined;
    let saved = false;
    try {
      await started.promise;
      second = retainLearnerDraftFiles(
        'other',
        [{uri: `${root}/account-b/project/file.pdf`}],
        'account-b',
      ).then(() => {
        saved = true;
      });
      for (let turn = 0; turn < 40; turn += 1) await Promise.resolve();
      expect(saved).toBe(true);
    } finally {
      gate.resolve();
      await first;
      await second;
    }
    expect(
      Object.keys(JSON.parse(disk.get(`${root}/account-b/.references.json`)!)),
    ).toEqual(['other']);
    expect(Object.keys(JSON.parse(disk.get(target)!))).toEqual(['old', 'new']);
  });
});
