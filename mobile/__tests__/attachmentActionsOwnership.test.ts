jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/cache',
  downloadFile: jest.fn(),
  exists: jest.fn(async () => false),
  getFSInfo: jest.fn(async () => ({freeSpace: 1024 * 1024 * 1024})),
  mkdir: jest.fn(async () => undefined),
  read: jest.fn(async () => '%PDF-1.7'),
  readDir: jest.fn(async () => []),
  stat: jest.fn(async () => ({size: 10})),
  stopDownload: jest.fn(),
  cancelDownload: jest.fn(),
  unlink: jest.fn(async () => undefined),
}));

jest.mock('react-native-share', () => ({
  open: jest.fn(async () => ({success: true})),
}));

jest.mock('@react-native-clipboard/clipboard', () => ({
  setString: jest.fn(),
}));
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

let mockAttemptId = 0;
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockAttemptId).padStart(12, '0')}`,
}));

let mockBoundary = {epoch: 1, scope: 'user-a'};
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockBoundary.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
}));

import {Alert, AppState, Linking, NativeModules, Platform} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';
import Clipboard from '@react-native-clipboard/clipboard';
import {publicRequest} from '../src/constants/api';
import {captureAccountSessionBoundary} from '../src/constants/helpers';
import {loadCourseLearningData} from '../src/components/VideoPlayer/courseLearning/mapping';
import {
  openCourseAttachment,
  quiescePrivateAttachmentDownloads,
} from '../src/components/VideoPlayer/attachmentActions';
import type {CourseAttachment} from '../src/components/VideoPlayer/types';
import {beginAttachmentDownloadNotice} from '../src/components/VideoPlayer/attachmentDownloadNotice';

const loadCourse = loadCourseLearningData as jest.MockedFunction<
  typeof loadCourseLearningData
>;

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};

const settleMicrotasks = async (turns = 20) => {
  for (let index = 0; index < turns; index += 1) {
    await Promise.resolve();
  }
};

const attachment = (
  overrides: Partial<CourseAttachment> = {},
): CourseAttachment => ({
  id: 'attachment-1',
  title: 'ملف التطبيق',
  url: 'https://cdn.example/old.pdf',
  fileType: 'pdf',
  mimeType: 'application/pdf',
  downloadVersion: 'version-1',
  platform: 'mobile',
  courseId: '31',
  ...overrides,
});

describe('course attachment operation ownership', () => {
  const enqueue = jest.fn();
  const inspectMetadata = jest.fn();
  const cancelIfActive = jest.fn(async () => true);
  const cancelAllActive = jest.fn(async () => true);

  beforeAll(() => {
    Object.defineProperty(Platform, 'OS', {
      configurable: true,
      value: 'android',
    });
    Object.defineProperty(Platform, 'Version', {
      configurable: true,
      value: 34,
    });
  });

  beforeEach(async () => {
    jest.useRealTimers();
    AppState.currentState = 'active';
    mockBoundary = {epoch: 1, scope: 'user-a'};
    NativeModules.RoknDownloads = {
      enqueue,
      enqueueExternal: enqueue,
      inspectMetadata,
      cancelIfActive,
      cancelAllActive,
    };
    enqueue.mockReset();
    inspectMetadata.mockReset();
    cancelIfActive.mockClear();
    cancelAllActive.mockClear();
    loadCourse.mockReset();
    jest.mocked(RNFS.exists).mockResolvedValue(false);
    jest.mocked(RNFS.readDir).mockResolvedValue([]);
    jest.mocked(publicRequest.get).mockReset();
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await quiescePrivateAttachmentDownloads();
    jest.clearAllMocks();
  });

  afterEach(() => {
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it('invalidates a tap awaiting account capture without blocking a fresh tap after quiescence', async () => {
    const capture =
      deferred<Awaited<ReturnType<typeof captureAccountSessionBoundary>>>();
    jest
      .mocked(captureAccountSessionBoundary)
      .mockReturnValueOnce(capture.promise);
    const file = attachment({
      id: '22',
      downloadRefreshEndpoint: '/api/v1/courses/31/pdfs/22',
    });
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        data: {
          id: 22,
          title: file.title,
          download_only: true,
          download_url: 'https://api.example/current.pdf',
          download_refresh_endpoint: file.downloadRefreshEndpoint,
          download_version: 'version-2',
          source_type: 'upload',
          platform: 'mobile',
          file_name: 'current.pdf',
          file_type: 'pdf',
          mime_type: 'application/pdf',
        },
      },
    });
    enqueue.mockResolvedValue({id: 109, status: 'started'});

    const oldTap = openCourseAttachment(file);
    await quiescePrivateAttachmentDownloads();
    jest.clearAllMocks();
    // Quiescence precedes session deletion: the same account and epoch can
    // still be current when this older asynchronous capture finishes.
    capture.resolve({...mockBoundary});
    await expect(oldTap).resolves.toEqual({copied: false, downloaded: false});
    expect(publicRequest.get).not.toHaveBeenCalled();
    expect(loadCourse).not.toHaveBeenCalled();
    expect(enqueue).not.toHaveBeenCalled();
    expect(RNFS.downloadFile).not.toHaveBeenCalled();
    expect(Clipboard.setString).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();

    await expect(openCourseAttachment(file)).resolves.toMatchObject({
      downloaded: true,
    });
    expect(publicRequest.get).toHaveBeenCalledTimes(1);
    expect(enqueue).toHaveBeenCalledTimes(1);
    expect(enqueue.mock.calls[0][0]).toBe('https://api.example/current.pdf');
  });

  it('copies a computer attachment link without starting a download', async () => {
    const file = attachment({platform: 'computer'});
    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: true,
      downloaded: false,
    });
    expect(Clipboard.setString).toHaveBeenCalledWith(file.url);
    expect(enqueue).not.toHaveBeenCalled();
    expect(Alert.alert).toHaveBeenCalledWith(
      'تم نسخ الرابط',
      'افتح الرابط على الكمبيوتر لتنزيل الملفات',
    );
  });

  it('reports a clipboard failure as copying failure and permits another tap', async () => {
    jest.mocked(Clipboard.setString).mockImplementationOnce(() => {
      throw new Error('clipboard unavailable');
    });
    const file = attachment({platform: 'computer'});
    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: false,
    });
    expect(Alert.alert).toHaveBeenCalledTimes(1);
    expect(Alert.alert).toHaveBeenCalledWith(
      'تعذّر نسخ الرابط',
      'حاول مرة أخرى',
    );
    expect(enqueue).not.toHaveBeenCalled();
    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: true,
      downloaded: false,
    });
  });

  it('refreshes one expiring link and coalesces repeated taps across URL changes', async () => {
    const courseRequest =
      deferred<Awaited<ReturnType<typeof loadCourseLearningData>>>();
    loadCourse.mockReturnValue(courseRequest.promise);
    enqueue.mockResolvedValue({id: 17, status: 'started'});

    const first = openCourseAttachment(
      attachment({temporary: true, expiresAt: '2000-01-01T00:00:00Z'}),
    );
    const second = openCourseAttachment(
      attachment({
        temporary: true,
        expiresAt: '2000-01-01T00:00:00Z',
        url: 'https://cdn.example/another-expired-signature.pdf',
      }),
    );
    await settleMicrotasks();
    expect(loadCourse).toHaveBeenCalledTimes(1);

    courseRequest.resolve({
      course: {
        id: '31',
        title: 'الكورس',
        totalReels: 1,
        attachments: [
          attachment({
            temporary: true,
            url: 'https://cdn.example/fresh.pdf',
            expiresAt: '2099-01-01T00:00:00Z',
          }),
        ],
        modules: [
          {
            id: '72',
            title: 'الوحدة',
            order: 1,
            isLocked: false,
            reels: [],
            projects: [],
          },
        ],
      },
    });

    await expect(Promise.all([first, second])).resolves.toEqual([
      {copied: false, downloaded: true, downloadId: 17},
      {copied: false, downloaded: true, downloadId: 17},
    ]);
    expect(enqueue).toHaveBeenCalledTimes(1);
    expect(enqueue).toHaveBeenCalledWith(
      'https://cdn.example/fresh.pdf',
      'ملف التطبيق',
      expect.any(String),
      'application/pdf',
      expect.stringContaining('user-a:31:attachment-1:version-1'),
      0,
    );
  });

  it('downloads an external mobile file with the resolved filename and MIME instead of opening a browser', async () => {
    const source = attachment({
      url: 'https://api.example/attachments/1/download?signature=abc',
      external: true,
      fileType: undefined,
      mimeType: undefined,
    });
    inspectMetadata.mockResolvedValue({
      url: 'https://files.example/download?id=1',
      statusCode: 200,
      contentType: 'application/zip',
      contentDisposition: "attachment; filename*=UTF-8''lesson-files.zip",
      contentLength: 123,
    });
    enqueue.mockResolvedValue({id: 101, status: 'started'});
    const browser = jest.spyOn(Linking, 'openURL');
    await expect(openCourseAttachment(source)).resolves.toEqual({
      copied: false,
      downloaded: true,
      downloadId: 101,
    });
    expect(inspectMetadata).toHaveBeenCalledWith(source.url);
    expect(enqueue).toHaveBeenCalledWith(
      'https://files.example/download?id=1',
      source.title,
      'lesson-files.zip',
      'application/zip',
      expect.any(String),
      123,
    );
    expect(browser).not.toHaveBeenCalled();
  });

  it('preserves the uploaded original filename even when the signed URL has no extension', async () => {
    enqueue.mockResolvedValue({id: 104, status: 'started'});
    await openCourseAttachment(
      attachment({
        url: 'https://api.example/download?signature=abc',
        fileName: 'original-workbook.xlsx',
        fileType: undefined,
        mimeType: undefined,
      }),
    );
    expect(enqueue.mock.calls[0][2]).toBe('original-workbook.xlsx');
    expect(enqueue.mock.calls[0][3]).toBe(
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
  });

  it('copies the original public source for computer attachments without probing or downloading', async () => {
    const file = attachment({
      external: true,
      platform: 'computer',
      sourceUrl: 'https://drive.google.com/file/d/public/view',
    });
    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: true,
      downloaded: false,
    });
    expect(Clipboard.setString).toHaveBeenCalledWith(file.sourceUrl);
    expect(inspectMetadata).not.toHaveBeenCalled();
    expect(enqueue).not.toHaveBeenCalled();
  });

  it('renews an external signed route once when metadata lookup rejects the signature', async () => {
    const original = attachment({
      external: true,
      temporary: true,
      expiresAt: '2099-01-01T00:00:00Z',
    });
    const refreshed = {...original, url: 'https://api.example/fresh-signature'};
    loadCourse.mockResolvedValue({
      course: {attachments: [refreshed]},
    } as Awaited<ReturnType<typeof loadCourseLearningData>>);
    inspectMetadata
      .mockResolvedValueOnce({
        url: original.url,
        statusCode: 403,
        contentType: 'text/html',
      })
      .mockResolvedValueOnce({
        url: 'https://files.example/current.pdf',
        statusCode: 200,
        contentType: 'application/pdf',
      });
    enqueue.mockResolvedValue({id: 102, status: 'started'});
    await expect(openCourseAttachment(original)).resolves.toMatchObject({
      downloaded: true,
    });
    expect(inspectMetadata.mock.calls).toEqual([
      [original.url],
      [refreshed.url],
    ]);
    expect(loadCourse).toHaveBeenCalledTimes(1);
    expect(enqueue).toHaveBeenCalledTimes(1);
  });

  it('deduplicates external metadata and download work across repeated taps', async () => {
    const metadata = deferred<{
      url: string;
      statusCode: number;
      contentType: string;
    }>();
    inspectMetadata.mockReturnValue(metadata.promise);
    enqueue.mockResolvedValue({id: 103, status: 'started'});
    const file = attachment({external: true});
    const first = openCourseAttachment(file);
    const second = openCourseAttachment(file);
    await settleMicrotasks();
    expect(inspectMetadata).toHaveBeenCalledTimes(1);
    metadata.resolve({
      url: 'https://files.example/current.pdf',
      statusCode: 200,
      contentType: 'application/pdf',
    });
    await Promise.all([first, second]);
    expect(enqueue).toHaveBeenCalledTimes(1);
  });

  it('refreshes an old attachment ID through the published-revision alias endpoint', async () => {
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        data: {
          id: 22,
          download_only: true,
          download_url: 'https://api.example/current-download',
          download_refresh_endpoint: '/api/v1/courses/31/pdfs/22',
          download_url_is_temporary: true,
          download_url_expires_at: '2099-01-01T00:00:00Z',
          source_type: 'external',
          mime_type: null,
          file_type: null,
        },
      },
    });
    inspectMetadata.mockResolvedValue({
      url: 'https://files.example/current.pdf',
      statusCode: 200,
      contentType: 'application/pdf',
    });
    enqueue.mockResolvedValue({id: 105, status: 'started'});
    await expect(
      openCourseAttachment(
        attachment({
          id: '11',
          external: true,
          temporary: true,
          expiresAt: '2099-01-01T00:00:00Z',
          downloadRefreshEndpoint: '/api/v1/courses/31/pdfs/11',
        }),
      ),
    ).resolves.toMatchObject({downloaded: true});
    expect(publicRequest.get).toHaveBeenCalledWith(
      'courses/31/pdfs/11',
      expect.objectContaining({timeout: 10_000}),
    );
    expect(loadCourse).not.toHaveBeenCalled();
    expect(inspectMetadata).toHaveBeenCalledWith(
      'https://api.example/current-download',
    );
    expect(enqueue.mock.calls[0][4]).toContain('user-a:31:22:');
  });

  it('settles an HTML begin callback even if cancelling the native iOS task never resolves its promise', async () => {
    jest.replaceProperty(Platform, 'OS', 'ios');
    inspectMetadata.mockResolvedValue({
      url: 'https://files.example/file',
      statusCode: 200,
      contentType: 'application/pdf',
    });
    const transfer =
      deferred<Awaited<ReturnType<typeof RNFS.downloadFile>['promise']>>();
    let begin: Parameters<typeof RNFS.downloadFile>[0]['begin'];
    jest.mocked(RNFS.downloadFile).mockImplementationOnce(options => {
      begin = options.begin;
      return {jobId: 13, promise: transfer.promise};
    });
    const result = openCourseAttachment(attachment({external: true}));
    await settleMicrotasks(40);
    expect(begin).toBeDefined();
    begin?.({
      jobId: 13,
      statusCode: 200,
      contentLength: 10,
      headers: {'Content-Type': 'text/html'},
    });
    await expect(result).resolves.toEqual({copied: false, downloaded: false});
    expect(RNFS.cancelDownload).toHaveBeenCalledWith(13);
    expect(RNFS.stopDownload).not.toHaveBeenCalled();
    expect(Share.open).not.toHaveBeenCalled();
    expect(Alert.alert).toHaveBeenLastCalledWith(
      'تعذّر التنزيل المباشر',
      'افتح المصدر لإكمال التنزيل\nقد يتطلب تسجيل دخول أو تأكيد',
      expect.any(Array),
    );
  });

  it.each(['cancel', 'interruption', 'account change'] as const)(
    'settles iOS %s without a native result and isolates the next attempt from late callbacks',
    async reason => {
      jest.replaceProperty(Platform, 'OS', 'ios');
      const oldTransfer =
        deferred<Awaited<ReturnType<typeof RNFS.downloadFile>['promise']>>();
      const newTransfer =
        deferred<Awaited<ReturnType<typeof RNFS.downloadFile>['promise']>>();
      let oldOptions!: Parameters<typeof RNFS.downloadFile>[0];
      let newOptions!: Parameters<typeof RNFS.downloadFile>[0];
      jest
        .mocked(RNFS.downloadFile)
        .mockImplementationOnce(options => {
          oldOptions = options;
          return {jobId: 70, promise: oldTransfer.promise};
        })
        .mockImplementationOnce(options => {
          newOptions = options;
          return {jobId: 71, promise: newTransfer.promise};
        });
      const file = attachment({
        fileSizeBytes: 10,
        temporary: true,
        expiresAt: '2099-01-01T00:00:00Z',
      });
      const first = openCourseAttachment(file);
      await settleMicrotasks(50);
      const cancel = jest.mocked(beginAttachmentDownloadNotice).mock
        .calls[0]?.[2];
      expect(cancel).toBeDefined();
      expect(oldOptions.resumable).toEqual(expect.any(Function));
      jest.mocked(Alert.alert).mockClear();
      if (reason === 'cancel') cancel?.();
      else if (reason === 'interruption') oldOptions.resumable?.();
      else {
        mockBoundary = {epoch: 2, scope: 'user-b'};
        await quiescePrivateAttachmentDownloads();
      }
      await expect(first).resolves.toEqual({copied: false, downloaded: false});
      expect(RNFS.cancelDownload).toHaveBeenCalledWith(70);
      expect(RNFS.stopDownload).not.toHaveBeenCalled();
      expect(Share.open).not.toHaveBeenCalled();
      expect(Linking.openURL).not.toHaveBeenCalled();
      if (reason === 'interruption') {
        expect(Alert.alert).toHaveBeenLastCalledWith(
          'تعذّر تنزيل الملف',
          'تحقق من الاتصال ثم حاول مرة أخرى',
        );
      } else expect(Alert.alert).not.toHaveBeenCalled();

      // Even if a retired native task leaves a full-sized file before its
      // final callback, a new retry must not adopt that still-owned path.
      const oldFolderName = oldOptions.toFile.split('/').at(-2)!;
      jest.mocked(RNFS.readDir).mockResolvedValueOnce([
        {
          name: oldFolderName,
          isDirectory: () => true,
        } as Awaited<ReturnType<typeof RNFS.readDir>>[number],
      ]);
      jest
        .mocked(RNFS.exists)
        .mockImplementation(async target => target === oldOptions.toFile);
      const retry = openCourseAttachment(file);
      await settleMicrotasks(50);
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
      expect(newOptions.toFile).not.toBe(oldOptions.toFile);
      jest.mocked(RNFS.unlink).mockClear();
      const alertCount = jest.mocked(Alert.alert).mock.calls.length;
      oldOptions.resumable?.();
      oldOptions.begin?.({
        jobId: 70,
        statusCode: 200,
        contentLength: 10,
        headers: {'Content-Type': 'text/html'},
      });
      oldTransfer.resolve({jobId: 70, statusCode: 200, bytesWritten: 10});
      await settleMicrotasks(30);
      expect(RNFS.unlink).toHaveBeenCalledWith(oldOptions.toFile);
      expect(RNFS.unlink).not.toHaveBeenCalledWith(newOptions.toFile);
      expect(RNFS.unlink).not.toHaveBeenCalledWith(
        newOptions.toFile.slice(0, newOptions.toFile.lastIndexOf('/')),
      );
      expect(Share.open).not.toHaveBeenCalled();
      expect(Alert.alert).toHaveBeenCalledTimes(alertCount);

      newTransfer.resolve({jobId: 71, statusCode: 200, bytesWritten: 10});
      await expect(retry).resolves.toEqual({copied: false, downloaded: true});
      expect(Share.open).toHaveBeenCalledTimes(1);
      expect(Share.open).toHaveBeenCalledWith(
        expect.objectContaining({url: `file://${newOptions.toFile}`}),
      );
    },
  );

  it.each(['legacy', 'background attempt'] as const)(
    'reuses a complete %s file without downloading it again',
    async kind => {
      jest.replaceProperty(Platform, 'OS', 'ios');
      const attemptName = 'attempt-22222222-2222-4222-8222-222222222222';
      let expectedTarget = '';
      jest.mocked(RNFS.readDir).mockImplementationOnce(async root => {
        expectedTarget = `${root}/${
          kind === 'legacy' ? '' : `${attemptName}/`
        }saved.pdf`;
        return kind === 'legacy'
          ? []
          : [
              {
                name: attemptName,
                isDirectory: () => true,
              } as Awaited<ReturnType<typeof RNFS.readDir>>[number],
            ];
      });
      jest
        .mocked(RNFS.exists)
        .mockImplementation(async target => target === expectedTarget);
      await expect(
        openCourseAttachment(
          attachment({
            fileName: 'saved.pdf',
            fileSizeBytes: 10,
          }),
        ),
      ).resolves.toEqual({copied: false, downloaded: true});
      expect(RNFS.downloadFile).not.toHaveBeenCalled();
      expect(Share.open).toHaveBeenCalledWith(
        expect.objectContaining({url: `file://${expectedTarget}`}),
      );
    },
  );

  it('re-enters source and device routing when an expired iOS upload was replaced with a computer link', async () => {
    jest.replaceProperty(Platform, 'OS', 'ios');
    const original = attachment({
      temporary: true,
      expiresAt: '2099-01-01T00:00:00Z',
    });
    loadCourse.mockResolvedValue({
      course: {
        attachments: [
          {
            ...original,
            external: true,
            platform: 'computer',
            sourceUrl: 'https://drive.google.com/file/d/new/view',
          },
        ],
      },
    } as Awaited<ReturnType<typeof loadCourseLearningData>>);
    jest.mocked(RNFS.downloadFile).mockReturnValueOnce({
      jobId: 14,
      promise: Promise.resolve({jobId: 14, statusCode: 403, bytesWritten: 0}),
    });
    await expect(openCourseAttachment(original)).resolves.toEqual({
      copied: true,
      downloaded: false,
    });
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
    expect(Clipboard.setString).toHaveBeenCalledWith(
      'https://drive.google.com/file/d/new/view',
    );
    expect(Share.open).not.toHaveBeenCalled();
  });

  it.each([
    {statusCode: 200, contentType: 'text/html; charset=utf-8'},
    {statusCode: 200, contentType: 'application/xhtml+xml'},
    {statusCode: 200, contentType: ''},
    {statusCode: 405, contentType: 'text/plain'},
  ])(
    'never saves a host page or unsupported metadata response: %p',
    async metadata => {
      const source = attachment({
        external: true,
        sourceUrl: 'https://drive.google.com/file/d/public/view',
      });
      inspectMetadata.mockResolvedValue({url: source.url, ...metadata});
      const browser = jest
        .spyOn(Linking, 'openURL')
        .mockResolvedValue(undefined);
      jest.spyOn(Linking, 'canOpenURL').mockResolvedValue(true);
      await expect(openCourseAttachment(source)).resolves.toEqual({
        copied: false,
        downloaded: false,
      });
      expect(enqueue).not.toHaveBeenCalled();
      expect(browser).not.toHaveBeenCalled();
      const buttons = jest.mocked(Alert.alert).mock.calls.at(-1)?.[2];
      expect(buttons?.map(button => button.text)).toEqual([
        'إلغاء',
        'فتح المصدر',
      ]);
      buttons?.find(button => button.text === 'فتح المصدر')?.onPress?.();
      await settleMicrotasks();
      expect(browser).toHaveBeenCalledWith(source.sourceUrl);
    },
  );

  it.each([
    'external-mobile',
    'external-computer',
    'upload-mobile',
    'upload-hidden-before-confirmation',
    'upload-account-changed-before-confirmation',
    'hidden',
    'deleted',
    'revoked',
  ])(
    'renews the descriptor when an open-source prompt outlives an attachment change (%s)',
    async change => {
      const currentPayload = {
        id: 22,
        title: 'ملف التطبيق',
        download_only: true,
        download_url: 'https://api.example/download-before-edit',
        download_refresh_endpoint: '/api/v1/courses/31/pdfs/22',
        download_url_is_temporary: true,
        download_url_expires_at: '2099-01-01T00:00:00Z',
        download_version: 'version-2',
        source_type: 'external',
        platform: 'mobile',
        external_url: 'https://files.example/before-edit',
      };
      jest
        .mocked(publicRequest.get)
        .mockResolvedValueOnce({data: {data: currentPayload}});
      inspectMetadata.mockResolvedValue({
        url: currentPayload.external_url,
        statusCode: 200,
        contentType: 'text/html',
      });
      const browser = jest
        .spyOn(Linking, 'openURL')
        .mockResolvedValue(undefined);
      jest.spyOn(Linking, 'canOpenURL').mockResolvedValue(true);
      enqueue.mockResolvedValue({id: 108, status: 'started'});
      await openCourseAttachment(
        attachment({
          id: '11',
          external: true,
          temporary: true,
          expiresAt: '2099-01-01T00:00:00Z',
          downloadRefreshEndpoint: '/api/v1/courses/31/pdfs/11',
        }),
      );
      const openSource = jest
        .mocked(Alert.alert)
        .mock.calls.at(-1)?.[2]
        ?.find(button => button.text === 'فتح المصدر');
      expect(openSource).toBeDefined();
      const changedPayload = {
        ...currentPayload,
        id: 33,
        download_version: 'version-3',
        download_refresh_endpoint: '/api/v1/courses/31/pdfs/33',
        download_url: 'https://api.example/download-after-edit',
        external_url: 'https://files.example/after-edit',
        platform: change === 'external-computer' ? 'computer' : 'mobile',
        source_type: change.startsWith('upload-') ? 'upload' : 'external',
        file_name: 'current.pdf',
        file_type: 'pdf',
        mime_type: 'application/pdf',
      };
      if (['hidden', 'deleted', 'revoked'].includes(change)) {
        jest.mocked(publicRequest.get).mockRejectedValueOnce({
          response: {status: change === 'revoked' ? 403 : 404},
        });
      } else {
        jest
          .mocked(publicRequest.get)
          .mockResolvedValueOnce({data: {data: changedPayload}});
      }
      openSource?.onPress?.();
      await settleMicrotasks(60);
      if (change === 'external-mobile') {
        expect(browser).toHaveBeenCalledWith(changedPayload.external_url);
        expect(enqueue).not.toHaveBeenCalled();
      } else {
        expect(browser).not.toHaveBeenCalled();
        if (change === 'external-computer') {
          expect(Clipboard.setString).toHaveBeenCalledWith(
            changedPayload.external_url,
          );
          expect(enqueue).not.toHaveBeenCalled();
        } else if (change.startsWith('upload-')) {
          expect(enqueue).not.toHaveBeenCalled();
          const download = jest
            .mocked(Alert.alert)
            .mock.calls.at(-1)?.[2]
            ?.find(button => button.text === 'تنزيل الملف');
          expect(jest.mocked(Alert.alert).mock.calls.at(-1)?.[0]).toBe(
            'تم تحديث المرفق',
          );
          expect(download).toBeDefined();
          if (change === 'upload-hidden-before-confirmation') {
            jest
              .mocked(publicRequest.get)
              .mockRejectedValueOnce({response: {status: 404}});
          } else {
            jest
              .mocked(publicRequest.get)
              .mockResolvedValueOnce({data: {data: changedPayload}});
          }
          if (change === 'upload-account-changed-before-confirmation') {
            mockBoundary = {epoch: 2, scope: 'user-b'};
          }
          download?.onPress?.();
          await settleMicrotasks(60);
          if (change === 'upload-mobile') {
            expect(enqueue.mock.calls[0][0]).toBe(changedPayload.download_url);
            expect(enqueue.mock.calls[0][4]).toContain(
              'user-a:31:33:version-3',
            );
          }
        } else {
          expect(enqueue).not.toHaveBeenCalled();
          expect(Clipboard.setString).not.toHaveBeenCalled();
        }
      }
      expect(publicRequest.get).toHaveBeenNthCalledWith(
        2,
        'courses/31/pdfs/22',
        expect.anything(),
      );
      expect(inspectMetadata).toHaveBeenCalledTimes(1);
      expect(enqueue).toHaveBeenCalledTimes(change === 'upload-mobile' ? 1 : 0);
      expect(
        jest
          .mocked(Alert.alert)
          .mock.calls.filter(call => call[0] === 'تعذّر التنزيل المباشر'),
      ).toHaveLength(1);
    },
  );

  it('does not start an external transfer after the account changes during metadata lookup', async () => {
    const metadata = deferred<{
      url: string;
      statusCode: number;
      contentType: string;
    }>();
    inspectMetadata.mockReturnValue(metadata.promise);
    const result = openCourseAttachment(attachment({external: true}));
    await settleMicrotasks();
    mockBoundary = {epoch: 2, scope: 'user-b'};
    metadata.resolve({
      url: 'https://files.example/course.pdf',
      statusCode: 200,
      contentType: 'application/pdf',
    });
    await expect(result).resolves.toEqual({copied: false, downloaded: false});
    expect(enqueue).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('settles a stalled metadata lookup and ignores its late result', async () => {
    jest.useFakeTimers();
    const metadata = deferred<{
      url: string;
      statusCode: number;
      contentType: string;
    }>();
    inspectMetadata.mockReturnValue(metadata.promise);
    const result = openCourseAttachment(attachment({external: true}));
    await settleMicrotasks();
    await jest.advanceTimersByTimeAsync(10_000);
    await expect(result).resolves.toEqual({copied: false, downloaded: false});
    metadata.resolve({
      url: 'https://files.example/course.pdf',
      statusCode: 200,
      contentType: 'application/pdf',
    });
    await settleMicrotasks();
    expect(enqueue).not.toHaveBeenCalled();
  });

  it.each([false, true])(
    'validates external iOS staging before Save to Files (HTML=%s)',
    async html => {
      jest.replaceProperty(Platform, 'OS', 'ios');
      inspectMetadata.mockResolvedValue({
        url: 'https://files.example/download',
        statusCode: 200,
        contentType: 'application/pdf',
        contentDisposition: 'attachment; filename="workbook.pdf"',
      });
      jest
        .mocked(RNFS.read)
        .mockResolvedValueOnce(
          html ? '<!DOCTYPE html><html>Sign in' : '%PDF-1.7',
        );
      jest.mocked(RNFS.downloadFile).mockReturnValueOnce({
        jobId: 12,
        promise: Promise.resolve({
          jobId: 12,
          statusCode: 200,
          bytesWritten: 10,
        }),
      });
      await expect(
        openCourseAttachment(attachment({external: true})),
      ).resolves.toEqual({
        copied: false,
        downloaded: !html,
      });
      expect(RNFS.downloadFile).toHaveBeenCalledWith(
        expect.objectContaining({
          fromUrl: 'https://files.example/download',
          toFile: expect.stringMatching(/\/workbook\.pdf$/),
        }),
      );
      if (html) expect(Share.open).not.toHaveBeenCalled();
      else
        expect(Share.open).toHaveBeenCalledWith(
          expect.objectContaining({saveToFiles: true}),
        );
      expect(RNFS.unlink).toHaveBeenCalled();
    },
  );

  it('cancels a native result that settles after its account changed', async () => {
    const nativeRequest = deferred<{id: number; status: string}>();
    enqueue.mockReturnValue(nativeRequest.promise);

    const result = openCourseAttachment(
      attachment({
        temporary: true,
        expiresAt: '2099-01-01T00:00:00Z',
      }),
    );
    await settleMicrotasks();
    expect(enqueue).toHaveBeenCalledTimes(1);

    mockBoundary = {epoch: 2, scope: 'user-b'};
    nativeRequest.resolve({id: 91, status: 'started'});

    await expect(result).resolves.toEqual({copied: false, downloaded: false});
    expect(cancelIfActive).toHaveBeenCalledWith(91);
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('settles a stuck native bridge and cancels a late download', async () => {
    jest.useFakeTimers();
    const nativeRequest = deferred<{id: number; status: string}>();
    enqueue.mockReturnValue(nativeRequest.promise);

    const result = openCourseAttachment(
      attachment({
        temporary: true,
        expiresAt: '2099-01-01T00:00:00Z',
      }),
    );
    await settleMicrotasks();
    await jest.advanceTimersByTimeAsync(12_000);

    await expect(result).resolves.toEqual({copied: false, downloaded: false});
    nativeRequest.resolve({id: 92, status: 'started'});
    await settleMicrotasks();
    expect(cancelIfActive).toHaveBeenCalledWith(92);
  });

  it('contains a native cancellation rejection after account retirement and permits a fresh action', async () => {
    const nativeRequest = deferred<{id: number; status: string}>();
    enqueue.mockReturnValueOnce(nativeRequest.promise);
    const result = openCourseAttachment(attachment());
    await settleMicrotasks();

    mockBoundary = {epoch: 2, scope: 'user-b'};
    cancelIfActive.mockRejectedValueOnce(
      Object.assign(new Error('The download could not be cancelled'), {
        code: 'DOWNLOAD_CANCEL_FAILED',
      }),
    );
    nativeRequest.resolve({id: 93, status: 'started'});
    await expect(result).resolves.toEqual({copied: false, downloaded: false});
    // Let the native rejection reach the event loop: successful cancellation
    // alone does not prove that the retired operation handles this failure.
    await new Promise<void>(resolve => setImmediate(resolve));
    expect(cancelIfActive).toHaveBeenCalledWith(93);
    expect(Alert.alert).not.toHaveBeenCalled();

    enqueue.mockResolvedValueOnce({id: 94, status: 'started'});
    await expect(openCourseAttachment(attachment())).resolves.toMatchObject({
      downloaded: true,
      downloadId: 94,
    });
    expect(enqueue).toHaveBeenCalledTimes(2);
  });

  it('contains rejected cancellation of a late timed-out bridge receipt', async () => {
    jest.useFakeTimers();
    const nativeRequest = deferred<{id: number; status: string}>();
    enqueue.mockReturnValueOnce(nativeRequest.promise);
    const result = openCourseAttachment(
      attachment({temporary: true, expiresAt: '2099-01-01T00:00:00Z'}),
    );
    await settleMicrotasks();
    await jest.advanceTimersByTimeAsync(12_000);
    await expect(result).resolves.toEqual({copied: false, downloaded: false});
    const alertsBeforeLateReceipt = jest.mocked(Alert.alert).mock.calls.length;

    cancelIfActive.mockRejectedValueOnce(
      Object.assign(new Error('The download could not be cancelled'), {
        code: 'DOWNLOAD_CANCEL_FAILED',
      }),
    );
    nativeRequest.resolve({id: 95, status: 'started'});
    await settleMicrotasks();
    jest.useRealTimers();
    await new Promise<void>(resolve => setImmediate(resolve));
    expect(cancelIfActive).toHaveBeenCalledWith(95);
    expect(Alert.alert).toHaveBeenCalledTimes(alertsBeforeLateReceipt);
  });
});
