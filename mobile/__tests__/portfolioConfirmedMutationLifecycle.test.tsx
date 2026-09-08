import React, {useState} from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import TestRenderer, {act} from 'react-test-renderer';

const mockGet = jest.fn();
const mockPost = jest.fn();
const mockDelete = jest.fn();
let mockBoundary = {epoch: 1, scope: 'portfolio-confirmed-1'};

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  ...jest.requireActual('../src/constants/helpers'),
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary !== mockBoundary)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/portfolio'),
}));
jest.mock('react-native-image-picker', () => ({launchImageLibrary: jest.fn()}));

import {usePortfolioProjectDetails} from '../src/screens/Profile/gallery/usePortfolioProjectDetails';
import {
  toPortfolioProject,
  type Project,
} from '../src/screens/Profile/gallery/portfolioModel';
import {mapPortfolioItem} from '../src/services/api/portfolioContract';
import {
  listPortfolioMediaUploads,
  stagePortfolioMediaUpload,
} from '../src/services/portfolioMediaOutbox';
import {deliverPortfolioMedia} from '../src/services/portfolioMediaDelivery';
import {replayPendingPortfolioMediaUploads} from '../src/services/portfolioMediaReplay';

const item = {
  id: 9,
  title: 'العمل المنشور',
  description: '',
  upload_state: 'ready',
  uploaded_media_count: 1,
  expected_media_count: 1,
  media: [
    {
      id: 71,
      file_type: 'image',
      status: 'ready',
      image_url: 'https://cdn.example.test/work.jpg',
    },
  ],
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const captureBoundary = async () => mockBoundary;
const isCreateBusy = () => false;
const cancelLibraryLoad = jest.fn();
const setMutationBlocked = jest.fn();
const mountedRef = {current: true};
const originalUnlink = (RNFS.unlink as jest.Mock).getMockImplementation()!;

describe('confirmed portfolio actions versus native outbox cleanup', () => {
  let owner!: ReturnType<typeof usePortfolioProjectDetails>;
  let projects!: Project[];
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = () => {
    const [library, setLibrary] = useState([
      toPortfolioProject(mapPortfolioItem(item)),
    ]);
    projects = library;
    owner = usePortfolioProjectDetails({
      cancelLibraryLoad,
      captureBoundary,
      isCreateBusy,
      mountedRef,
      setLibraryProjects: setLibrary,
      setMutationBlocked,
    });
    return null;
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {
      epoch: mockBoundary.epoch + 1,
      scope: `portfolio-confirmed-${mockBoundary.epoch + 1}`,
    };
    mountedRef.current = true;
    await AsyncStorage.clear();
    mockGet.mockReset().mockResolvedValue({data: {data: item}});
    mockPost.mockReset().mockResolvedValue({data: {data: item}});
    mockDelete.mockReset().mockResolvedValue({data: {success: true}});
    (RNFS.unlink as jest.Mock).mockImplementation(originalUnlink);
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    jest.useFakeTimers();
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      owner.openProject(projects[0]);
    });
  });

  afterEach(async () => {
    await act(async () => {
      mountedRef.current = false;
      renderer?.unmount();
    });
    renderer = undefined;
    jest.useRealTimers();
    jest.restoreAllMocks();
    (RNFS.unlink as jest.Mock).mockImplementation(originalUnlink);
  });

  it.each(['delete', 'finalize'] as const)(
    'releases the confirmed %s action while cleanup of its already-retired file is stalled',
    async operation => {
      const boundary = mockBoundary;
      const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
      const filePath = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/uploaded.jpg`;
      await AsyncStorage.setItem(
        key,
        JSON.stringify([
          {
            projectId: '9',
            clientRequestId: '11111111-1111-4111-8111-111111111111',
            file: {uri: `file://${filePath}`, type: 'image/jpeg'},
            createdAt: Date.now(),
          },
        ]),
      );
      const gate = deferred<void>();
      const started = deferred<void>();
      (RNFS.unlink as jest.Mock).mockImplementation(async path => {
        if (path === filePath) {
          started.resolve();
          await gate.promise;
        }
      });
      let completion: Promise<unknown> | undefined;
      try {
        await act(async () => {
          if (operation === 'delete') {
            owner.confirmDeleteSelectedProject();
            const buttons = (Alert.alert as jest.Mock).mock.calls[0][2];
            buttons
              .find(
                (button: {style?: string}) => button.style === 'destructive',
              )
              .onPress();
          } else {
            completion = owner.finalizeSelectedProject();
          }
        });
        await started.promise;
        // This is terminal native-file maintenance after both the server ACK
        // and the actual outbox removal, not an unaccepted upload or lost ACK.
        expect(await AsyncStorage.getItem(key)).toBeNull();
        expect(
          operation === 'delete' ? mockDelete : mockPost,
        ).toHaveBeenCalledTimes(1);
        await act(async () => {
          await jest.advanceTimersByTimeAsync(1500);
        });
        expect(owner.saving).toBe(false);
        expect(setMutationBlocked).toHaveBeenLastCalledWith(false);
        if (operation === 'delete') {
          expect(projects).toEqual([]);
          expect(owner.selected).toBeNull();
        } else {
          expect(owner.selected?.shareReady).toBe(true);
          expect(projects[0].shareReady).toBe(true);
        }
      } finally {
        await act(async () => {
          gate.resolve();
          await completion;
          await listPortfolioMediaUploads(undefined, boundary);
        });
      }
    },
  );

  it('returns accepted image media while terminal native file cleanup is stalled', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const filePath = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/accepted.jpg`;
    const entry = {
      projectId: '9',
      clientRequestId: '22222222-2222-4222-8222-222222222222',
      file: {uri: `file://${filePath}`, type: 'image/jpeg'},
      createdAt: Date.now(),
      storageKey: key,
    };
    await AsyncStorage.setItem(key, JSON.stringify([entry]));
    const gate = deferred<void>();
    const started = deferred<void>();
    (RNFS.unlink as jest.Mock).mockImplementation(async path => {
      if (path === filePath) {
        started.resolve();
        await gate.promise;
      }
    });
    mockPost.mockResolvedValueOnce({data: {data: item.media[0]}});
    let settled = false;
    const delivery = deliverPortfolioMedia(entry, boundary).then(result => {
      settled = true;
      return result;
    });
    try {
      await started.promise;
      expect(mockPost).toHaveBeenCalledWith(
        'portfolio/9/media',
        expect.any(FormData),
        expect.objectContaining({
          headers: {'Idempotency-Key': entry.clientRequestId},
        }),
      );
      expect(await AsyncStorage.getItem(key)).toBeNull();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1500);
      });
      expect(settled).toBe(true);
      await expect(delivery).resolves.toMatchObject({
        state: 'uploaded',
        media: {id: '71'},
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      gate.resolve();
      await delivery;
      await listPortfolioMediaUploads(undefined, boundary);
    }
  });

  it('retains the exact request and file after failed retirement and replays only that identity', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const entry = {
      projectId: '9',
      clientRequestId: '33333333-3333-4333-8333-333333333333',
      file: {
        uri: `file://${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/retry.jpg`,
        type: 'image/jpeg',
      },
      createdAt: Date.now(),
      storageKey: key,
    };
    await AsyncStorage.setItem(key, JSON.stringify([entry]));
    const serverIdentities = new Set<string>();
    mockPost.mockImplementation(async (_endpoint, _body, options) => {
      serverIdentities.add(options.headers['Idempotency-Key']);
      return {data: {data: item.media[0]}};
    });
    const remove = AsyncStorage.removeItem as jest.Mock;
    const originalRemove = remove.getMockImplementation()!;
    remove.mockImplementationOnce(async storageKey => {
      expect(storageKey).toBe(key);
      throw new Error('STORAGE_FULL');
    });
    try {
      await expect(
        deliverPortfolioMedia(entry, boundary),
      ).resolves.toMatchObject({state: 'uploaded', media: {id: '71'}});
      expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual([entry]);
      expect(RNFS.unlink).not.toHaveBeenCalled();
      await expect(
        deliverPortfolioMedia(entry, boundary),
      ).resolves.toMatchObject({state: 'uploaded', media: {id: '71'}});
      expect(mockPost).toHaveBeenCalledTimes(2);
      expect(serverIdentities).toEqual(new Set([entry.clientRequestId]));
      expect(await AsyncStorage.getItem(key)).toBeNull();
    } finally {
      remove.mockImplementation(originalRemove);
    }
  });

  it('does not convert an unacknowledged upload failure into completed media', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const entry = {
      projectId: '9',
      clientRequestId: '44444444-4444-4444-8444-444444444444',
      file: {uri: 'file:///pending.jpg', type: 'image/jpeg'},
      createdAt: Date.now(),
      storageKey: key,
    };
    await AsyncStorage.setItem(key, JSON.stringify([entry]));
    mockPost.mockRejectedValueOnce(new Error('NETWORK_TIMEOUT'));
    await expect(deliverPortfolioMedia(entry, boundary)).resolves.toEqual({
      state: 'retry',
    });
    expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual([entry]);
    expect(RNFS.unlink).not.toHaveBeenCalled();
    expect(mockPost).toHaveBeenCalledTimes(1);
  });

  it('keeps later staging behind the raw retirement lock without deleting its new entry or file', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const oldPath = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/old.jpg`;
    const nextPath = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/next.jpg`;
    await AsyncStorage.setItem(
      key,
      JSON.stringify([
        {
          projectId: '9',
          clientRequestId: '55555555-5555-4555-8555-555555555555',
          file: {uri: `file://${oldPath}`},
          createdAt: Date.now(),
        },
      ]),
    );
    const gate = deferred<void>();
    const started = deferred<void>();
    (RNFS.unlink as jest.Mock).mockImplementation(async path => {
      if (path === oldPath) {
        started.resolve();
        await gate.promise;
      }
    });
    let completion!: Promise<unknown>;
    await act(async () => {
      completion = owner.finalizeSelectedProject();
    });
    await started.promise;
    await act(async () => {
      await jest.advanceTimersByTimeAsync(750);
    });
    const nextEntry = {
      projectId: '9',
      clientRequestId: '66666666-6666-4666-8666-666666666666',
      file: {uri: `file://${nextPath}`},
      createdAt: Date.now(),
    };
    jest.spyOn(RNFS, 'stat').mockResolvedValueOnce({
      isFile: () => true,
      size: 128,
    } as Awaited<ReturnType<typeof RNFS.stat>>);
    let staged = false;
    const next = stagePortfolioMediaUpload(nextEntry, boundary).then(value => {
      staged = true;
      return value;
    });
    try {
      await act(async () => {
        await jest.advanceTimersByTimeAsync(10);
      });
      expect(owner.saving).toBe(false);
      expect(staged).toBe(false);
      expect(await AsyncStorage.getItem(key)).toBeNull();
      await act(async () => {
        gate.resolve();
        await completion;
        await next;
      });
      expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual([
        {...nextEntry, storageKey: key},
      ]);
      expect(RNFS.unlink).not.toHaveBeenCalledWith(nextPath);
      expect(RNFS.writeFile).toHaveBeenLastCalledWith(
        expect.stringContaining('.references.json.tmp'),
        expect.stringContaining(nextPath),
        'utf8',
      );
    } finally {
      gate.resolve();
      await completion;
      await next;
    }
  });

  it('rejects the old account result when ownership changes during terminal cleanup', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const path = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/old-owner.jpg`;
    const entry = {
      projectId: '9',
      clientRequestId: '77777777-7777-4777-8777-777777777777',
      file: {uri: `file://${path}`, type: 'image/jpeg'},
      createdAt: Date.now(),
      storageKey: key,
    };
    await AsyncStorage.setItem(key, JSON.stringify([entry]));
    const gate = deferred<void>();
    const started = deferred<void>();
    (RNFS.unlink as jest.Mock).mockImplementation(async filePath => {
      if (filePath === path) {
        started.resolve();
        await gate.promise;
      }
    });
    mockPost.mockResolvedValueOnce({data: {data: item.media[0]}});
    const result = deliverPortfolioMedia(entry, boundary).catch(error => error);
    try {
      await started.promise;
      mockBoundary = {
        epoch: boundary.epoch + 1,
        scope: `${boundary.scope}-replacement`,
      };
      const replacementKey = `@rokn/portfolio-media-outbox/v1:${mockBoundary.scope}`;
      await AsyncStorage.setItem(replacementKey, 'replacement account data');
      await act(async () => {
        await jest.advanceTimersByTimeAsync(750);
      });
      expect(await result).toEqual(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
      expect(await AsyncStorage.getItem(replacementKey)).toBe(
        'replacement account data',
      );
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      gate.resolve();
      mockBoundary = boundary;
      await listPortfolioMediaUploads(undefined, boundary);
    }
  });

  it('keeps a failed server deletion visible and allows an explicit retry', async () => {
    mockDelete.mockRejectedValueOnce(new Error('NETWORK_TIMEOUT'));
    const confirm = () => {
      owner.confirmDeleteSelectedProject();
      const calls = (Alert.alert as jest.Mock).mock.calls;
      const buttons = calls[calls.length - 1][2];
      buttons
        .find((button: {style?: string}) => button.style === 'destructive')
        .onPress();
    };
    await act(async () => {
      confirm();
    });
    expect(owner.saving).toBe(false);
    expect(projects).toHaveLength(1);
    expect(owner.selected?.id).toBe('9');
    expect(Alert.alert).toHaveBeenCalledWith(
      'تعذّر حذف المشروع',
      expect.any(String),
    );
    await act(async () => {
      confirm();
    });
    expect(mockDelete).toHaveBeenCalledTimes(2);
    expect(projects).toEqual([]);
    expect(owner.selected).toBeNull();
    expect(owner.saving).toBe(false);
  });

  it('still waits for durable staging before admitting an unaccepted upload', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const entry = {
      projectId: '9',
      clientRequestId: '88888888-8888-4888-8888-888888888888',
      file: {
        uri: `file://${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/pending.jpg`,
      },
      createdAt: Date.now(),
    };
    jest.spyOn(RNFS, 'stat').mockResolvedValueOnce({
      isFile: () => true,
      size: 128,
    } as Awaited<ReturnType<typeof RNFS.stat>>);
    const write = AsyncStorage.setItem as jest.Mock;
    const originalWrite = write.getMockImplementation()!;
    const started = deferred<void>();
    const gate = deferred<void>();
    write.mockImplementation(async (storageKey, value) => {
      if (storageKey === key) {
        started.resolve();
        await gate.promise;
      }
      await originalWrite(storageKey, value);
    });
    let settled = false;
    const pending = stagePortfolioMediaUpload(entry, boundary).then(value => {
      settled = true;
      return value;
    });
    try {
      await started.promise;
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1500);
      });
      expect(settled).toBe(false);
      expect(await AsyncStorage.getItem(key)).toBeNull();
      expect(mockPost).not.toHaveBeenCalled();
      gate.resolve();
      await expect(pending).resolves.toEqual({...entry, storageKey: key});
      expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual([
        {...entry, storageKey: key},
      ]);
    } finally {
      gate.resolve();
      await pending;
      write.mockImplementation(originalWrite);
    }
  });

  it('does not update an unmounted gallery after confirmed deletion cleanup times out', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const path = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}/unmounted.jpg`;
    await AsyncStorage.setItem(
      key,
      JSON.stringify([
        {
          projectId: '9',
          clientRequestId: '99999999-9999-4999-8999-999999999999',
          file: {uri: `file://${path}`},
          createdAt: Date.now(),
        },
      ]),
    );
    const gate = deferred<void>();
    const started = deferred<void>();
    (RNFS.unlink as jest.Mock).mockImplementation(async filePath => {
      if (filePath === path) {
        started.resolve();
        await gate.promise;
      }
    });
    await act(async () => {
      owner.confirmDeleteSelectedProject();
      const buttons = (Alert.alert as jest.Mock).mock.calls[0][2];
      buttons
        .find((button: {style?: string}) => button.style === 'destructive')
        .onPress();
    });
    await started.promise;
    await act(async () => {
      mountedRef.current = false;
      renderer?.unmount();
    });
    renderer = undefined;
    const previousProjects = projects;
    const alerts = (Alert.alert as jest.Mock).mock.calls.length;
    try {
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1500);
      });
      expect(projects).toBe(previousProjects);
      expect(Alert.alert).toHaveBeenCalledTimes(alerts);
      expect(mockDelete).toHaveBeenCalledTimes(1);
    } finally {
      gate.resolve();
      await listPortfolioMediaUploads(undefined, boundary);
    }
  });

  it('retires only a server-deleted UUID and continues its sibling upload in the same project', async () => {
    const boundary = mockBoundary;
    const key = `@rokn/portfolio-media-outbox/v1:${boundary.scope}`;
    const root = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${boundary.scope}`;
    const retiredPath = `${root}/retired.jpg`;
    const siblingPath = `${root}/sibling.jpg`;
    const retired = {
      projectId: '9',
      clientRequestId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      file: {uri: `file://${retiredPath}`, type: 'image/jpeg'},
      createdAt: Date.now() - 1,
      storageKey: key,
    };
    const sibling = {
      ...retired,
      clientRequestId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
      file: {uri: `file://${siblingPath}`, type: 'image/jpeg'},
      createdAt: Date.now(),
    };
    await AsyncStorage.setItem(key, JSON.stringify([retired, sibling]));
    jest.spyOn(RNFS, 'stat').mockResolvedValue({
      isFile: () => true,
      size: 128,
    } as Awaited<ReturnType<typeof RNFS.stat>>);
    const siblingStarted = deferred<void>();
    const siblingAcknowledgement = deferred<{
      data: {data: (typeof item.media)[0]};
    }>();
    mockPost.mockImplementation(async (endpoint, _form, options) => {
      expect(endpoint).toBe('portfolio/9/media');
      const requestId = options.headers['Idempotency-Key'];
      if (requestId === retired.clientRequestId) {
        // This fixture checks consumption of the terminal contract. The
        // backend route regression separately proves the deletion receipt.
        throw {
          response: {
            status: 422,
            data: {
              success: false,
              code: 'media_deleted',
              message: 'تم حذف هذا الملف',
              data: null,
            },
          },
        };
      }
      expect(requestId).toBe(sibling.clientRequestId);
      siblingStarted.resolve();
      return siblingAcknowledgement.promise;
    });
    const replay = replayPendingPortfolioMediaUploads();
    try {
      await siblingStarted.promise;
      expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual([sibling]);
      expect(RNFS.unlink).toHaveBeenCalledWith(retiredPath);
      expect(RNFS.unlink).not.toHaveBeenCalledWith(siblingPath);
      expect(mockDelete).not.toHaveBeenCalled();
      expect(
        mockPost.mock.calls.map(call => call[2].headers['Idempotency-Key']),
      ).toEqual([retired.clientRequestId, sibling.clientRequestId]);

      siblingAcknowledgement.resolve({
        data: {data: {...item.media[0], id: 72}},
      });
      await expect(replay).resolves.toMatchObject({
        attempted: 2,
        completed: 1,
        completedProjectIds: ['9'],
      });
      expect(await AsyncStorage.getItem(key)).toBeNull();
      expect(RNFS.unlink).toHaveBeenCalledWith(siblingPath);
      expect(mockPost).toHaveBeenCalledTimes(2);
      expect(mockDelete).not.toHaveBeenCalled();
      expect(projects.map(project => project.id)).toEqual(['9']);
    } finally {
      siblingAcknowledgement.resolve({data: {data: item.media[0]}});
      await replay;
    }
  });
});
