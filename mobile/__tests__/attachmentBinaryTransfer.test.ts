import {Alert, AppState, NativeModules, Platform} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';
import Clipboard from '@react-native-clipboard/clipboard';

jest.mock('react-native-share', () => ({
  open: jest.fn(async () => ({success: true})),
}));
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
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'user-a', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => '11111111-1111-4111-8111-111111111111',
}));

import {
  openCourseAttachment,
  quiescePrivateAttachmentDownloads,
} from '../src/components/VideoPlayer/attachmentActions';
import type {CourseAttachment} from '../src/components/VideoPlayer/types';

// Use the installed RNFS decoder dependencies, not a mock that returns an
// already-decoded PDF header. FS.common.js read() receives native base64 bytes
// and applies exactly these encoding branches before resolving its caller.
const base64 = require(require.resolve('base-64', {
  paths: [require.resolve('react-native-fs')],
})) as {decode: (value: string) => string};
const utf8 = require('utf8') as {decode: (value: string) => string};
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==',
  'base64',
);
const pdf = Buffer.from(
  '%PDF-1.7\n%\xe2\xe3\xcf\xd3\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF',
  'latin1',
);
// Empty ZIP with a two-byte binary archive comment.
const zip = Buffer.from(
  '504b0506000000000000000000000000000000000200fffe',
  'hex',
);
const hostPage =
  '  <!DOCTYPE html><html><body>Sign in to download</body></html>';
const file: CourseAttachment = {
  id: '22',
  courseId: '31',
  title: 'ملفات الدرس',
  url: 'https://api.example/courses/31/pdfs/22/download?signature=signed',
  sourceUrl: 'https://files.example/course-asset',
  external: true,
  platform: 'mobile',
  temporary: true,
  expiresAt: '2099-01-01T00:00:00Z',
  downloadVersion: 'version-1',
};

describe('binary-safe iOS external attachment save', () => {
  let nativeBytes = pdf;
  let fileSize = pdf.length;
  let inspect: jest.Mock;

  beforeEach(async () => {
    jest.clearAllMocks();
    AppState.currentState = 'active';
    nativeBytes = pdf;
    fileSize = pdf.length;
    jest.replaceProperty(Platform, 'OS', 'ios');
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    inspect = jest.fn(async () => ({
      url: 'https://files.example/course-asset',
      statusCode: 200,
      contentType: 'application/octet-stream',
      contentDisposition: 'attachment; filename="course.zip"',
      contentLength: fileSize,
    }));
    NativeModules.RoknDownloads = {inspectMetadata: inspect};
    jest.mocked(RNFS.exists).mockResolvedValue(false);
    jest.mocked(RNFS.readDir).mockResolvedValue([]);
    jest.mocked(RNFS.getFSInfo).mockResolvedValue({
      freeSpace: 20 * 1024 ** 3,
      totalSpace: 30 * 1024 ** 3,
    });
    jest
      .mocked(RNFS.stat)
      .mockImplementation(
        async () => ({size: fileSize} as Awaited<ReturnType<typeof RNFS.stat>>),
      );
    jest.mocked(RNFS.downloadFile).mockImplementation(() => ({
      jobId: 12,
      promise: Promise.resolve({
        jobId: 12,
        statusCode: 200,
        bytesWritten: fileSize,
      }),
    }));
    jest
      .mocked(RNFS.read)
      .mockImplementation(async (_path, length = 0, position = 0, encoding) => {
        const encoded = nativeBytes
          .subarray(position, position + length)
          .toString('base64');
        if (encoding === 'base64') return encoded;
        const bytes = base64.decode(encoded);
        return encoding === 'ascii' ? bytes : utf8.decode(bytes);
      });
    jest
      .mocked(Share.open)
      .mockResolvedValue({success: true, message: 'saved'});
    await quiescePrivateAttachmentDownloads();
    jest.clearAllMocks();
  });

  afterEach(() => jest.restoreAllMocks());

  it.each([
    {
      name: 'binary PDF',
      bytes: pdf,
      mime: 'application/pdf',
      filename: 'workbook.pdf',
    },
    {name: 'PNG', bytes: png, mime: 'image/png', filename: 'example.png'},
    {name: 'ZIP', bytes: zip, mime: 'application/zip', filename: 'course.zip'},
  ])(
    'opens Save to Files for a complete $name without strict UTF-8 decoding',
    async fixture => {
      nativeBytes = fixture.bytes;
      fileSize = nativeBytes.length;
      inspect.mockResolvedValue({
        url: 'https://files.example/final-download',
        statusCode: 200,
        contentType: fixture.mime,
        contentLength: fileSize,
        contentDisposition: `attachment; filename="${fixture.filename}"`,
      });
      await expect(openCourseAttachment(file)).resolves.toEqual({
        copied: false,
        downloaded: true,
      });
      expect(Share.open).toHaveBeenCalledWith(
        expect.objectContaining({
          saveToFiles: true,
          url: expect.stringContaining(`/${fixture.filename}`),
        }),
      );
      expect(RNFS.read).toHaveBeenCalledWith(
        expect.any(String),
        Math.min(512, fileSize),
        0,
        expect.any(String),
      );
      expect(RNFS.readFile).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalledWith(
        'تعذّر التنزيل المباشر',
        expect.anything(),
        expect.anything(),
      );
    },
  );

  it.each([
    {name: 'ASCII', bytes: Buffer.from(hostPage)},
    {name: 'UTF-8 BOM', bytes: Buffer.from(`\uFEFF${hostPage}`)},
    {
      name: 'UTF-16 little-endian BOM',
      bytes: Buffer.from(`\uFEFF${hostPage}`, 'utf16le'),
    },
    {
      name: 'UTF-16 big-endian BOM',
      bytes: Buffer.from(`\uFEFF${hostPage}`, 'utf16le').swap16(),
    },
  ])(
    'does not offer a mislabeled $name host page as a downloaded file',
    async fixture => {
      nativeBytes = fixture.bytes;
      fileSize = nativeBytes.length;

      await expect(openCourseAttachment(file)).resolves.toEqual({
        copied: false,
        downloaded: false,
      });
      expect(Share.open).not.toHaveBeenCalled();
      expect(RNFS.unlink).toHaveBeenCalled();
      expect(Alert.alert).toHaveBeenCalledWith(
        'تعذّر التنزيل المباشر',
        expect.any(String),
        expect.any(Array),
      );
      expect(RNFS.readFile).not.toHaveBeenCalled();
    },
  );

  it('keeps a multi-gigabyte mobile transfer native and reads only its 512-byte prefix', async () => {
    nativeBytes = Buffer.concat([zip, Buffer.alloc(512 - zip.length, 0xff)]);
    fileSize = 3 * 1024 ** 3;

    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: true,
    });
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
    expect(RNFS.read).toHaveBeenCalledWith(expect.any(String), 512, 0, 'ascii');
    expect(RNFS.readFile).not.toHaveBeenCalled();
    expect(Share.open).toHaveBeenCalledWith(
      expect.objectContaining({saveToFiles: true}),
    );
  });

  it('keeps a cancelled save truthful and lets the student retry the binary file', async () => {
    jest
      .mocked(Share.open)
      .mockResolvedValueOnce({success: false, message: 'User did not share'});

    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: false,
    });
    const firstTarget = jest.mocked(RNFS.downloadFile).mock.calls[0][0].toFile;
    expect(RNFS.unlink).toHaveBeenCalledWith(
      firstTarget.slice(0, firstTarget.lastIndexOf('/')),
    );
    expect(Alert.alert).not.toHaveBeenCalledWith(
      'تعذّر التنزيل المباشر',
      expect.anything(),
      expect.anything(),
    );

    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: true,
    });
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
    expect(Share.open).toHaveBeenCalledTimes(2);
  });

  it('treats the native iOS document picker CANCELLED rejection as cancellation and allows retry', async () => {
    // RNShare.mm documentPickerWasCancelled rejects this bridge error rather
    // than resolving success:false, even with failOnCancel:false.
    jest.mocked(Share.open).mockRejectedValueOnce(
      Object.assign(new Error('CANCELLED'), {
        code: 'CANCELLED',
        userInfo: {NSLocalizedDescription: 'PICKER_WAS_CANCELLED'},
      }),
    );

    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: false,
    });
    const target = jest.mocked(RNFS.downloadFile).mock.calls[0][0].toFile;
    expect(RNFS.unlink).toHaveBeenCalledWith(
      target.slice(0, target.lastIndexOf('/')),
    );
    expect(Alert.alert).not.toHaveBeenCalledWith(
      'تعذّر التنزيل المباشر',
      expect.anything(),
      expect.anything(),
    );

    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: true,
    });
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
    expect(Share.open).toHaveBeenCalledTimes(2);
  });

  it('does not silently treat a genuine native save error as picker cancellation', async () => {
    jest
      .mocked(Share.open)
      .mockRejectedValueOnce(
        Object.assign(new Error('Unable to export document'), {
          code: 'E_SAVE_FILE',
        }),
      );

    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: false,
    });
    const target = jest.mocked(RNFS.downloadFile).mock.calls[0][0].toFile;
    expect(RNFS.unlink).toHaveBeenCalledWith(
      target.slice(0, target.lastIndexOf('/')),
    );
    expect(Alert.alert).toHaveBeenCalledWith(
      'تعذّر التنزيل المباشر',
      expect.any(String),
      expect.any(Array),
    );
  });

  it('does not report success or share when the native prefix read actually fails', async () => {
    jest.mocked(RNFS.read).mockRejectedValueOnce(new Error('EIO'));

    await expect(openCourseAttachment(file)).resolves.toEqual({
      copied: false,
      downloaded: false,
    });
    expect(Share.open).not.toHaveBeenCalled();
    expect(Alert.alert).toHaveBeenCalledWith(
      'تعذّر التنزيل المباشر',
      expect.any(String),
      expect.any(Array),
    );
  });

  it('copies the original computer link without probing or downloading the large file', async () => {
    fileSize = 3 * 1024 ** 3;

    await expect(
      openCourseAttachment({...file, platform: 'computer'}),
    ).resolves.toEqual({copied: true, downloaded: false});
    expect(Clipboard.setString).toHaveBeenCalledWith(file.sourceUrl);
    expect(inspect).not.toHaveBeenCalled();
    expect(RNFS.downloadFile).not.toHaveBeenCalled();
    expect(RNFS.read).not.toHaveBeenCalled();
    expect(Share.open).not.toHaveBeenCalled();
  });
});
