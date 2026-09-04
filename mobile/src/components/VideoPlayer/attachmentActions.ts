import Clipboard from '@react-native-clipboard/clipboard';
import {
  Alert,
  Linking,
  NativeModules,
  PermissionsAndroid,
  Platform,
} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {CourseAttachment} from './types';
import {loadCourseLearningData} from './courseLearning/mapping';
import {remainingServerMilliseconds} from '../../utils/serverClock';
import {settleWithin} from '../../utils/settleWithin';
import {safeFilenameStem} from '../../utils/unicodeText';
import {nativeAttachmentRecovery} from './attachmentDownloadPolicy';

const downloadFlights = new Map<
  string,
  Promise<{copied: boolean; downloaded: boolean; downloadId?: number}>
>();
const activePrivateDownloadJobs = new Set<number>();
const activeAndroidDownloadIds = new Set<number>();
let privateDownloadGeneration = 0;
type AttachmentResult = {
  copied: boolean;
  downloaded: boolean;
  downloadId?: number;
};
type AttachmentOperation = {
  boundary: AccountSessionBoundary;
  generation: number;
};
const STORAGE_RESERVE_BYTES = 32 * 1024 * 1024;
const IOS_DOWNLOAD_TIMEOUT_MS = 30 * 60 * 1000;
const NATIVE_ENQUEUE_TIMEOUT_MS = 12_000;
const MIME_EXTENSIONS: Record<string, string> = {
  'application/pdf': 'pdf',
  'application/zip': 'zip',
  'application/x-zip-compressed': 'zip',
  'application/msword': 'doc',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
    'docx',
  'application/vnd.ms-excel': 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'application/vnd.ms-powerpoint': 'ppt',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation':
    'pptx',
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'text/plain': 'txt',
};
const EXTENSION_MIME: Record<string, string> = Object.fromEntries(
  Object.entries(MIME_EXTENSIONS).map(([mime, extension]) => [extension, mime]),
);

const normalizeExtension = (value?: string) => {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .split(';')[0];
  if (MIME_EXTENSIONS[normalized]) return MIME_EXTENSIONS[normalized];
  const tail = normalized.includes('/')
    ? normalized.split('/').pop() || ''
    : normalized;
  const clean = tail.replace(/^\./, '').replace(/[^a-z0-9]/g, '');
  if (!clean || clean.length > 8) {
    return '';
  }
  return clean === 'jpeg' ? 'jpg' : clean === 'plain' ? 'txt' : clean;
};

const mimeTypeFor = (attachment: CourseAttachment, fileName: string) => {
  const supplied = String(attachment.mimeType || attachment.fileType || '')
    .trim()
    .toLowerCase()
    .split(';')[0];
  if (supplied.includes('/')) return supplied;
  const extension = normalizeExtension(fileName.split('.').pop());
  return EXTENSION_MIME[extension] || 'application/octet-stream';
};

const safeFileName = (attachment: CourseAttachment) => {
  const fromUrl = attachment.url.split('?')[0].split('/').pop();
  const extensionFromUrl = fromUrl?.includes('.')
    ? normalizeExtension(fromUrl.split('.').pop())
    : '';
  const extension =
    extensionFromUrl || normalizeExtension(attachment.fileType) || 'file';
  const cleanTitle = safeFilenameStem(attachment.title);
  return `${cleanTitle || `rokn-${attachment.id}`}.${extension}`;
};

const isAllowedRemoteUrl = (value: string) => {
  try {
    const parsed = new URL(value) as unknown as {
      hostname: string;
      protocol: string;
    };
    return (
      Boolean(parsed.hostname) &&
      (parsed.protocol === 'https:' || (__DEV__ && parsed.protocol === 'http:'))
    );
  } catch {
    return false;
  }
};

const assertAttachmentOwner = (operation: AttachmentOperation) => {
  if (operation.generation !== privateDownloadGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(operation.boundary);
};

const attachmentOwnerIsActive = (operation: AttachmentOperation) => {
  try {
    assertAttachmentOwner(operation);
    return true;
  } catch {
    return false;
  }
};

const emptyResult = (): AttachmentResult => ({
  copied: false,
  downloaded: false,
});

const openRemoteDownload = async (
  url: string,
  operation: AttachmentOperation,
) => {
  try {
    assertAttachmentOwner(operation);
    const canOpen = await Linking.canOpenURL(url);
    assertAttachmentOwner(operation);
    if (!canOpen) return false;
    await Linking.openURL(url);
    assertAttachmentOwner(operation);
    return true;
  } catch {
    return false;
  }
};

const allowPublicAndroidDownload = async () => {
  if (Platform.OS !== 'android' || Number(Platform.Version) >= 29) {
    return true;
  }
  const permission = PermissionsAndroid.PERMISSIONS.WRITE_EXTERNAL_STORAGE;
  if (await PermissionsAndroid.check(permission)) return true;
  return (
    (await PermissionsAndroid.request(permission)) ===
    PermissionsAndroid.RESULTS.GRANTED
  );
};

const attachmentUrlNeedsRefresh = (attachment: CourseAttachment) => {
  if (!attachment.temporary) return false;
  const remaining = remainingServerMilliseconds(attachment.expiresAt);
  const safeWindow = attachment.platform === 'computer' ? 10 * 60_000 : 90_000;
  return remaining === null || remaining <= safeWindow;
};

const refreshAttachment = async (
  attachment: CourseAttachment,
  operation: AttachmentOperation,
) => {
  if (!attachment.courseId) return null;
  assertAttachmentOwner(operation);
  const {course} = await loadCourseLearningData(attachment.courseId, {
    reconcilePending: false,
  });
  assertAttachmentOwner(operation);
  return course.attachments.find(item => item.id === attachment.id) || null;
};

const usableAttachment = async (
  attachment: CourseAttachment,
  operation: AttachmentOperation,
  forceRefresh = false,
) => {
  assertAttachmentOwner(operation);
  if (!forceRefresh && !attachmentUrlNeedsRefresh(attachment)) {
    return attachment;
  }
  const refreshed = await refreshAttachment(attachment, operation);
  if (!refreshed || !isAllowedRemoteUrl(refreshed.url)) {
    throw new Error('ATTACHMENT_URL_REFRESH_FAILED');
  }
  return refreshed;
};

const attachmentFlightKey = (
  attachment: CourseAttachment,
  accountScope: string,
) =>
  [
    accountScope,
    attachment.courseId || 'course',
    attachment.id,
    attachment.downloadVersion || 'current',
  ].join('|');

const nativeStableKey = (
  attachment: CourseAttachment,
  accountScope: string,
) => {
  return [
    accountScope,
    attachment.courseId || 'course',
    attachment.id,
    attachment.downloadVersion || attachment.url.split('?')[0],
  ].join(':');
};

const hasLocalSpace = async (expectedBytes?: number) => {
  if (!expectedBytes || expectedBytes <= 0) return true;
  try {
    const {freeSpace} = await RNFS.getFSInfo();
    // iOS stages one copy and Save to Files may create the durable second copy.
    return freeSpace >= expectedBytes * 2 + STORAGE_RESERVE_BYTES;
  } catch {
    return true;
  }
};

const downloadPrivateFile = (fromUrl: string, toFile: string) => {
  const task = RNFS.downloadFile({
    fromUrl,
    toFile,
    background: true,
    discretionary: true,
    readTimeout: 45_000,
    backgroundTimeout: IOS_DOWNLOAD_TIMEOUT_MS,
  });
  activePrivateDownloadJobs.add(task.jobId);
  return {
    jobId: task.jobId,
    promise: task.promise.finally(() => {
      activePrivateDownloadJobs.delete(task.jobId);
    }),
  };
};

const nativeDownloadId = (value: unknown) => {
  const downloadId = Number(
    value && typeof value === 'object' ? (value as {id?: unknown}).id : value,
  );
  return Number.isFinite(downloadId) ? downloadId : undefined;
};

const enqueueNativeDownload = (
  args: [string, string, string, string, string, number],
  operation: AttachmentOperation,
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
      enqueueResult = Promise.resolve(
        NativeModules.RoknDownloads.enqueue(...args),
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
            void NativeModules.RoknDownloads.cancelIfActive?.(downloadId);
          }
          return;
        }
        settled = true;
        clearTimeout(timer);
        if (!attachmentOwnerIsActive(operation)) {
          const downloadId = nativeDownloadId(value);
          if (downloadId !== undefined) {
            void NativeModules.RoknDownloads.cancelIfActive?.(downloadId);
          }
          resolve(null);
          return;
        }
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

const openCourseAttachmentInternal = async (
  attachment: CourseAttachment,
  operation: AttachmentOperation,
  signedUrlRefreshAttempted = false,
) => {
  let currentAttachment: CourseAttachment;
  try {
    currentAttachment = await usableAttachment(attachment, operation);
  } catch {
    if (!attachmentOwnerIsActive(operation)) {
      return emptyResult();
    }
    Alert.alert('تعذّر تجهيز الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return emptyResult();
  }

  if (!attachmentOwnerIsActive(operation)) {
    return emptyResult();
  }

  if (!isAllowedRemoteUrl(currentAttachment.url)) {
    Alert.alert('الرابط غير متاح', 'حاول مرة أخرى\nأو تواصل مع الدعم');
    return emptyResult();
  }

  if (currentAttachment.platform === 'computer') {
    Clipboard.setString(currentAttachment.url);
    const temporaryLink = currentAttachment.temporary;
    Alert.alert(
      'تم نسخ الرابط',
      temporaryLink
        ? 'افتحه على الكمبيوتر الآن\nوإذا انتهى الرابط انسخه من جديد'
        : 'افتح الرابط على الكمبيوتر لتنزيل الملفات',
    );
    return {copied: true, downloaded: false};
  }

  if (currentAttachment.external) {
    if (await openRemoteDownload(currentAttachment.url, operation)) {
      return emptyResult();
    }
    if (!attachmentOwnerIsActive(operation)) return emptyResult();
    Alert.alert('تعذّر فتح الرابط', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return emptyResult();
  }

  const fileName = safeFileName(currentAttachment);
  if (Platform.OS === 'android' && NativeModules.RoknDownloads?.enqueue) {
    try {
      const canSaveToDownloads = await allowPublicAndroidDownload();
      if (!attachmentOwnerIsActive(operation)) {
        return emptyResult();
      }
      if (!canSaveToDownloads) {
        Alert.alert(
          'تعذّر حفظ الملف',
          'اسمح بحفظ الملفات لتنزيل مرفق الكورس على هاتفك',
        );
        return emptyResult();
      }
      const nativeResult = await enqueueNativeDownload(
        [
          currentAttachment.url,
          currentAttachment.title,
          fileName,
          mimeTypeFor(currentAttachment, fileName),
          nativeStableKey(currentAttachment, operation.boundary.scope),
          currentAttachment.fileSizeBytes || 0,
        ],
        operation,
      );
      const downloadId = nativeDownloadId(nativeResult);
      const status =
        nativeResult && typeof nativeResult === 'object'
          ? String((nativeResult as {status?: unknown}).status || 'started')
          : 'started';
      if (downloadId !== undefined) {
        activeAndroidDownloadIds.add(downloadId);
        // New native builds can enumerate persisted DownloadManager jobs.
        // Keep only a bounded compatibility window for older native shells.
        while (activeAndroidDownloadIds.size > 128) {
          const oldest = activeAndroidDownloadIds.values().next().value;
          if (typeof oldest !== 'number') break;
          activeAndroidDownloadIds.delete(oldest);
        }
      }
      if (!attachmentOwnerIsActive(operation)) {
        if (downloadId !== undefined) {
          void NativeModules.RoknDownloads.cancelIfActive?.(downloadId);
        }
        return emptyResult();
      }
      if (status === 'running') {
        Alert.alert('التنزيل مستمر', 'تابع التقدم من إشعار التنزيل');
      } else if (status === 'completed') {
        Alert.alert('الملف في التنزيلات', 'افتحه بأي تطبيق مناسب');
      } else if (status !== 'opened') {
        Alert.alert('بدأ التنزيل', 'تابع التقدم من إشعار التنزيل');
      }
      return {
        copied: false,
        downloaded: true,
        downloadId,
      };
    } catch (error: unknown) {
      const code =
        error && typeof error === 'object' && 'code' in error
          ? String((error as {code?: unknown}).code || '')
          : '';
      const recovery = nativeAttachmentRecovery(
        code,
        signedUrlRefreshAttempted,
      );
      if (recovery === 'storage') {
        if (!attachmentOwnerIsActive(operation)) return emptyResult();
        Alert.alert(
          'المساحة لا تكفي',
          'وفّر مساحة على الهاتف ثم حاول مرة أخرى',
        );
        return emptyResult();
      }
      if (recovery === 'refresh') {
        try {
          const refreshed = await usableAttachment(
            currentAttachment,
            operation,
            true,
          );
          if (!attachmentOwnerIsActive(operation)) {
            return emptyResult();
          }
          return openCourseAttachmentInternal(refreshed, operation, true);
        } catch {
          if (!attachmentOwnerIsActive(operation)) return emptyResult();
          Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
          return emptyResult();
        }
      }
      if (
        code === 'DOWNLOAD_RETRY_REQUIRES_REFRESH' &&
        signedUrlRefreshAttempted
      ) {
        if (!attachmentOwnerIsActive(operation)) return emptyResult();
        Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
        return emptyResult();
      }
      // Fall through to the direct URL so a native integration issue never blocks the learner.
    }
  }

  // Android's system DownloadManager is the durable owner of private files.
  // Only a public external link may fall back to another app; a signed course
  // URL must remain cancellable at an account boundary.
  if (Platform.OS === 'android') {
    if (
      !currentAttachment.temporary &&
      (await openRemoteDownload(currentAttachment.url, operation))
    ) {
      return {copied: false, downloaded: true};
    }
    if (!attachmentOwnerIsActive(operation)) return emptyResult();
    Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return emptyResult();
  }

  // iOS needs a local staging file before the system Save/Share sheet. Keep
  // that copy in cache and remove it after the handoff so every attachment
  // does not leave a hidden duplicate inside the app.
  const hasSpace = await hasLocalSpace(currentAttachment.fileSizeBytes);
  if (!attachmentOwnerIsActive(operation)) {
    return emptyResult();
  }
  if (!hasSpace) {
    Alert.alert('المساحة لا تكفي', 'وفّر مساحة على الهاتف ثم حاول مرة أخرى');
    return emptyResult();
  }
  const cacheFolder = `${
    RNFS.CachesDirectoryPath
  }/rokn-attachments/${nativeStableKey(
    currentAttachment,
    operation.boundary.scope,
  )
    .replace(/[^a-zA-Z0-9_-]/g, '_')
    .slice(0, 120)}`;
  const target = `${cacheFolder}/${fileName}`;
  let cancelled = false;
  let activeJobId: number | undefined;

  try {
    // A cancelled/failed attempt can leave a partial cache file with the same
    // name. A full byte-for-byte staging file can also be the result of an iOS
    // background transfer that finished after the process was evicted.
    await RNFS.mkdir(cacheFolder);
    if (!attachmentOwnerIsActive(operation)) {
      await RNFS.unlink(cacheFolder).catch(() => undefined);
      return emptyResult();
    }
    const stagedSize = (await RNFS.exists(target))
      ? Number((await RNFS.stat(target)).size)
      : 0;
    assertAttachmentOwner(operation);
    const recoveredBackgroundDownload = Boolean(
      currentAttachment.fileSizeBytes &&
        stagedSize === currentAttachment.fileSizeBytes,
    );
    let result: {jobId: number; statusCode: number; bytesWritten: number};
    let download: ReturnType<typeof RNFS.downloadFile> | undefined;
    if (recoveredBackgroundDownload) {
      result = {jobId: -1, statusCode: 200, bytesWritten: stagedSize};
    } else {
      await RNFS.unlink(target).catch(() => undefined);
      assertAttachmentOwner(operation);
      download = downloadPrivateFile(currentAttachment.url, target);
      activeJobId = download.jobId;
      Alert.alert(
        'جارٍ تنزيل الملف',
        currentAttachment.fileSize
          ? `${currentAttachment.fileSize}\nسنفتح خيارات الحفظ عند اكتماله`
          : 'سنفتح خيارات الحفظ عند اكتماله',
        [
          {
            text: 'إلغاء',
            style: 'cancel',
            onPress: () => {
              cancelled = true;
              if (activeJobId !== undefined) RNFS.stopDownload(activeJobId);
            },
          },
          {text: 'إخفاء'},
        ],
      );
      result = await download.promise;
    }
    if (cancelled || !attachmentOwnerIsActive(operation)) {
      await RNFS.unlink(cacheFolder).catch(() => undefined);
      return emptyResult();
    }
    if (
      currentAttachment.temporary &&
      [401, 403, 404, 410].includes(result.statusCode)
    ) {
      await RNFS.unlink(target).catch(() => undefined);
      currentAttachment = await usableAttachment(
        currentAttachment,
        operation,
        true,
      );
      download = downloadPrivateFile(currentAttachment.url, target);
      activeJobId = download.jobId;
      result = await download.promise;
      if (cancelled || !attachmentOwnerIsActive(operation)) {
        await RNFS.unlink(cacheFolder).catch(() => undefined);
        return emptyResult();
      }
    }
    if (result.statusCode >= 200 && result.statusCode < 300) {
      const localSize = Number((await RNFS.stat(target)).size);
      assertAttachmentOwner(operation);
      if (!Number.isFinite(localSize) || localSize <= 0) {
        throw new Error('ATTACHMENT_EMPTY_DOWNLOAD');
      }
      if (
        currentAttachment.fileSizeBytes &&
        localSize !== currentAttachment.fileSizeBytes
      ) {
        throw new Error('ATTACHMENT_TRUNCATED_DOWNLOAD');
      }
      try {
        assertAttachmentOwner(operation);
        const handoff = await Share.open({
          url: `file://${target}`,
          saveToFiles: true,
          failOnCancel: false,
          title: currentAttachment.title,
        });
        if (!handoff.success || handoff.dismissedAction) {
          return emptyResult();
        }
        assertAttachmentOwner(operation);
      } finally {
        await RNFS.unlink(cacheFolder).catch(() => undefined);
      }
      return {copied: false, downloaded: true};
    }
    throw new Error(`Download failed (${result.statusCode})`);
  } catch {
    await RNFS.unlink(cacheFolder).catch(() => undefined);
    if (cancelled || !attachmentOwnerIsActive(operation)) {
      return emptyResult();
    }
    if (
      !currentAttachment.temporary &&
      (await openRemoteDownload(currentAttachment.url, operation))
    ) {
      return {copied: false, downloaded: true};
    }
    if (attachmentOwnerIsActive(operation)) {
      Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    }
    return emptyResult();
  }
};

export const openCourseAttachment = async (
  attachment: CourseAttachment,
): Promise<AttachmentResult> => {
  let boundary: AccountSessionBoundary;
  try {
    boundary = await captureAccountSessionBoundary();
  } catch {
    return emptyResult();
  }
  const generation = privateDownloadGeneration;
  const key = attachmentFlightKey(attachment, boundary.scope);
  const existing = downloadFlights.get(key);
  if (existing) return existing;
  const operation: AttachmentOperation = {boundary, generation};
  if (!attachmentOwnerIsActive(operation)) return emptyResult();
  // Every caller gets the same user-facing terminal contract. Most expected
  // failures are handled close to their recovery path above; this boundary
  // catches platform/native surprises so a tap can never fail silently just
  // because a screen used fire-and-forget semantics.
  const flight = openCourseAttachmentInternal(attachment, operation).catch(
    () => {
      if (
        attachmentOwnerIsActive(operation) &&
        downloadFlights.get(key) === flight
      ) {
        Alert.alert('تعذّر فتح الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
      }
      return emptyResult();
    },
  );
  downloadFlights.set(key, flight);
  const clear = () => {
    if (downloadFlights.get(key) === flight) downloadFlights.delete(key);
  };
  void flight.then(clear, clear);
  return flight;
};

/** Stop private transfers before account/session storage changes owner. */
export const quiescePrivateAttachmentDownloads = async (): Promise<void> => {
  privateDownloadGeneration += 1;
  downloadFlights.clear();
  activePrivateDownloadJobs.forEach(jobId => {
    try {
      RNFS.stopDownload(jobId);
    } catch {
      // A transfer that finished between snapshot and cancellation is safe.
    }
  });
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
