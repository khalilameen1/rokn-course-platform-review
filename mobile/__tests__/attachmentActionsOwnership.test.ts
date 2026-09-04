jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/cache',
  downloadFile: jest.fn(),
  exists: jest.fn(async () => false),
  getFSInfo: jest.fn(async () => ({freeSpace: 1024 * 1024 * 1024})),
  mkdir: jest.fn(async () => undefined),
  stat: jest.fn(async () => ({size: 10})),
  stopDownload: jest.fn(),
  unlink: jest.fn(async () => undefined),
}));

jest.mock('react-native-share', () => ({
  open: jest.fn(async () => ({success: true})),
}));

jest.mock('@react-native-clipboard/clipboard', () => ({
  setString: jest.fn(),
}));

jest.mock('../src/components/VideoPlayer/courseLearning/mapping', () => ({
  loadCourseLearningData: jest.fn(),
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

import {Alert, NativeModules, Platform} from 'react-native';
import {loadCourseLearningData} from '../src/components/VideoPlayer/courseLearning/mapping';
import {
  openCourseAttachment,
  quiescePrivateAttachmentDownloads,
} from '../src/components/VideoPlayer/attachmentActions';
import type {CourseAttachment} from '../src/components/VideoPlayer/types';

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
    mockBoundary = {epoch: 1, scope: 'user-a'};
    NativeModules.RoknDownloads = {enqueue, cancelIfActive, cancelAllActive};
    enqueue.mockReset();
    cancelIfActive.mockClear();
    cancelAllActive.mockClear();
    loadCourse.mockReset();
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await quiescePrivateAttachmentDownloads();
    jest.clearAllMocks();
  });

  afterEach(() => {
    jest.restoreAllMocks();
    jest.useRealTimers();
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
});
