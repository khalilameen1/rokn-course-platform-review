import {
  Alert,
  Linking,
  NativeModules,
  PermissionsAndroid,
  Platform,
} from 'react-native';
import RNFS from 'react-native-fs';
import Clipboard from '@react-native-clipboard/clipboard';

let mockBoundary = {scope: 'account-a', epoch: 1};
jest.mock('@react-native-clipboard/clipboard', () => ({setString: jest.fn()}));
jest.mock('react-native-share', () => ({open: jest.fn()}));
jest.mock('../src/components/VideoPlayer/courseLearning/mapping', () => ({
  loadCourseLearningData: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({publicRequest: {get: jest.fn()}}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));

import {
  openCourseAttachment,
  quiescePrivateAttachmentDownloads,
} from '../src/components/VideoPlayer/attachmentActions';
import type {CourseAttachment} from '../src/components/VideoPlayer/types';

const file: CourseAttachment = {
  id: '22',
  courseId: '31',
  title: 'ملف التطبيق',
  url: 'https://api.example/courses/31/pdfs/22/download?signature=signed',
  platform: 'mobile',
  temporary: true,
  expiresAt: '2099-01-01T00:00:00Z',
  downloadVersion: 'version-1',
  fileName: 'workbook.pdf',
  mimeType: 'application/pdf',
  fileSizeBytes: 1024,
};
const legacyPermission = PermissionsAndroid.PERMISSIONS.WRITE_EXTERNAL_STORAGE;

describe('Android attachment permission and user handoff', () => {
  const enqueue = jest.fn();
  const enqueueExternal = jest.fn();
  const inspectMetadata = jest.fn();
  const originalVersion = Object.getOwnPropertyDescriptor(Platform, 'Version');

  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'account-a', epoch: 1};
    jest.replaceProperty(Platform, 'OS', 'android');
    Object.defineProperty(Platform, 'Version', {configurable: true, value: 28});
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    jest.spyOn(Linking, 'openURL').mockResolvedValue(undefined);
    jest.spyOn(PermissionsAndroid, 'check').mockResolvedValue(false);
    jest
      .spyOn(PermissionsAndroid, 'request')
      .mockResolvedValue(PermissionsAndroid.RESULTS.GRANTED);
    enqueue.mockResolvedValue({id: 101, status: 'started'});
    enqueueExternal.mockResolvedValue({id: 102, status: 'started'});
    inspectMetadata.mockResolvedValue({
      url: 'https://files.example/final.pdf',
      statusCode: 200,
      contentType: 'application/pdf',
      contentLength: 1024,
      contentDisposition: 'attachment; filename="public.pdf"',
    });
    NativeModules.RoknDownloads = {
      enqueue,
      enqueueExternal,
      inspectMetadata,
      cancelAllActive: jest.fn(async () => true),
    };
    await quiescePrivateAttachmentDownloads();
    jest.clearAllMocks();
  });

  afterEach(() => {
    jest.restoreAllMocks();
    if (originalVersion)
      Object.defineProperty(Platform, 'Version', originalVersion);
  });

  it.each([
    PermissionsAndroid.RESULTS.DENIED,
    PermissionsAndroid.RESULTS.NEVER_ASK_AGAIN,
  ])(
    'does not claim a download or open a browser after the legacy permission result %s',
    async result => {
      jest.mocked(PermissionsAndroid.request).mockResolvedValueOnce(result);

      await expect(openCourseAttachment(file)).resolves.toEqual({
        copied: false,
        downloaded: false,
      });
      expect(PermissionsAndroid.request).toHaveBeenCalledWith(legacyPermission);
      expect(enqueue).not.toHaveBeenCalled();
      expect(Linking.openURL).not.toHaveBeenCalled();
      expect(RNFS.downloadFile).not.toHaveBeenCalled();
      expect(Alert.alert).toHaveBeenCalledWith(
        'تعذّر حفظ الملف',
        expect.any(String),
      );
      expect(Alert.alert).not.toHaveBeenCalledWith(
        'بدأ التنزيل',
        expect.anything(),
      );
    },
  );

  it('starts exactly one native download when a later explicit retry grants permission', async () => {
    jest
      .mocked(PermissionsAndroid.request)
      .mockResolvedValueOnce(PermissionsAndroid.RESULTS.DENIED)
      .mockResolvedValueOnce(PermissionsAndroid.RESULTS.GRANTED);

    await expect(openCourseAttachment(file)).resolves.toMatchObject({
      downloaded: false,
    });
    await expect(openCourseAttachment(file)).resolves.toMatchObject({
      downloaded: true,
      downloadId: 101,
    });
    expect(enqueue).toHaveBeenCalledTimes(1);
    expect(enqueue).toHaveBeenCalledWith(
      file.url,
      file.title,
      'workbook.pdf',
      'application/pdf',
      expect.any(String),
      1024,
    );
    expect(Alert.alert).toHaveBeenCalledWith(
      'بدأ التنزيل',
      'تابع التقدم من إشعار التنزيل',
    );
    expect(Alert.alert).not.toHaveBeenCalledWith(
      'الملف في التنزيلات',
      expect.anything(),
    );
  });

  it.each([24, 28])(
    'does not ask again when storage permission is already granted on Android API %s',
    async version => {
      Object.defineProperty(Platform, 'Version', {
        configurable: true,
        value: version,
      });
      jest.mocked(PermissionsAndroid.check).mockResolvedValueOnce(true);

      await expect(openCourseAttachment(file)).resolves.toMatchObject({
        downloaded: true,
      });
      expect(PermissionsAndroid.check).toHaveBeenCalledWith(legacyPermission);
      expect(PermissionsAndroid.request).not.toHaveBeenCalled();
      expect(enqueue).toHaveBeenCalledTimes(1);
    },
  );

  it.each([29, 36])(
    'uses system downloads without a legacy permission prompt on Android API %s',
    async version => {
      Object.defineProperty(Platform, 'Version', {
        configurable: true,
        value: version,
      });

      await expect(openCourseAttachment(file)).resolves.toMatchObject({
        downloaded: true,
      });
      expect(PermissionsAndroid.check).not.toHaveBeenCalled();
      expect(PermissionsAndroid.request).not.toHaveBeenCalled();
      expect(enqueue).toHaveBeenCalledTimes(1);
    },
  );

  it('downloads an external mobile file through the same granted permission path', async () => {
    await expect(
      openCourseAttachment({...file, external: true}),
    ).resolves.toMatchObject({downloaded: true, downloadId: 102});

    expect(inspectMetadata).toHaveBeenCalledWith(file.url);
    expect(enqueueExternal).toHaveBeenCalledWith(
      'https://files.example/final.pdf',
      file.title,
      'public.pdf',
      'application/pdf',
      expect.any(String),
      1024,
    );
    expect(enqueue).not.toHaveBeenCalled();
    expect(Linking.openURL).not.toHaveBeenCalled();
    expect(RNFS.downloadFile).not.toHaveBeenCalled();
  });

  it('copies a computer link without requesting file permission or starting a transfer', async () => {
    const computer = {
      ...file,
      external: true,
      sourceUrl: 'https://files.example/large.zip',
      platform: 'computer' as const,
    };

    await expect(openCourseAttachment(computer)).resolves.toEqual({
      copied: true,
      downloaded: false,
    });
    expect(Clipboard.setString).toHaveBeenCalledWith(computer.sourceUrl);
    expect(PermissionsAndroid.check).not.toHaveBeenCalled();
    expect(PermissionsAndroid.request).not.toHaveBeenCalled();
    expect(inspectMetadata).not.toHaveBeenCalled();
    expect(enqueue).not.toHaveBeenCalled();
    expect(enqueueExternal).not.toHaveBeenCalled();
  });

  it('discards a permission acknowledgement after its account has changed', async () => {
    let grant!: (value: typeof PermissionsAndroid.RESULTS.GRANTED) => void;
    const prompt = new Promise<typeof PermissionsAndroid.RESULTS.GRANTED>(
      resolve => {
        grant = resolve;
      },
    );
    jest.mocked(PermissionsAndroid.request).mockReturnValueOnce(prompt);
    const action = openCourseAttachment(file);
    for (let turn = 0; turn < 20; turn += 1) await Promise.resolve();
    expect(PermissionsAndroid.request).toHaveBeenCalledTimes(1);
    mockBoundary = {scope: 'account-b', epoch: 2};
    grant(PermissionsAndroid.RESULTS.GRANTED);

    await expect(action).resolves.toEqual({copied: false, downloaded: false});
    expect(enqueue).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(Linking.openURL).not.toHaveBeenCalled();
  });
});
