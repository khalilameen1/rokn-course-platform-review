import Clipboard from '@react-native-clipboard/clipboard';
import {
  Alert,
  Linking,
  NativeModules,
  PermissionsAndroid,
  Platform,
} from 'react-native';
import RNFS from 'react-native-fs';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {CourseAttachment} from './types';
import {publicRequest, type RoknRequestConfig} from '../../constants/api';
import {loadCourseLearningData} from './courseLearning/mapping';
import {mapCourseAttachments} from './courseLearning/coursePayload';
import {asRecord} from './courseLearning/shared';
import {remainingServerMilliseconds} from '../../utils/serverClock';
import {settleWithin} from '../../utils/settleWithin';
import {safeFilenameStem} from '../../utils/unicodeText';
import {secureRandomUuid} from '../../utils/secureRandom';
import {nativeAttachmentRecovery} from './attachmentDownloadPolicy';
import {
  cancelPendingAttachmentSaves,
  saveAttachmentToFiles,
} from './attachmentSavePresentation';
import {
  attachmentDownloadIdentity,
  beginAttachmentDownloadNotice,
  cancelAttachmentDownloadNotices,
} from './attachmentDownloadNotice';
import {
  attachmentHeaderFilename,
  attachmentPrefixIsHtml,
  attachmentResponseIsHtml,
  safeAttachmentName,
  type AttachmentMetadata,
} from './attachmentMetadata';

const downloadFlights = new Map<
  string,
  Promise<{copied: boolean; downloaded: boolean; downloadId?: number}>
>();
const activePrivateDownloadJobs = new Map<number, () => void>();
const retiredPrivateDownloadTargets = new Set<string>();
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
  downloadIdentity: string;
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
  if (attachment.fileName) return safeAttachmentName(attachment.fileName);
  const fromUrl = attachment.url.split('?')[0].split('/').pop();
  const extensionFromUrl = fromUrl?.includes('.')
    ? normalizeExtension(fromUrl.split('.').pop())
    : '';
  if (fromUrl && extensionFromUrl) {
    try {
      return safeAttachmentName(decodeURIComponent(fromUrl));
    } catch {
      return safeAttachmentName(fromUrl);
    }
  }
  const extension =
    extensionFromUrl ||
    normalizeExtension(attachment.fileType) ||
    MIME_EXTENSIONS[String(attachment.mimeType || '').split(';')[0]] ||
    '';
  if (/\.[a-z0-9]{1,10}$/i.test(attachment.title)) {
    return safeAttachmentName(attachment.title);
  }
  const cleanTitle = safeFilenameStem(attachment.title);
  return `${cleanTitle || `rokn-${attachment.id}`}${
    extension ? `.${extension}` : ''
  }`;
};

const isAllowedRemoteUrl = (value: string) => {
  try {
    const parsed = new URL(value) as unknown as {
      hostname: string;
      protocol: string;
      username: string;
      password: string;
    };
    return (
      Boolean(parsed.hostname) &&
      !parsed.username &&
      !parsed.password &&
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
  const endpoint = /^\/api\/v1\/courses\/([1-9]\d*)\/pdfs\/([1-9]\d*)$/.exec(
    attachment.downloadRefreshEndpoint || '',
  );
  if (
    endpoint &&
    endpoint[1] === attachment.courseId &&
    endpoint[2] === attachment.id
  ) {
    // The endpoint resolves published-revision aliases even when the original
    // card's attachment ID no longer appears in a full course response.
    const response = await publicRequest.get(
      `courses/${endpoint[1]}/pdfs/${endpoint[2]}`,
      {
        timeout: 10_000,
        roknNetworkRetryCount: Number.MAX_SAFE_INTEGER,
      } as RoknRequestConfig,
    );
    assertAttachmentOwner(operation);
    return (
      mapCourseAttachments(
        [asRecord(asRecord(response.data).data)],
        'mobile',
        attachment.courseId,
      )[0] || null
    );
  }
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

const completedPrivateDownloadFolder = async (
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

const nativeDownloadId = (value: unknown) => {
  const downloadId = Number(
    value && typeof value === 'object' ? (value as {id?: unknown}).id : value,
  );
  return Number.isFinite(downloadId) ? downloadId : undefined;
};

const cancelNativeDownloadIfActive = async (downloadId: number) => {
  try {
    await NativeModules.RoknDownloads.cancelIfActive?.(downloadId);
  } catch {
    // A retired JS action stays settled even if DownloadManager cannot cancel.
  }
};

const enqueueNativeDownload = (
  args: [string, string, string, string, string, number],
  operation: AttachmentOperation,
  external = false,
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
      const enqueue = external
        ? NativeModules.RoknDownloads.enqueueExternal
        : NativeModules.RoknDownloads.enqueue;
      enqueueResult = Promise.resolve(enqueue(...args));
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
        if (!attachmentOwnerIsActive(operation)) {
          const downloadId = nativeDownloadId(value);
          if (downloadId !== undefined) {
            void cancelNativeDownloadIfActive(downloadId);
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

const showExternalDownloadFallback = (
  attachment: CourseAttachment,
  operation: AttachmentOperation,
) => {
  if (!attachmentOwnerIsActive(operation)) return;
  Alert.alert(
    'تعذّر التنزيل المباشر',
    'افتح المصدر لإكمال التنزيل\nقد يتطلب تسجيل دخول أو تأكيد',
    [
      {text: 'إلغاء', style: 'cancel'},
      {
        text: 'فتح المصدر',
        onPress: () => {
          void (async () => {
            try {
              // The prompt can remain open across a publication or access
              // change. Its follow-up tap owns a fresh descriptor too.
              const current = await usableAttachment(
                attachment,
                operation,
                Boolean(attachment.downloadRefreshEndpoint),
              );
              if (current.platform === 'computer') {
                await openCourseAttachmentInternal(current, operation, true);
                return;
              }
              if (!current.external) {
                Alert.alert(
                  'تم تحديث المرفق',
                  'أصبح ملفًا للتنزيل على الهاتف',
                  [
                    {text: 'إلغاء', style: 'cancel'},
                    {
                      text: 'تنزيل الملف',
                      onPress: () => {
                        if (attachmentOwnerIsActive(operation)) {
                          void openCourseAttachment(current);
                        }
                      },
                    },
                  ],
                );
                return;
              }
              const url = current.sourceUrl || current.url;
              if (
                !isAllowedRemoteUrl(url) ||
                !(await openRemoteDownload(url, operation))
              ) {
                if (attachmentOwnerIsActive(operation)) {
                  Alert.alert(
                    'تعذّر فتح المصدر',
                    'تحقق من الاتصال ثم حاول مرة أخرى',
                  );
                }
              }
            } catch {
              if (attachmentOwnerIsActive(operation)) {
                Alert.alert(
                  'تعذّر فتح المصدر',
                  'تحقق من الاتصال ثم حاول مرة أخرى',
                );
              }
            }
          })();
        },
      },
    ],
  );
};

const openCourseAttachmentInternal = async (
  attachment: CourseAttachment,
  operation: AttachmentOperation,
  signedUrlRefreshAttempted = false,
) => {
  let currentAttachment: CourseAttachment;
  try {
    currentAttachment = await usableAttachment(
      attachment,
      operation,
      Boolean(attachment.downloadRefreshEndpoint) && !signedUrlRefreshAttempted,
    );
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
    const computerUrl =
      currentAttachment.external && currentAttachment.sourceUrl
        ? currentAttachment.sourceUrl
        : currentAttachment.url;
    if (!isAllowedRemoteUrl(computerUrl)) return emptyResult();
    try {
      Clipboard.setString(computerUrl);
    } catch {
      Alert.alert('تعذّر نسخ الرابط', 'حاول مرة أخرى');
      return emptyResult();
    }
    const temporaryLink =
      currentAttachment.temporary && computerUrl === currentAttachment.url;
    Alert.alert(
      'تم نسخ الرابط',
      temporaryLink
        ? 'افتحه على الكمبيوتر الآن\nوإذا انتهى الرابط انسخه من جديد'
        : 'افتح الرابط على الكمبيوتر لتنزيل الملفات',
    );
    return {copied: true, downloaded: false};
  }

  let transferAttachment = currentAttachment;
  if (currentAttachment.external) {
    try {
      if (!NativeModules.RoknDownloads?.inspectMetadata)
        throw new Error('METADATA_UNAVAILABLE');
      const metadata = await settleWithin<AttachmentMetadata | null>(
        NativeModules.RoknDownloads.inspectMetadata(currentAttachment.url),
        null,
        10_000,
      );
      assertAttachmentOwner(operation);
      if (!metadata) throw new Error('DOWNLOAD_METADATA_TIMEOUT');
      if (
        currentAttachment.temporary &&
        !signedUrlRefreshAttempted &&
        [401, 403, 404, 410].includes(metadata.statusCode)
      ) {
        const refreshed = await usableAttachment(
          currentAttachment,
          operation,
          true,
        );
        return openCourseAttachmentInternal(refreshed, operation, true);
      }
      if (
        metadata.statusCode < 200 ||
        metadata.statusCode >= 300 ||
        !isAllowedRemoteUrl(metadata.url) ||
        !metadata.contentType ||
        attachmentResponseIsHtml(metadata.contentType)
      ) {
        throw new Error('ATTACHMENT_HOST_PAGE');
      }
      transferAttachment = {
        ...currentAttachment,
        url: metadata.url,
        fileName:
          attachmentHeaderFilename(metadata.contentDisposition) ||
          currentAttachment.fileName,
        mimeType: metadata.contentType.split(';')[0].trim(),
        fileType:
          MIME_EXTENSIONS[metadata.contentType.split(';')[0].trim()] ||
          undefined,
        fileSizeBytes:
          metadata.contentLength && metadata.contentLength > 0
            ? metadata.contentLength
            : undefined,
      };
    } catch {
      showExternalDownloadFallback(currentAttachment, operation);
      return emptyResult();
    }
  }

  const fileName = safeFileName(transferAttachment);
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
          transferAttachment.url,
          currentAttachment.title,
          fileName,
          mimeTypeFor(transferAttachment, fileName),
          nativeStableKey(currentAttachment, operation.boundary.scope),
          transferAttachment.fileSizeBytes || 0,
        ],
        operation,
        Boolean(currentAttachment.external),
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
          void cancelNativeDownloadIfActive(downloadId);
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
      return emptyResult();
    }
    if (!attachmentOwnerIsActive(operation)) return emptyResult();
    Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    return emptyResult();
  }

  // iOS needs a local staging file before the system Save/Share sheet. Keep
  // that copy in cache and remove it after the handoff so every attachment
  // does not leave a hidden duplicate inside the app.
  const hasSpace = await hasLocalSpace(transferAttachment.fileSizeBytes);
  if (!attachmentOwnerIsActive(operation)) {
    return emptyResult();
  }
  if (!hasSpace) {
    Alert.alert('المساحة لا تكفي', 'وفّر مساحة على الهاتف ثم حاول مرة أخرى');
    return emptyResult();
  }
  const attachmentCacheFolder = `${
    RNFS.CachesDirectoryPath
  }/rokn-attachments/${nativeStableKey(
    currentAttachment,
    operation.boundary.scope,
  )
    .replace(/[^a-zA-Z0-9_-]/g, '_')
    .slice(0, 120)}`;
  let cacheFolder = `${attachmentCacheFolder}/attempt-${secureRandomUuid()}`;
  let cancelled = false;
  const saveCancellation = new AbortController();
  let downloadNotice:
    | ReturnType<typeof beginAttachmentDownloadNotice>
    | undefined;

  try {
    // A cancelled/failed attempt can leave a partial cache file with the same
    // name. A staging file matching the expected size can also result from an iOS
    // background transfer that finished after the process was evicted.
    await RNFS.mkdir(attachmentCacheFolder);
    const completedFolder = await completedPrivateDownloadFolder(
      attachmentCacheFolder,
      fileName,
      transferAttachment.fileSizeBytes,
    );
    if (completedFolder) cacheFolder = completedFolder;
    await RNFS.mkdir(cacheFolder);
    if (!attachmentOwnerIsActive(operation)) {
      await RNFS.unlink(cacheFolder).catch(() => undefined);
      return emptyResult();
    }
    const target = `${cacheFolder}/${fileName}`;
    const stagedSize = (await RNFS.exists(target))
      ? Number((await RNFS.stat(target)).size)
      : 0;
    assertAttachmentOwner(operation);
    const recoveredBackgroundDownload = Boolean(
      transferAttachment.fileSizeBytes &&
        stagedSize === transferAttachment.fileSizeBytes,
    );
    let result: {jobId: number; statusCode: number; bytesWritten: number};
    if (recoveredBackgroundDownload) {
      result = {jobId: -1, statusCode: 200, bytesWritten: stagedSize};
    } else {
      await RNFS.unlink(target).catch(() => undefined);
      assertAttachmentOwner(operation);
      const download = downloadPrivateFile(transferAttachment.url, target);
      downloadNotice = beginAttachmentDownloadNotice(
        currentAttachment.title,
        currentAttachment.fileSize,
        () => {
          cancelled = true;
          saveCancellation.abort();
          download.cancel();
        },
        {
          identity: operation.downloadIdentity,
          isCurrent: () => attachmentOwnerIsActive(operation),
        },
      );
      try {
        result = await download.promise;
      } finally {
        downloadNotice.transferFinished();
        // The native progress modal must actually dismiss before another
        // controller presents either Save to Files or an error notice.
        await downloadNotice.dismiss();
      }
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
      if (signedUrlRefreshAttempted)
        throw new Error('ATTACHMENT_DOWNLOAD_REJECTED');
      const refreshed = await usableAttachment(
        currentAttachment,
        operation,
        true,
      );
      return openCourseAttachmentInternal(refreshed, operation, true);
    }
    if (result.statusCode >= 200 && result.statusCode < 300) {
      const localSize = Number((await RNFS.stat(target)).size);
      assertAttachmentOwner(operation);
      if (!Number.isFinite(localSize) || localSize <= 0) {
        throw new Error('ATTACHMENT_EMPTY_DOWNLOAD');
      }
      if (
        transferAttachment.fileSizeBytes &&
        localSize !== transferAttachment.fileSizeBytes
      ) {
        if (currentAttachment.temporary && !signedUrlRefreshAttempted) {
          await RNFS.unlink(cacheFolder).catch(() => undefined);
          const refreshed = await usableAttachment(
            currentAttachment,
            operation,
            true,
          );
          return openCourseAttachmentInternal(refreshed, operation, true);
        }
        throw new Error('ATTACHMENT_TRUNCATED_DOWNLOAD');
      }
      if (currentAttachment.external) {
        const prefix = await RNFS.read(
          target,
          Math.min(512, localSize),
          0,
          'ascii',
        );
        assertAttachmentOwner(operation);
        if (attachmentPrefixIsHtml(prefix))
          throw new Error('ATTACHMENT_HOST_PAGE');
      }
      try {
        assertAttachmentOwner(operation);
        if (cancelled) return emptyResult();
        const handoff = await saveAttachmentToFiles(
          target,
          currentAttachment.title,
          () => attachmentOwnerIsActive(operation),
          saveCancellation.signal,
        );
        if (!handoff?.success || handoff.dismissedAction) {
          return emptyResult();
        }
        assertAttachmentOwner(operation);
      } catch (error) {
        // The iOS document picker rejects cancellation even with failOnCancel:false.
        if (asRecord(error).code === 'CANCELLED') return emptyResult();
        throw error;
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
    if (currentAttachment.external) {
      showExternalDownloadFallback(currentAttachment, operation);
      return emptyResult();
    }
    if (
      !currentAttachment.temporary &&
      (await openRemoteDownload(currentAttachment.url, operation))
    ) {
      return emptyResult();
    }
    if (attachmentOwnerIsActive(operation)) {
      Alert.alert('تعذّر تنزيل الملف', 'تحقق من الاتصال ثم حاول مرة أخرى');
    }
    return emptyResult();
  } finally {
    if (downloadNotice) {
      await downloadNotice.dismiss();
      downloadNotice.release();
    }
  }
};

export const openCourseAttachment = async (
  attachment: CourseAttachment,
): Promise<AttachmentResult> => {
  const generation = privateDownloadGeneration;
  let boundary: AccountSessionBoundary;
  try {
    boundary = await captureAccountSessionBoundary();
  } catch {
    return emptyResult();
  }
  const key = attachmentFlightKey(attachment, boundary.scope);
  const operation: AttachmentOperation = {
    boundary,
    generation,
    downloadIdentity: attachmentDownloadIdentity(attachment),
  };
  if (!attachmentOwnerIsActive(operation)) return emptyResult();
  const existing = downloadFlights.get(key);
  if (existing) return existing;
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
  cancelPendingAttachmentSaves();
  cancelAttachmentDownloadNotices();
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
