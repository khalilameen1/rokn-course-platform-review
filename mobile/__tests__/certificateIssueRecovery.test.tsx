import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetCertificates = jest.fn();
const mockGetCachedCertificates = jest.fn();
const mockGetLearningCourses = jest.fn();
const mockIssueCertificate = jest.fn();
const mockRecoverCertificate = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useIsFocused: () => true,
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(effect, [effect]);
  },
}));

jest.mock('react-redux', () => ({
  useSelector: () => ({api_token: 'token-a', user: {id: 7, name: 'طالب ركن'}}),
}));

jest.mock('../src/services/roknApi', () => ({
  getCertificates: (...args: unknown[]) => mockGetCertificates(...args),
  getCachedCertificates: (...args: unknown[]) => mockGetCachedCertificates(...args),
  getLearningCourses: (...args: unknown[]) => mockGetLearningCourses(...args),
  hasSession: jest.fn(async () => true),
  issueCertificate: (...args: unknown[]) => mockIssueCertificate(...args),
  recoverCertificate: (...args: unknown[]) => mockRecoverCertificate(...args),
}));

jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
  shareOnce: jest.fn(),
}));

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'account-a',
  })),
  extractUserProfile: () => ({id: 7, name: 'طالب ركن'}),
  sessionIdentityKey: () => 'account-a',
}));

jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({
  openCourseAttachment: jest.fn(),
}));

jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));

jest.mock('../src/utils/settleWithin', () => ({
  settleWithin: async (value: Promise<unknown>, fallback: unknown) =>
    value.catch(() => fallback),
}));

import {useCertificatesController} from '../src/screens/Profile/certificates/useCertificatesController';

const readyCourse = {
  id: '52',
  title: 'كورس تجريبي',
  progress: 100,
  completedSections: 3,
  totalSections: 3,
  accessType: 'paid',
  certificateAvailable: true,
};

describe('accepted certificate issue recovery', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetCachedCertificates.mockReset().mockResolvedValue([]);
    mockGetCertificates
      .mockResolvedValueOnce([])
      .mockRejectedValueOnce(new Error('offline'));
    mockGetLearningCourses.mockResolvedValue([readyCourse]);
    // A null result is the explicit accepted/202 generating contract.
    mockIssueCertificate.mockResolvedValue(null);
    mockRecoverCertificate.mockResolvedValue(null);
  });

  it('keeps the accepted pending state when its first reconciliation read fails', async () => {
    let controller!: ReturnType<typeof useCertificatesController>;
    const Harness = () => {
      controller = useCertificatesController('طالب ركن');
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(controller.readyCourses).toHaveLength(1);

    await act(async () => {
      controller.openIssueCertificate(readyCourse as never);
    });
    await act(async () => {
      await controller.confirmIssueCertificate();
    });

    expect(mockIssueCertificate).toHaveBeenCalledTimes(1);
    expect(controller.certificatePending).toBe(true);
    expect(controller.readyCourses).toEqual([]);
    expect(controller.loadError).toContain('نعرض المتاح الآن');

    await act(async () => renderer.unmount());
  });

  it('does not offer a second issue while the accepted row is not visible yet', async () => {
    mockGetCertificates.mockReset();
    mockGetCertificates.mockResolvedValue([]);
    let controller!: ReturnType<typeof useCertificatesController>;
    const Harness = () => {
      controller = useCertificatesController('طالب ركن');
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(controller.readyCourses).toHaveLength(1);

    await act(async () => {
      controller.openIssueCertificate(readyCourse as never);
    });
    await act(async () => {
      await controller.confirmIssueCertificate();
    });

    expect(controller.certificatePending).toBe(true);
    expect(controller.readyCourses).toEqual([]);
    await act(async () => {
      await controller.recoverPendingCertificates();
    });
    expect(mockRecoverCertificate).toHaveBeenCalledWith(
      '52',
      expect.objectContaining({scope: 'account-a'}),
    );
    expect(mockIssueCertificate).toHaveBeenCalledTimes(1);

    await act(async () => renderer.unmount());
  });

  it('keeps a newly accepted issue pending when cached earlier certificates render before a failed reconciliation', async () => {
    let controller!: ReturnType<typeof useCertificatesController>;
    const Harness = () => {
      controller = useCertificatesController('طالب ركن');
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    const previousCertificate = {
      publicId: 'previous-certificate',
      courseId: '51',
      status: 'active',
      holderName: 'طالب ركن',
      courseName: 'كورس سابق',
    };
    mockGetCachedCertificates.mockResolvedValue([previousCertificate]);
    try {
      await act(async () => {
        controller.openIssueCertificate(readyCourse as never);
      });
      await act(async () => controller.confirmIssueCertificate());

      expect(controller.certificates).toEqual([previousCertificate]);
      expect(controller.certificatePending).toBe(true);
      expect(controller.readyCourses).toEqual([]);
      expect(mockIssueCertificate).toHaveBeenCalledTimes(1);
      mockGetCertificates.mockResolvedValue([]);
      await act(async () => controller.recoverPendingCertificates());
      expect(mockRecoverCertificate).toHaveBeenCalledWith(
        '52',
        expect.objectContaining({scope: 'account-a'}),
      );
      expect(mockIssueCertificate).toHaveBeenCalledTimes(1);

      const completedCertificate = {
        ...previousCertificate,
        publicId: 'newly-completed-certificate',
        courseId: '52',
      };
      mockGetCachedCertificates.mockResolvedValue([
        previousCertificate,
        {...completedCertificate, status: 'pending'},
      ]);
      mockGetCertificates.mockResolvedValue([
        previousCertificate,
        completedCertificate,
      ]);
      await act(async () => controller.loadCertificates());
      expect(controller.certificatePending).toBe(false);
      expect(controller.certificates).toEqual([
        previousCertificate,
        completedCertificate,
      ]);
      expect(controller.readyCourses).toEqual([]);
      expect(mockIssueCertificate).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => renderer.unmount());
    }
  });
});
