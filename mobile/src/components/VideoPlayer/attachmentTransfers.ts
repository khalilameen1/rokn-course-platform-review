import {NativeModules} from 'react-native';
import RNFS from 'react-native-fs';
import {settleWithin} from '../../utils/settleWithin';
import {attachmentResponseIsHtml} from './attachmentMetadata';

// Own native transfer settlement and retired staging paths, never UI/account state.
const activePrivateDownloadJobs = new Map<number, () => void>();
const retiredPrivateDownloadTargets = new Set<string>();
const activeAndroidDownloadIds = new Set<number>();
const STORAGE_RESERVE_BYTES = 32 * 1024 * 1024;
const IOS_DOWNLOAD_TIMEOUT_MS = 30 * 60 * 1000;
const NATIVE_ENQUEUE_TIMEOUT_MS = 12_000;

export const hasLocalSpace = async (expectedBytes?: number) => {
  if (!expectedBytes || expectedBytes <= 0) return true;
  try {
    const {freeSpace} = await RNFS.getFSInfo();
    // iOS stages one copy and Save to Files may create the durable second copy.
    return freeSpace >= expectedBytes * 2 + STORAGE_RESERVE_BYTES;
  } catch {
    return true;
  }
};

export const downloadPrivateFile = (fromUrl: string, toFile: string) => {
  let settled = false;
  let jobId: number | undefined;
  let resolveTransfer!: (
    result: Awaited<ReturnType<typeof RNFS.downloadFile>['promise']>,
  ) => void;
  let rejectTransfer!: (error: unknown) => void;
  const promise = new Promise<
    Awaited<ReturnType<typeof RNFS.downloadFile>['promise']>
  >((resolve, reject) => {
    resolveTransfer = resolve;
    rejectTransfer = reject;
  });
  const fail = (error: unknown) => {
    if (settled) return false;
    settled = true;
    retiredPrivateDownloadTargets.add(toFile);
    if (jobId !== undefined) activePrivateDownloadJobs.delete(jobId);
    rejectTransfer(error);
    return true;
  };
  const abandon = (id = jobId) => {
    if (id === undefined) return;
    try {
      RNFS.cancelDownload(id);
    } catch {
      // JS owns settlement even if the native cancellation cannot acknowledge it.
    }
  };
  const cancel = () => {
    if (fail(new Error('ATTACHMENT_DOWNLOAD_CANCELLED'))) abandon();
  };
  const task = RNFS.downloadFile({
    fromUrl,
    toFile,
    background: true,
    discretionary: true,
    readTimeout: 45_000,
    backgroundTimeout: IOS_DOWNLOAD_TIMEOUT_MS,
    begin: response => {
      if (settled) return;
      const mime = Object.entries(response.headers || {}).find(
        ([name]) => name.toLowerCase() === 'content-type',
      )?.[1];
      if (attachmentResponseIsHtml(mime)) {
        fail(new Error('ATTACHMENT_HOST_PAGE'));
        abandon(response.jobId);
      }
    },
    resumable: () => {
      // RNFS keeps its promise pending when iOS produces resumeData, even on
      // cancellation. A later retry must recheck access/source, not resume a
      // cancelled or old-account task behind the current action.
      if (fail(new Error('ATTACHMENT_DOWNLOAD_INTERRUPTED'))) abandon();
    },
  });
  jobId = task.jobId;
  if (!settled) activePrivateDownloadJobs.set(jobId, cancel);
  void task.promise.then(
    result => {
      if (settled) {
        // The target belongs only to this attempt, never to a newer retry.
        void RNFS.unlink(toFile)
          .then(() => retiredPrivateDownloadTargets.delete(toFile))
          .catch(() => undefined);
        return;
      }
      settled = true;
      activePrivateDownloadJobs.delete(task.jobId);
      resolveTransfer(result);
    },
    error => {
      fail(error);
    },
  );
  return {jobId: task.jobId, promise, cancel};
};

export const completedPrivateDownloadFolder = async (
  root: string,
  fileName: string,
  expectedBytes?: number,
): Promise<string | null> => {
  if (!expectedBytes || expectedBytes <= 0) return null;
  // Preserve the old fixed target and completed background attempts, looking
  // only inside this account/attachment/version root, not other downloads.
  const entries = await RNFS.readDir(root).catch(() => []);
  const folders = [
    root,
    ...entries
      .filter(
        entry =>
          entry.isDirectory() && /^attempt-[a-f0-9-]{36}$/.test(entry.name),
      )
      .map(entry => `${root}/${entry.name}`),
  ];
  for (const folder of folders) {
    const target = `${folder}/${fileName}`;
    // A cancelled task may still produce its final native callback/file. Do
    // not adopt that path while its old owner can still clean it up.
    if (retiredPrivateDownloadTargets.has(target)) continue;
    try {
      if (
        (await RNFS.exists(target)) &&
        Number((await RNFS.stat(target)).size) === expectedBytes
      ) {
        return folder;
      }
    } catch {
      // An evicted/incomplete attempt is not a reusable file.
    }
  }
  return null;
};

export const nativeDownloadId = (value: unknown) => {
  const downloadId = Number(
    value && typeof value === 'object' ? (value as {id?: unknown}).id : value,
  );
  return Number.isFinite(downloadId) ? downloadId : undefined;
};

export const cancelNativeDownloadIfActive = async (downloadId: number) => {
  try {
    await NativeModules.RoknDownloads.cancelIfActive?.(downloadId);
  } catch {
    // A retired JS action stays settled even if DownloadManager cannot cancel.
  }
};

type NativeDownloadRequest = {
  url: string;
  title: string;
  fileName: string;
  mimeType: string;
  stableKey: string;
  expectedBytes: number;
  external: boolean;
};

export const enqueueNativeDownload = (
  request: NativeDownloadRequest,
  isCurrent: () => boolean,
) =>
  new Promise<unknown>((resolve, reject) => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      reject(
        Object.assign(new Error('DOWNLOAD_ENQUEUE_TIMEOUT'), {
          code: 'DOWNLOAD_ENQUEUE_TIMEOUT',
        }),
      );
    }, NATIVE_ENQUEUE_TIMEOUT_MS);

    let enqueueResult: Promise<unknown>;
    try {
      const enqueue = request.external
        ? NativeModules.RoknDownloads.enqueueExternal
        : NativeModules.RoknDownloads.enqueue;
      enqueueResult = Promise.resolve(
        enqueue(
          request.url,
          request.title,
          request.fileName,
          request.mimeType,
          request.stableKey,
          request.expectedBytes,
        ),
      );
    } catch (error) {
      clearTimeout(timer);
      settled = true;
      reject(error);
      return;
    }
    void enqueueResult.then(
      value => {
        if (settled) {
          const downloadId = nativeDownloadId(value);
          if (downloadId !== undefined) {
            void cancelNativeDownloadIfActive(downloadId);
          }
          return;
        }
        settled = true;
        clearTimeout(timer);
        if (!isCurrent()) {
          const downloadId = nativeDownloadId(value);
          if (downloadId !== undefined) {
            void cancelNativeDownloadIfActive(downloadId);
          }
          resolve(null);
          return;
        }
        const downloadId = nativeDownloadId(value);
        if (downloadId !== undefined) rememberNativeDownload(downloadId);
        resolve(value);
      },
      error => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        reject(error);
      },
    );
  });

const rememberNativeDownload = (downloadId: number): void => {
  activeAndroidDownloadIds.add(downloadId);
  // New native builds enumerate persisted jobs; retain a bounded fallback for old shells.
  while (activeAndroidDownloadIds.size > 128) {
    const oldest = activeAndroidDownloadIds.values().next().value;
    if (typeof oldest !== 'number') break;
    activeAndroidDownloadIds.delete(oldest);
  }
};

/** Called only after the action owner has invalidated its account generation. */
export const cancelAttachmentTransfers = async (): Promise<void> => {
  activePrivateDownloadJobs.forEach(cancel => cancel());
  activePrivateDownloadJobs.clear();
  if (NativeModules.RoknDownloads?.cancelIfActive) {
    await settleWithin(
      Promise.all(
        [...activeAndroidDownloadIds].map(downloadId =>
          NativeModules.RoknDownloads.cancelIfActive(downloadId).catch(
            () => undefined,
          ),
        ),
      ).then(() => undefined),
      undefined,
      1500,
    );
  }
  if (NativeModules.RoknDownloads?.cancelAllActive) {
    await settleWithin(
      Promise.resolve(NativeModules.RoknDownloads.cancelAllActive()).catch(
        () => undefined,
      ),
      undefined,
      1500,
    );
  }
  activeAndroidDownloadIds.clear();
  await settleWithin(
    RNFS.unlink(`${RNFS.CachesDirectoryPath}/rokn-attachments`).catch(
      () => undefined,
    ),
    undefined,
    1500,
  );
};
