jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/cache',
  downloadFile: jest.fn(),
  cancelDownload: jest.fn(),
  unlink: jest.fn(async () => undefined),
}));

import {NativeModules} from 'react-native';
import RNFS from 'react-native-fs';
import {
  cancelAttachmentTransfers,
  downloadPrivateFile,
  enqueueNativeDownload,
} from '../src/components/VideoPlayer/attachmentTransfers';

const request = {
  url: 'https://cdn.example.test/file.pdf',
  title: 'ملفات الدرس',
  fileName: 'file.pdf',
  mimeType: 'application/pdf',
  stableKey: 'student:course:attachment:revision',
  expectedBytes: 100,
  external: false,
};

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(accept => {
    resolve = accept;
  });
  return {promise, resolve};
};

describe('attachment transfer ownership without UI', () => {
  const enqueue = jest.fn();
  const cancelIfActive = jest.fn(async () => true);

  beforeEach(() => {
    jest.useRealTimers();
    jest.clearAllMocks();
    NativeModules.RoknDownloads = {enqueue, enqueueExternal: enqueue, cancelIfActive};
  });

  afterEach(async () => {
    await cancelAttachmentTransfers();
    jest.useRealTimers();
  });

  it('registers accepted Android jobs itself so account shutdown cannot omit them', async () => {
    enqueue.mockResolvedValue({id: 42, status: 'started'});

    await expect(enqueueNativeDownload(request, () => true)).resolves.toEqual({
      id: 42,
      status: 'started',
    });
    expect(enqueue).toHaveBeenCalledWith(
      request.url, request.title, request.fileName, request.mimeType,
      request.stableKey, request.expectedBytes,
    );
    await cancelAttachmentTransfers();

    expect(cancelIfActive).toHaveBeenCalledTimes(1);
    expect(cancelIfActive).toHaveBeenCalledWith(42);
  });

  it('cancels a job returned after its caller lost ownership without handing it back', async () => {
    const native = deferred<{id: number}>();
    enqueue.mockReturnValue(native.promise);
    let current = true;
    const transfer = enqueueNativeDownload(request, () => current);
    current = false;
    native.resolve({id: 43});

    await expect(transfer).resolves.toBeNull();
    await cancelAttachmentTransfers();
    expect(cancelIfActive).toHaveBeenCalledTimes(1);
    expect(cancelIfActive).toHaveBeenCalledWith(43);
  });

  it('settles a timed out enqueue and retires a late native success', async () => {
    jest.useFakeTimers();
    const native = deferred<{id: number}>();
    enqueue.mockReturnValue(native.promise);
    const transfer = enqueueNativeDownload(request, () => true);
    const outcome = transfer.catch(error => error);

    await jest.advanceTimersByTimeAsync(12_000);
    expect(await outcome).toMatchObject({code: 'DOWNLOAD_ENQUEUE_TIMEOUT'});
    native.resolve({id: 44});
    await Promise.resolve();

    expect(cancelIfActive).toHaveBeenCalledWith(44);
  });

  it('settles cancelled private transfers even before the native promise completes', async () => {
    const native = deferred<{jobId: number; statusCode: number; bytesWritten: number}>();
    jest.mocked(RNFS.downloadFile).mockReturnValue({jobId: 45, promise: native.promise});
    const target = '/cache/rokn-attachments/account-a/attempt-45/file.pdf';
    const transfer = downloadPrivateFile(request.url, target);
    const outcome = transfer.promise.catch(error => error);

    transfer.cancel();
    expect(await outcome).toEqual(new Error('ATTACHMENT_DOWNLOAD_CANCELLED'));
    expect(RNFS.cancelDownload).toHaveBeenCalledWith(45);
    native.resolve({jobId: 45, statusCode: 200, bytesWritten: 100});
    await Promise.resolve();

    expect(RNFS.unlink).toHaveBeenCalledWith(target);
  });
});
