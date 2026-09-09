import {
  Alert,
  AppState,
  NativeModules,
  Platform,
  type AppStateStatus,
} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';
import Clipboard from '@react-native-clipboard/clipboard';

jest.mock('react-native-share', () => ({open: jest.fn()}));
jest.mock('@react-native-clipboard/clipboard', () => ({setString: jest.fn()}));
jest.mock('../src/components/VideoPlayer/attachmentDownloadNotice', () => ({
  ...jest.requireActual(
    '../src/components/VideoPlayer/attachmentDownloadNotice',
  ),
  beginAttachmentDownloadNotice: jest.fn(() => ({
    transferFinished: jest.fn(),
    dismiss: jest.fn(async () => undefined),
    release: jest.fn(),
  })),
  cancelAttachmentDownloadNotices: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/mapping', () => ({
  loadCourseLearningData: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({publicRequest: {get: jest.fn()}}));
let mockOwner = {scope: 'user-a', epoch: 1};
let mockAttempt = 0;
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockOwner}),
  assertAccountSessionBoundary: (owner: typeof mockOwner) => {
    if (owner.scope !== mockOwner.scope || owner.epoch !== mockOwner.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockAttempt).padStart(12, '0')}`,
}));

import {
  openCourseAttachment,
  quiescePrivateAttachmentDownloads,
} from '../src/components/VideoPlayer/attachmentActions';
import type {CourseAttachment} from '../src/components/VideoPlayer/types';
import {beginAttachmentDownloadNotice} from '../src/components/VideoPlayer/attachmentDownloadNotice';

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const flush = async () => {
  for (let turn = 0; turn < 50; turn += 1) await Promise.resolve();
};
const file = (id: string): CourseAttachment => ({
  id,
  courseId: '31',
  title: `ملف ${id}`,
  url: `https://cdn.example/${id}.pdf`,
  platform: 'mobile',
  temporary: true,
  expiresAt: '2099-01-01T00:00:00Z',
  fileName: `${id}.pdf`,
  mimeType: 'application/pdf',
  fileSizeBytes: 10,
  downloadVersion: 'version-1',
});
type SaveReceipt = Awaited<ReturnType<typeof Share.open>>;
const saved: SaveReceipt = {success: true, message: 'com.apple.DocumentsApp'};
type DownloadReceipt = Awaited<ReturnType<typeof RNFS.downloadFile>['promise']>;

describe('iOS attachment Save to Files presentation ownership', () => {
  const downloads: ReturnType<typeof deferred<DownloadReceipt>>[] = [];
  const pickerReceipts: ReturnType<typeof deferred<SaveReceipt>>[] = [];
  const stateListeners = new Set<(state: AppStateStatus) => void>();
  let nativeDelegate: ReturnType<typeof deferred<SaveReceipt>>;
  const changeState = (state: AppStateStatus) => {
    AppState.currentState = state;
    [...stateListeners].forEach(listener => listener(state));
  };

  beforeEach(async () => {
    mockOwner = {scope: 'user-a', epoch: 1};
    jest.replaceProperty(Platform, 'OS', 'ios');
    AppState.currentState = 'active';
    stateListeners.clear();
    jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((event, listener) => {
        if (event !== 'change')
          throw new Error('Unexpected AppState subscription');
        stateListeners.add(listener);
        return {remove: () => stateListeners.delete(listener)};
      });
    NativeModules.RoknDownloads = {};
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    jest.mocked(RNFS.exists).mockResolvedValue(false);
    jest.mocked(RNFS.readDir).mockResolvedValue([]);
    jest
      .mocked(RNFS.stat)
      .mockResolvedValue({size: 10} as Awaited<ReturnType<typeof RNFS.stat>>);
    jest.mocked(RNFS.getFSInfo).mockResolvedValue({
      freeSpace: 1024 ** 3,
      totalSpace: 2 * 1024 ** 3,
    });
    await quiescePrivateAttachmentDownloads();
    jest.clearAllMocks();
    downloads.length = 0;
    pickerReceipts.length = 0;
    jest.mocked(RNFS.downloadFile).mockImplementation(() => {
      const transfer = deferred<DownloadReceipt>();
      downloads.push(transfer);
      return {jobId: downloads.length, promise: transfer.promise};
    });
    // Installed RNShare.mm's saveToFiles branch assigns module-wide
    // resolveBlock/rejectBlock (281–282); either picker delegate callback
    // invokes the latest slot (376–388). This models that source contract,
    // not a real UIKit presentation or an iOS acceptance run.
    jest.mocked(Share.open).mockImplementation(() => {
      nativeDelegate = deferred<SaveReceipt>();
      pickerReceipts.push(nativeDelegate);
      return nativeDelegate.promise;
    });
  });

  afterEach(async () => {
    changeState('active');
    // Drain even after a RED assertion so no test-owned native promise leaks
    // into the next case. Real assertions below use only the active delegate.
    for (let turn = 0; turn < 5; turn += 1) {
      pickerReceipts.forEach(receipt => receipt.resolve(saved));
      await flush();
    }
    await quiescePrivateAttachmentDownloads();
    expect(stateListeners.size).toBe(0);
    jest.restoreAllMocks();
  });

  const finishDownload = async (index: number) => {
    downloads[index].resolve({
      jobId: index + 1,
      statusCode: 200,
      bytesWritten: 10,
    });
    await flush();
  };

  it('downloads two files in parallel but delivers each save acknowledgement to its own action', async () => {
    const first = openCourseAttachment(file('a'));
    const second = openCourseAttachment(file('b'));
    await flush();
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
    await finishDownload(0);
    expect(Share.open).toHaveBeenCalledTimes(1);
    await finishDownload(1);
    expect(Share.open).toHaveBeenCalledTimes(1);

    nativeDelegate.resolve(saved);
    await expect(first).resolves.toEqual({copied: false, downloaded: true});
    await flush();
    expect(Share.open).toHaveBeenCalledTimes(2);
    expect(jest.mocked(Share.open).mock.calls[1][0]?.url).toMatch(/\/b\.pdf$/);
    nativeDelegate.resolve(saved);
    await expect(second).resolves.toEqual({copied: false, downloaded: true});
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
  });

  it.each(['background', 'inactive'] as const)(
    'retains a completed file in %s and opens Save to Files once on return',
    async state => {
      const action = openCourseAttachment(file('a'));
      await flush();
      changeState(state);
      await finishDownload(0);
      expect(Share.open).not.toHaveBeenCalled();
      expect(RNFS.unlink).not.toHaveBeenCalledWith(
        expect.stringMatching(/\/attempt-[^/]+$/),
      );
      expect(stateListeners.size).toBe(1);

      changeState('active');
      changeState('active');
      await flush();
      expect(Share.open).toHaveBeenCalledTimes(1);
      expect(stateListeners.size).toBe(0);
      nativeDelegate.resolve(saved);
      await expect(action).resolves.toEqual({copied: false, downloaded: true});
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
    },
  );

  it.each(['success', 'failure'] as const)(
    'waits for its progress modal to dismiss before presenting the %s result',
    async outcome => {
      const dismissal = deferred<void>();
      const release = jest.fn();
      const dismiss = jest.fn(() => dismissal.promise);
      jest
        .mocked(beginAttachmentDownloadNotice)
        .mockReturnValueOnce({dismiss, release, transferFinished: jest.fn()});
      const action = openCourseAttachment(file('a'));
      await flush();
      if (outcome === 'success') await finishDownload(0);
      else downloads[0].reject(new Error('transfer failed'));
      await flush();
      expect(dismiss).toHaveBeenCalledTimes(1);
      expect(Share.open).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalledWith(
        'تعذّر تنزيل الملف',
        expect.anything(),
      );
      expect(release).not.toHaveBeenCalled();

      dismissal.resolve();
      await flush();
      if (outcome === 'success') {
        expect(Share.open).toHaveBeenCalledTimes(1);
        expect(release).not.toHaveBeenCalled();
        nativeDelegate.resolve(saved);
      } else {
        expect(Share.open).not.toHaveBeenCalled();
        expect(Alert.alert).toHaveBeenCalledWith(
          'تعذّر تنزيل الملف',
          expect.anything(),
        );
      }
      await expect(action).resolves.toEqual({
        copied: false,
        downloaded: outcome === 'success',
      });
      expect(release).toHaveBeenCalledTimes(1);
    },
  );

  it('checks foreground again when a queued file takes the native presentation slot', async () => {
    const first = openCourseAttachment(file('a'));
    const second = openCourseAttachment(file('b'));
    await flush();
    await finishDownload(0);
    await finishDownload(1);
    changeState('background');
    nativeDelegate.resolve(saved);
    await expect(first).resolves.toEqual({copied: false, downloaded: true});
    await flush();
    expect(Share.open).toHaveBeenCalledTimes(1);
    changeState('active');
    await flush();
    expect(Share.open).toHaveBeenCalledTimes(2);
    nativeDelegate.resolve(saved);
    await expect(second).resolves.toEqual({copied: false, downloaded: true});
  });

  it.each(['account retirement', 'user cancellation'] as const)(
    'releases an unpresented save on %s without waiting for the app to return',
    async reason => {
      const action = openCourseAttachment(file('a'));
      await flush();
      const cancel = jest.mocked(beginAttachmentDownloadNotice).mock
        .calls[0]?.[2];
      changeState('background');
      await finishDownload(0);
      expect(Share.open).not.toHaveBeenCalled();

      if (reason === 'account retirement') {
        await quiescePrivateAttachmentDownloads();
        mockOwner = {scope: 'user-b', epoch: 2};
      } else {
        cancel?.();
      }
      await expect(action).resolves.toEqual({copied: false, downloaded: false});
      expect(stateListeners.size).toBe(0);
      expect(Alert.alert).not.toHaveBeenCalledWith(
        'تعذّر تنزيل الملف',
        expect.anything(),
      );
      changeState('active');
      const current = openCourseAttachment(file('b'));
      await flush();
      await finishDownload(1);
      expect(Share.open).toHaveBeenCalledTimes(1);
      expect(jest.mocked(Share.open).mock.calls[0][0]?.url).toMatch(
        /\/b\.pdf$/,
      );
      nativeDelegate.resolve(saved);
      await expect(current).resolves.toEqual({copied: false, downloaded: true});
    },
  );

  it.each(['cancelled', 'rejected', 'synchronous failure'])(
    'releases the presentation slot after %s without losing the next file',
    async outcome => {
      if (outcome === 'synchronous failure') {
        jest.mocked(Share.open).mockImplementationOnce(() => {
          throw new Error('native picker unavailable');
        });
      }
      const first = openCourseAttachment(file('a'));
      const second = openCourseAttachment(file('b'));
      await flush();
      await finishDownload(0);
      await finishDownload(1);
      if (outcome !== 'synchronous failure') {
        expect(Share.open).toHaveBeenCalledTimes(1);
        nativeDelegate.reject(
          Object.assign(new Error('native picker result'), {
            code: outcome === 'cancelled' ? 'CANCELLED' : 'E_SAVE_FILE',
          }),
        );
      }
      await expect(first).resolves.toEqual({copied: false, downloaded: false});
      await flush();
      expect(Share.open).toHaveBeenCalledTimes(2);
      nativeDelegate.resolve(saved);
      await expect(second).resolves.toEqual({copied: false, downloaded: true});
      if (outcome === 'cancelled') {
        expect(Alert.alert).not.toHaveBeenCalledWith(
          'تعذّر تنزيل الملف',
          expect.anything(),
        );
      } else {
        expect(Alert.alert).toHaveBeenCalledWith(
          'تعذّر تنزيل الملف',
          expect.anything(),
        );
      }

      const retry = openCourseAttachment(file('a'));
      await flush();
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(3);
      await finishDownload(2);
      nativeDelegate.resolve(saved);
      await expect(retry).resolves.toEqual({copied: false, downloaded: true});
    },
  );

  it('skips a retired queued account and allows the next account after the active picker settles', async () => {
    const first = openCourseAttachment(file('a'));
    const waiting = openCourseAttachment(file('b'));
    await flush();
    await finishDownload(0);
    await finishDownload(1);
    expect(Share.open).toHaveBeenCalledTimes(1);

    await quiescePrivateAttachmentDownloads();
    mockOwner = {scope: 'user-b', epoch: 2};
    const current = openCourseAttachment(file('c'));
    await flush();
    await finishDownload(2);
    expect(Share.open).toHaveBeenCalledTimes(1);
    nativeDelegate.resolve(saved);
    await expect(first).resolves.toEqual({copied: false, downloaded: false});
    await expect(waiting).resolves.toEqual({copied: false, downloaded: false});
    await flush();
    expect(Share.open).toHaveBeenCalledTimes(2);
    expect(jest.mocked(Share.open).mock.calls[1][0]?.url).toMatch(/\/c\.pdf$/);
    nativeDelegate.resolve(saved);
    await expect(current).resolves.toEqual({copied: false, downloaded: true});
    expect(Alert.alert).not.toHaveBeenCalledWith(
      'تعذّر تنزيل الملف',
      expect.anything(),
    );
  });

  it('does not present a queued file cancelled through its own download action', async () => {
    const first = openCourseAttachment(file('a'));
    const waiting = openCourseAttachment(file('b'));
    await flush();
    const cancelWaiting = jest.mocked(beginAttachmentDownloadNotice).mock
      .calls[1]?.[2];
    expect(cancelWaiting).toBeDefined();
    await finishDownload(0);
    await finishDownload(1);
    cancelWaiting?.();
    nativeDelegate.resolve(saved);
    await expect(first).resolves.toEqual({copied: false, downloaded: true});
    await expect(waiting).resolves.toEqual({copied: false, downloaded: false});
    expect(Share.open).toHaveBeenCalledTimes(1);
  });

  it('keeps computer copy independent and coalesces a repeated tap on the active file', async () => {
    const first = openCourseAttachment(file('a'));
    const duplicate = openCourseAttachment(file('a'));
    await flush();
    await finishDownload(0);
    await expect(
      openCourseAttachment({...file('b'), platform: 'computer'}),
    ).resolves.toEqual({copied: true, downloaded: false});
    expect(Clipboard.setString).toHaveBeenCalledWith(file('b').url);
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
    expect(Share.open).toHaveBeenCalledTimes(1);
    nativeDelegate.resolve(saved);
    await expect(first).resolves.toEqual({copied: false, downloaded: true});
    await expect(duplicate).resolves.toEqual({copied: false, downloaded: true});
  });
});
