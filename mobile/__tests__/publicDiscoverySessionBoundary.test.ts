const mockGet = jest.fn();
const mockTransport = jest.fn();
const mockLoadSession = jest.fn();
let mockSnapshot = {ready: false, session: null as unknown, epoch: 1};

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  extractApiToken: (session: {api_token?: string} | null) =>
    session?.api_token || '',
  getItem: jest.fn(async () => null),
  saveItem: jest.fn(async () => true),
  removeItem: jest.fn(async () => true),
}));
jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: () => mockSnapshot,
  loadSecureSession: () => mockLoadSession(),
}));

import {
  assertResponseStillBelongsToSession,
  captureSessionAtApiCall,
  type RoknRequestConfig,
} from '../src/constants/apiSessionBoundary';

type Discovery = 'settings' | 'content' | 'features';
const responseFor = (kind: Discovery) => ({
  data: {
    data:
      kind === 'settings'
        ? [{contract_version: 2, revision: 'current-settings'}]
        : kind === 'content'
        ? {source: 'dashboard', managed_body: 'محتوى منشور'}
        : {
            version: 'current-features',
            expires_at: new Date(Date.now() + 60_000).toISOString(),
            flags: {
              checkout: true,
              playback: true,
              project_uploads: true,
              ai_chat: true,
            },
          },
  },
});

const freshRead = (kind: Discovery): (() => Promise<unknown>) => {
  let read!: () => Promise<unknown>;
  // Isolate only service caches. Transport uses the real shared session capture
  // and response guard below, not a successful-GET stub that hides this defect.
  jest.isolateModules(() => {
    if (kind === 'settings') {
      read = require('../src/services/publicAppSettings').getPublicAppSettings;
    } else if (kind === 'content') {
      const content = require('../src/services/publicContent');
      read = () => content.getManagedPublicContent('privacy');
    } else {
      read = require('../src/services/productFeatures').refreshProductFeatures;
    }
  });
  return read;
};

const flush = async () => {
  for (let index = 0; index < 12; index += 1) await Promise.resolve();
};

describe.each<Discovery>(['settings', 'content', 'features'])(
  '%s discovery is independent of account restore',
  kind => {
    beforeEach(() => {
      jest.clearAllMocks();
      mockSnapshot = {ready: false, session: null, epoch: 1};
      mockGet.mockImplementation(async (url: string, config?: RoknRequestConfig) => {
        const captured = await captureSessionAtApiCall(config, 'get');
        const response = await mockTransport(url, captured);
        await assertResponseStillBelongsToSession(
          captured as unknown as Record<string, unknown>,
        );
        return response;
      });
      mockTransport.mockResolvedValue(responseFor(kind));
    });

    it('dispatches without waiting for the native session read', async () => {
      let finishRestore!: () => void;
      mockLoadSession.mockImplementation(
        () => new Promise<void>(resolve => { finishRestore = resolve; }),
      );
      const result = freshRead(kind)();
      await flush();
      const dispatchedBeforeRestore = mockTransport.mock.calls.length;
      const restoreCalls = mockLoadSession.mock.calls.length;
      // Settle the pre-fix path too so the regression itself leaves no flight.
      finishRestore?.();
      await result;

      expect(dispatchedBeforeRestore).toBe(1);
      expect(restoreCalls).toBe(0);
      expect(mockTransport.mock.calls[0][1]).toMatchObject({
        roknSessionNeutral: true,
      });
    });

    it('keeps a valid public response when login finishes while it is in flight', async () => {
      mockSnapshot.ready = true;
      let finish!: (response: ReturnType<typeof responseFor>) => void;
      mockTransport.mockImplementation(
        () => new Promise(resolve => { finish = resolve; }),
      );
      const result = freshRead(kind)().then(
        value => ({value}),
        error => ({error}),
      );
      await flush();
      expect(mockTransport).toHaveBeenCalledTimes(1);
      mockSnapshot = {
        ready: true,
        session: {api_token: 'signed-in-token'},
        epoch: 2,
      };
      finish(responseFor(kind));

      const expected = kind === 'settings'
        ? {revision: 'current-settings'}
        : kind === 'content'
        ? 'محتوى منشور'
        : {version: 'current-features'};
      await expect(result).resolves.toMatchObject({value: expected});
      expect(mockLoadSession).not.toHaveBeenCalled();
    });
  },
);
