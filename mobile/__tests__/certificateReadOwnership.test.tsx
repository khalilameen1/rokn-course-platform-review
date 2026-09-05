import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockFocused = true;
let mockForeground = true;
let mockIdentity = 'account-a';
const mockGetCertificates = jest.fn();
const mockGetLearningCourses = jest.fn();
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
  useSelector: () => ({api_token: 'token-a', user: {id: 7, name: 'طالب ركن'}}),
}));
jest.mock('../src/services/roknApi', () => ({
  getCertificates: () => mockGetCertificates(),
  getCachedCertificates: async () => [],
  getLearningCourses: () => mockGetLearningCourses(),
  hasSession: async () => true,
  issueCertificate: jest.fn(),
  recoverCertificate: jest.fn(),
}));
jest.mock('../src/services/systemActions', () => ({}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {scope: string}) => {
    if (boundary.scope !== mockIdentity)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  captureAccountSessionBoundary: async () => ({epoch: 1, scope: mockIdentity}),
  extractUserProfile: () => ({id: 7, name: 'طالب ركن'}),
  sessionIdentityKey: () => mockIdentity,
}));
jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));

import {useCertificatesController} from '../src/screens/Profile/certificates/useCertificatesController';

let controller!: ReturnType<typeof useCertificatesController>;
const Harness = () => {
  controller = useCertificatesController();
  return null;
};

describe('certificate screen read ownership', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    mockFocused = true;
    mockForeground = true;
    mockIdentity = 'account-a';
    mockGetCertificates.mockReset().mockResolvedValue([]);
    mockGetLearningCourses.mockReset().mockResolvedValue([]);
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it.each(['screen', 'background'] as const)(
    'pauses pending polling on %s departure and resumes on return',
    async departure => {
      mockGetCertificates.mockResolvedValue([
        {publicId: 'pending-52', courseId: '52', status: 'pending'},
      ]);
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness />);
        });
        expect(controller.certificatePending).toBe(true);
        expect(mockGetCertificates).toHaveBeenCalledTimes(1);
        await act(async () => {
          if (departure === 'screen') mockFocused = false;
          else mockForeground = false;
          renderer.update(<Harness />);
        });
        await act(async () => {
          await jest.advanceTimersByTimeAsync(20_000);
        });
        expect(mockGetCertificates).toHaveBeenCalledTimes(1);
        expect(mockGetLearningCourses).toHaveBeenCalledTimes(1);
        await act(async () => {
          mockFocused = true;
          mockForeground = true;
          renderer.update(<Harness />);
        });
        expect(mockGetCertificates).toHaveBeenCalledTimes(2);
      } finally {
        if (renderer) await act(async () => renderer.unmount());
      }
    },
  );

  it('keeps the next account certificate list when an old account read settles late', async () => {
    let finishOld!: (value: unknown[]) => void;
    mockGetCertificates.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finishOld = resolve;
        }),
    );
    const currentCertificate = {
      publicId: 'account-b-certificate',
      courseId: '71',
      status: 'active',
    };
    mockGetCertificates.mockResolvedValue([currentCertificate]);
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      await act(async () => {
        mockIdentity = 'account-b';
        renderer.update(<Harness />);
      });
      expect(controller.certificates).toEqual([currentCertificate]);
      await act(async () => {
        finishOld([
          {publicId: 'account-a-private', courseId: '52', status: 'active'},
        ]);
      });
      expect(controller.certificates).toEqual([currentCertificate]);
      expect(controller.loadError).toBe('');
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });

  it('does not replace a successful refocus result with an older partial-read error', async () => {
    let finishOldCertificates!: (value: unknown[]) => void;
    mockGetCertificates.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finishOldCertificates = resolve;
        }),
    );
    mockGetLearningCourses.mockRejectedValueOnce(
      new Error('old learning read failed'),
    );
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      await act(async () => {
        mockFocused = false;
        renderer.update(<Harness />);
      });
      await act(async () => {
        mockFocused = true;
        renderer.update(<Harness />);
      });
      expect(mockGetCertificates).toHaveBeenCalledTimes(2);
      expect(controller.loading).toBe(false);
      expect(controller.loadError).toBe('');
      await act(async () => {
        finishOldCertificates([]);
      });
      expect(controller.loadError).toBe('');
      expect(controller.loading).toBe(false);
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });
});
