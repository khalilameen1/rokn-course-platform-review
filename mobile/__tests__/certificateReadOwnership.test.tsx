import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockFocused = true;
let mockForeground = true;
let mockIdentity = 'account-a';
const mockGetCertificates = jest.fn();
const mockGetLearningCourses = jest.fn();
const mockGetCachedCertificates = jest.fn();
const mockIssueCertificate = jest.fn();
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
  getCachedCertificates: () => mockGetCachedCertificates(),
  getLearningCourses: () => mockGetLearningCourses(),
  hasSession: async () => true,
  issueCertificate: (...args: unknown[]) => mockIssueCertificate(...args),
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

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const readyCourse = (id: string) => ({
  id,
  title: `كورس ${id}`,
  progress: 100,
  totalSections: 3,
  completedSections: 3,
  accessType: 'paid',
  certificateAvailable: true,
});

describe('certificate screen read ownership', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    mockFocused = true;
    mockForeground = true;
    mockIdentity = 'account-a';
    mockGetCertificates.mockReset().mockResolvedValue([]);
    mockGetLearningCourses.mockReset().mockResolvedValue([]);
    mockGetCachedCertificates.mockReset().mockResolvedValue([]);
    mockIssueCertificate.mockReset();
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it.each([undefined, '52'])(
    'publishes issued artifacts while eligibility remains pending (course scope=%s)',
    async courseId => {
      const learning = deferred<unknown[]>();
      const issued = {publicId: 'issued-52', courseId: '52', status: 'active'};
      const other = {publicId: 'issued-71', courseId: '71', status: 'active'};
      mockGetCertificates.mockResolvedValue([issued, other]);
      mockGetLearningCourses.mockReturnValueOnce(learning.promise);
      const Probe = () => {
        controller = useCertificatesController(undefined, courseId);
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Probe />);
        });
        expect(controller.certificates).toEqual(
          courseId ? [issued] : [issued, other],
        );
        expect(controller.loading).toBe(false);
        expect(controller.eligibilityLoading).toBe(true);
        expect(controller.issueReady).toBe(false);
        await act(async () => {
          controller.openIssueCertificate(readyCourse('78') as never);
          await controller.confirmIssueCertificate();
        });
        expect(controller.issueCourse).toBeNull();
        expect(mockIssueCertificate).not.toHaveBeenCalled();
        await act(async () =>
          learning.resolve([readyCourse('52'), readyCourse('78')]),
        );
        expect(controller.eligibilityLoading).toBe(false);
        expect(controller.issueReady).toBe(true);
        expect(controller.readyCourses.map(course => course.id)).toEqual(
          courseId ? [] : ['78'],
        );
        expect(mockIssueCertificate).not.toHaveBeenCalled();
      } finally {
        await act(async () => {
          renderer.unmount();
          learning.resolve([]);
        });
      }
    },
  );

  it.each(['active', 'revoked', 'empty'] as const)(
    'does not let a late cached credential replace a fresh %s result',
    async status => {
      const cache = deferred<unknown[]>();
      const issued = {publicId: 'issued-52', courseId: '52', status};
      mockGetCertificates.mockResolvedValue(status === 'empty' ? [] : [issued]);
      mockGetCachedCertificates.mockReturnValueOnce(cache.promise);
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness />);
        });
        expect(controller.loading).toBe(false);
        await act(async () => cache.resolve([{...issued, status: 'pending'}]));
        expect(controller.certificates).toEqual(
          status === 'active' ? [issued] : [],
        );
        expect(controller.certificatePending).toBe(false);
      } finally {
        await act(async () => {
          renderer.unmount();
          cache.resolve([]);
        });
      }
    },
  );

  it('offers a read retry after certificate failure without waiting for eligibility or allowing issuance', async () => {
    const learning = deferred<unknown[]>();
    mockGetCertificates.mockRejectedValueOnce(
      new Error('certificate read failed'),
    );
    mockGetLearningCourses.mockReturnValueOnce(learning.promise);
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      expect(controller.loading).toBe(false);
      expect(controller.loadError).not.toBe('');
      expect(controller.mutationReady).toBe(true);
      expect(controller.issueReady).toBe(false);
      await act(async () => learning.resolve([readyCourse('78')]));
      expect(controller.readyCourses).toEqual([]);
      expect(controller.issueReady).toBe(false);
      expect(mockIssueCertificate).not.toHaveBeenCalled();
    } finally {
      await act(async () => {
        renderer.unmount();
        learning.resolve([]);
      });
    }
  });

  it('does not replace the current account eligibility with an older account result', async () => {
    const learning = deferred<unknown[]>();
    mockGetLearningCourses.mockReturnValueOnce(learning.promise);
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      expect(controller.eligibilityLoading).toBe(true);
      await act(async () => {
        mockIdentity = 'account-b';
        renderer.update(<Harness />);
      });
      expect(controller.eligibilityLoading).toBe(false);
      await act(async () => learning.resolve([readyCourse('78')]));
      expect(controller.readyCourses).toEqual([]);
      expect(controller.eligibilityLoading).toBe(false);
      expect(controller.loadError).toBe('');
    } finally {
      await act(async () => {
        renderer.unmount();
        learning.resolve([]);
      });
    }
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
