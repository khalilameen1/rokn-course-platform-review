import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {AccountSessionBoundary} from '../src/constants/helpers';

let mockIdentity = 'account-a';
let mockFocused = true;
let mockForeground = true;
let mockBoundary: AccountSessionBoundary = {epoch: 1, scope: 'account-a'};
const mockCapture = jest.fn();
const mockStorageKey = jest.fn();
const mockGetItem = jest.fn();
const mockSaveItem = jest.fn();
const mockGet = jest.fn();
const mockPost = jest.fn();
const mockLearning = jest.fn();
const mockRemote = new Map<string, unknown[]>();

jest.mock('@react-navigation/native', () => ({
  useIsFocused: () => mockFocused,
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(
      () => (mockFocused ? effect() : undefined),
      [effect, mockFocused],
    );
  },
}));
jest.mock('react-redux', () => ({
  useSelector: () => ({user: {id: mockIdentity, name: 'طالب ركن'}}),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: () => mockCapture(),
  assertAccountSessionBoundary: (boundary: AccountSessionBoundary) => {
    if (
      boundary.epoch !== mockBoundary.epoch ||
      boundary.scope !== mockBoundary.scope
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: (...args: unknown[]) => mockStorageKey(...args),
  getItem: (...args: unknown[]) => mockGetItem(...args),
  saveItem: (...args: unknown[]) => mockSaveItem(...args),
  extractUserProfile: (session: {user: unknown}) => session.user,
  sessionIdentityKey: () => mockIdentity,
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
// Keep the real certificate API, DTO validation and ownership assertions.
// Only unrelated learning/session discovery and native/network I/O are fakes.
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/certificates'),
  getLearningCourses: () => mockLearning(),
  hasSession: async () => true,
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
  shareOnce: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({
  openCourseAttachment: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/attachmentDownloadNotice', () => ({
  useAttachmentDownloadCancellation: () => () => undefined,
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));

import {
  getCachedCertificates,
  getCertificates,
  issueCertificate,
  recoverCertificate,
} from '../src/services/api/certificates';
import {useCertificatesController} from '../src/screens/Profile/certificates/useCertificatesController';

const readyCourse = {
  id: '52',
  title: 'كورس تجريبي',
  progress: 100,
  totalSections: 3,
  completedSections: 3,
  accessType: 'paid',
  certificateAvailable: true,
};
const credential = '11111111-1111-4111-8111-111111111111';
const verificationUrl = `https://rokn.app/c/${credential}`;
const pendingDto = {
  public_id: credential,
  course_id: 52,
  holder_name: 'طالب ركن',
  course_name: readyCourse.title,
  verification_url: verificationUrl,
  status: 'pending',
  verification_level: 'completion',
  verification_label: 'اجتياز الكورس',
  certificate_text_template_key: 'completion',
  certificate_text: 'اجتاز الكورس',
  qr_destination: {
    type: 'certificate',
    url: verificationUrl,
    title: 'التحقق من الشهادة',
    hint: 'امسح الرمز',
  },
};
const acceptedResponse = {
  status: 202,
  data: {success: true, code: 'certificate_generating', data: null},
};

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return {promise, resolve, reject};
}

let controller!: ReturnType<typeof useCertificatesController>;
let renderer: TestRenderer.ReactTestRenderer | undefined;
const Harness = () => {
  controller = useCertificatesController('طالب ركن');
  return null;
};
async function mount(pending = false) {
  if (pending) mockRemote.set(mockIdentity, [pendingDto]);
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
  });
  expect(controller.loading).toBe(false);
}
async function openIssue() {
  await act(async () => {
    controller.openIssueCertificate(readyCourse as never);
  });
  expect(controller.issueCourse?.id).toBe('52');
}
async function changeAccount() {
  await act(async () => {
    mockIdentity = 'account-b';
    mockBoundary = {epoch: 2, scope: mockIdentity};
    renderer!.update(<Harness />);
  });
}
async function depart(kind: 'screen' | 'background' | 'unmount') {
  await act(async () => {
    if (kind === 'unmount') {
      renderer!.unmount();
      renderer = undefined;
    } else {
      if (kind === 'screen') mockFocused = false;
      else mockForeground = false;
      renderer!.update(<Harness />);
    }
  });
}
async function returnToScreen() {
  await act(async () => {
    mockFocused = true;
    mockForeground = true;
    renderer!.update(<Harness />);
  });
}
function deferNextCapture() {
  const wait = deferred<void>();
  const boundary = mockBoundary;
  mockCapture.mockImplementationOnce(async () => {
    await wait.promise;
    // Model helpers.ts: capture owns the snapshot taken BEFORE native I/O,
    // and rejects a changed session rather than returning the new boundary.
    if (boundary.epoch !== mockBoundary.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
    return boundary;
  });
  return wait;
}

describe('certificate issue and recovery presentation ownership', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockIdentity = 'account-a';
    mockBoundary = {epoch: 1, scope: mockIdentity};
    mockFocused = true;
    mockForeground = true;
    mockRemote.clear();
    mockCapture.mockReset().mockImplementation(async () => mockBoundary);
    mockStorageKey
      .mockReset()
      .mockImplementation(
        async (key: string, boundary: AccountSessionBoundary) =>
          `${key}:${boundary.scope}`,
      );
    mockGetItem.mockReset().mockResolvedValue(null);
    mockSaveItem.mockReset().mockResolvedValue(undefined);
    mockGet.mockReset().mockImplementation(async () => ({
      data: {data: mockRemote.get(mockIdentity) || []},
    }));
    mockPost.mockReset().mockResolvedValue(acceptedResponse);
    mockLearning.mockReset().mockResolvedValue([readyCourse]);
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it.each(['screen', 'background', 'unmount'] as const)(
    'does not send an issue prepared by a departed %s presentation',
    async departure => {
      await mount();
      await openIssue();
      const capture = deferNextCapture();
      let action!: Promise<void>;
      await act(async () => {
        action = controller.confirmIssueCertificate();
      });
      await depart(departure);
      await act(async () => {
        capture.resolve();
        await action;
      });
      expect(mockPost).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
      if (renderer) {
        await returnToScreen();
        expect(controller.issuing).toBe(false);
        await act(async () => controller.confirmIssueCertificate());
        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(mockPost).toHaveBeenCalledWith('certificates/52/issue', {
          holder_name: 'طالب ركن',
        });
      }
    },
  );

  it.each(['single', 'all'] as const)(
    'does not send %s recovery after teardown during session capture',
    async kind => {
      await mount(true);
      const capture = deferNextCapture();
      let action!: Promise<void>;
      await act(async () => {
        action =
          kind === 'single'
            ? controller.retryPendingCertificate(controller.certificates[0])
            : controller.recoverPendingCertificates();
      });
      await depart('unmount');
      await act(async () => {
        capture.resolve();
        await action;
      });
      expect(mockPost).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
    },
  );

  it('rejects a stale issue callback instead of recapturing the next account', async () => {
    await mount();
    await openIssue();
    const staleConfirm = controller.confirmIssueCertificate;
    await changeAccount();
    const captures = mockCapture.mock.calls.length;
    await act(async () => staleConfirm());
    expect(mockCapture).toHaveBeenCalledTimes(captures);
    expect(mockPost).not.toHaveBeenCalled();
    expect(controller.issueCourse).toBeNull();
    expect(controller.issuing).toBe(false);
  });

  it.each(['issue', 'single', 'all'] as const)(
    'ignores the late %s failure without alerting or reloading the next account',
    async kind => {
      await mount(kind !== 'issue');
      if (kind === 'issue') await openIssue();
      const request = deferred<typeof acceptedResponse>();
      mockPost.mockImplementationOnce(() => request.promise);
      let action!: Promise<void>;
      await act(async () => {
        action =
          kind === 'issue'
            ? controller.confirmIssueCertificate()
            : kind === 'single'
            ? controller.retryPendingCertificate(controller.certificates[0])
            : controller.recoverPendingCertificates();
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      await changeAccount();
      const reads = mockGet.mock.calls.length;
      await act(async () => {
        request.reject(new Error('old network failure'));
        await action;
      });
      expect(Alert.alert).not.toHaveBeenCalled();
      expect(mockGet).toHaveBeenCalledTimes(reads);
      expect(controller.certificates).toEqual([]);
      expect(controller.loadError).toBe('');
      expect(controller.issuing).toBe(false);
    },
  );

  it('cannot clear a new owner issue flight when the old request settles', async () => {
    await mount();
    await openIssue();
    const oldRequest = deferred<typeof acceptedResponse>();
    const currentRequest = deferred<typeof acceptedResponse>();
    mockPost
      .mockImplementationOnce(() => oldRequest.promise)
      .mockImplementationOnce(() => currentRequest.promise);
    let oldAction!: Promise<void>;
    let currentAction!: Promise<void>;
    await act(async () => {
      oldAction = controller.confirmIssueCertificate();
    });
    await changeAccount();
    await openIssue();
    await act(async () => {
      currentAction = controller.confirmIssueCertificate();
    });
    await act(async () => {
      oldRequest.reject(new Error('obsolete'));
      await oldAction;
    });
    expect(controller.issuing).toBe(true);
    await act(async () => controller.confirmIssueCertificate());
    expect(mockPost).toHaveBeenCalledTimes(2);
    await act(async () => {
      currentRequest.resolve(acceptedResponse);
      await currentAction;
    });
    expect(controller.issuing).toBe(false);
    expect(controller.certificatePending).toBe(true);
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('rehydrates an accepted server credential after leaving during the POST', async () => {
    await mount();
    await openIssue();
    const request = deferred<typeof acceptedResponse>();
    mockPost.mockImplementationOnce(() => request.promise);
    let action!: Promise<void>;
    await act(async () => {
      action = controller.confirmIssueCertificate();
    });
    await depart('screen');
    mockRemote.set('account-a', [pendingDto]);
    await act(async () => {
      request.resolve(acceptedResponse);
      await action;
    });
    expect(controller.certificatePending).toBe(false);
    await returnToScreen();
    expect(controller.certificates[0].publicId).toBe(credential);
    expect(controller.certificatePending).toBe(true);
    expect(controller.readyCourses).toEqual([]);
    expect(mockPost).toHaveBeenCalledTimes(1);
    await act(async () =>
      controller.retryPendingCertificate(controller.certificates[0]),
    );
    // Recovery addresses the reserved user/course row, never a new name.
    expect(mockPost).toHaveBeenLastCalledWith('certificates/52/issue');
  });

  it.each(['issue', 'single', 'all'] as const)(
    'keeps a dispatched %s locked through return, its response and post-response reconciliation',
    async kind => {
      await mount(kind !== 'issue');
      if (kind === 'issue') await openIssue();
      const pendingCertificate =
        controller.certificates[0] || ({courseId: '52'} as never);
      const post = deferred<typeof acceptedResponse>();
      mockPost.mockImplementationOnce(() => post.promise);
      let action!: Promise<void>;
      await act(async () => {
        action =
          kind === 'issue'
            ? controller.confirmIssueCertificate()
            : kind === 'single'
            ? controller.retryPendingCertificate(pendingCertificate)
            : controller.recoverPendingCertificates();
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      await depart('background');

      const returnRead = deferred<{data: {data: unknown[]}}>();
      mockGet.mockImplementationOnce(() => returnRead.promise);
      await returnToScreen();
      expect(controller.loading).toBe(true);
      expect(controller.issueReady).toBe(false);
      expect(controller.mutationReady).toBe(false);
      await act(async () => {
        await controller.confirmIssueCertificate();
        await controller.retryPendingCertificate(pendingCertificate);
        await controller.recoverPendingCertificates();
      });
      expect(mockPost).toHaveBeenCalledTimes(1);

      // Even a completed read may predate this still-dispatched POST.
      await act(async () => returnRead.resolve({data: {data: []}}));
      expect(controller.loading).toBe(false);
      expect(controller.issuing).toBe(true);
      expect(controller.issueReady).toBe(false);
      expect(controller.mutationReady).toBe(false);
      await act(async () => controller.confirmIssueCertificate());
      expect(mockPost).toHaveBeenCalledTimes(1);

      const receiptRead = deferred<{data: {data: unknown[]}}>();
      mockGet.mockImplementationOnce(() => receiptRead.promise);
      await act(async () => post.resolve(acceptedResponse));
      expect(controller.loading).toBe(true);
      await act(async () => {
        await controller.confirmIssueCertificate();
        await controller.retryPendingCertificate(pendingCertificate);
        await controller.recoverPendingCertificates();
      });
      expect(mockPost).toHaveBeenCalledTimes(1);
      await act(async () => {
        receiptRead.resolve({data: {data: [pendingDto]}});
        await action;
      });
      expect(controller.loading).toBe(false);
      expect(controller.issuing).toBe(false);
      expect(controller.certificates[0].publicId).toBe(credential);
      expect(controller.readyCourses).toEqual([]);
      expect(controller.certificatePending).toBe(true);
      expect(controller.mutationReady).toBe(true);
    },
  );

  it('keeps a receipt accepted while away when the return read fails and allows explicit recovery', async () => {
    await mount();
    await openIssue();
    const post = deferred<typeof acceptedResponse>();
    mockPost.mockImplementationOnce(() => post.promise);
    let action!: Promise<void>;
    await act(async () => {
      action = controller.confirmIssueCertificate();
    });
    await depart('background');
    await act(async () => {
      post.resolve(acceptedResponse);
      await action;
    });
    mockGet.mockRejectedValueOnce(new Error('offline on return'));
    await returnToScreen();
    expect(controller.certificatePending).toBe(true);
    expect(controller.readyCourses).toEqual([]);
    expect(controller.issueReady).toBe(false);
    expect(controller.mutationReady).toBe(true);
    await act(async () => controller.recoverPendingCertificates());
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(mockPost).toHaveBeenLastCalledWith('certificates/52/issue');
  });

  it('keeps a current accepted issue single-flight and recovers only that course', async () => {
    await mount();
    await openIssue();
    const capture = deferNextCapture();
    let action!: Promise<void>;
    await act(async () => {
      action = controller.confirmIssueCertificate();
    });
    await act(async () => controller.confirmIssueCertificate());
    expect(mockPost).not.toHaveBeenCalled();
    await act(async () => {
      capture.resolve();
      await action;
    });
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(controller.certificatePending).toBe(true);
    expect(controller.readyCourses).toEqual([]);
    await act(async () => controller.recoverPendingCertificates());
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(mockPost).toHaveBeenLastCalledWith('certificates/52/issue');
  });

  it.each(['issue', 'recover', 'read', 'cache'] as const)(
    'rejects an already stale boundary before the %s service performs I/O',
    async operation => {
      const stale = mockBoundary;
      mockIdentity = 'account-b';
      mockBoundary = {epoch: 2, scope: mockIdentity};
      const request =
        operation === 'issue'
          ? issueCertificate('52', 'طالب ركن', stale)
          : operation === 'recover'
          ? recoverCertificate('52', stale)
          : operation === 'read'
          ? getCertificates(stale)
          : getCachedCertificates(stale);
      await expect(request).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
      expect(mockPost).not.toHaveBeenCalled();
      expect(mockGet).not.toHaveBeenCalled();
      expect(mockGetItem).not.toHaveBeenCalled();
      expect(mockStorageKey).not.toHaveBeenCalled();
    },
  );

  it.each(['read', 'cache'] as const)(
    'does not %s account data after a session change during storage-key preparation',
    async operation => {
      const key = deferred<string>();
      mockStorageKey.mockImplementationOnce(() => key.promise);
      const request =
        operation === 'read'
          ? getCertificates(mockBoundary)
          : getCachedCertificates(mockBoundary);
      // Attach a rejection observer before allowing the async boundary to fail.
      const result = (async () => {
        await expect(request).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
      })();
      await Promise.resolve();
      mockBoundary = {epoch: 2, scope: 'account-b'};
      key.resolve('@rokn/certificates-cache/v2:account-a');
      await result;
      expect(mockSaveItem).not.toHaveBeenCalled();
      expect(mockGetItem).not.toHaveBeenCalled();
    },
  );
});
