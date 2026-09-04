import appConfig from '../app.json';

jest.mock('react-native', () => ({
  NativeModules: {},
  Platform: {OS: 'android', Version: 34},
  Share: {share: jest.fn(async () => ({action: 'sharedAction'}))},
}));

jest.mock('../src/services/operationalTelemetry', () => ({
  getOperationalDiagnosticsSnapshot: jest.fn(async () => []),
}));
jest.mock('../src/services/productFeatures', () => ({
  getProductFeatureDiagnosticsSnapshot: jest.fn(async () => ({
    source: 'remote',
    version: 'release-7',
    expiresAt: '2026-08-12T10:05:00.000Z',
    flags: {
      checkout: true,
      playback: true,
      project_uploads: false,
      ai_chat: true,
    },
  })),
}));

type SharedPayload = {title?: string; message: string};
type DiagnosticsSnapshot = Array<Record<string, unknown>>;

const {Share} = jest.requireMock('react-native') as {
  Share: {
    share: jest.Mock<Promise<{action: string}>, [SharedPayload]>;
  };
};
const mockShare = Share.share;
const {
  getOperationalDiagnosticsSnapshot: mockGetOperationalDiagnosticsSnapshot,
} = jest.requireMock('../src/services/operationalTelemetry') as {
  getOperationalDiagnosticsSnapshot: jest.Mock<
    Promise<DiagnosticsSnapshot>,
    []
  >;
};
const {createDebugBundle, formatDebugBundle, shareDebugBundle} =
  require('../src/services/debugBundle') as typeof import('../src/services/debugBundle');

describe('debug bundle privacy boundary', () => {
  beforeEach(() => {
    mockShare.mockClear();
    mockGetOperationalDiagnosticsSnapshot.mockReset();
    mockGetOperationalDiagnosticsSnapshot.mockResolvedValue([]);
  });

  it('copies only allowlisted event fields and values', async () => {
    const bundle = await createDebugBundle({
      now: new Date('2026-08-12T10:00:00.000Z'),
      readOperationalEvents: async () => [
        {
          event: 'api_failure',
          severity: 'error',
          code: 'TOKEN_SUPER_SECRET',
          occurred_at: '2026-08-12T09:59:00.000Z',
          attempts: 200,
          email: 'learner@example.com',
          url: 'https://private.example/path?token=secret',
          stack: 'Error at privateFunction',
          message: 'Bearer very-secret-token',
        },
        {
          event: 'video_failure',
          severity: 'fatal',
          code: 'VIDEO_BUFFER_TIMEOUT',
          occurred_at: '2026-08-12T09:58:00.000Z',
          attempts: 1,
        },
        {
          event: 'learner@example.com',
          severity: 'error',
          code: 'SECRET',
          occurred_at: '2026-08-12T09:57:00.000Z',
        },
        {
          event: 'app_crash',
          severity: 'error',
          code: 'APP_ERROR',
          occurred_at: 'not-a-date',
        },
      ],
    });

    expect(bundle).toEqual({
      schema_version: 1,
      generated_at: '2026-08-12T10:00:00.000Z',
      app: {
        version: appConfig.expo.version,
        build_number: appConfig.expo.android.versionCode,
        platform: 'android',
        os_major: 34,
        distribution_channel: expect.stringMatching(/^(direct|play|appstore)$/),
      },
      feature_flags: {
        external_checkout_enabled: expect.any(Boolean),
        play_distribution: expect.any(Boolean),
        app_store_distribution: expect.any(Boolean),
        store_distribution: expect.any(Boolean),
      },
      product_controls: {
        source: 'remote',
        version: 'release-7',
        expires_at: '2026-08-12T10:05:00.000Z',
        flags: {
          checkout: true,
          playback: true,
          project_uploads: false,
          ai_chat: true,
        },
      },
      operational_events: [
        {
          event: 'api_failure',
          severity: 'error',
          code: 'API_ERROR',
          occurred_at: '2026-08-12T09:59:00.000Z',
          attempts: 99,
        },
        {
          event: 'video_failure',
          severity: 'fatal',
          code: 'VIDEO_BUFFER_TIMEOUT',
          occurred_at: '2026-08-12T09:58:00.000Z',
          attempts: 1,
        },
      ],
    });

    const serialized = formatDebugBundle(bundle).toLowerCase();
    expect(serialized).not.toMatch(
      /learner@example\.com|very-secret|private\.example/,
    );
    expect(serialized).not.toMatch(
      /"(?:token|email|url|stack|message|fingerprint)"/,
    );
  });

  it('falls back to no events when diagnostics cannot be read', async () => {
    const bundle = await createDebugBundle({
      readOperationalEvents: async () => {
        throw new Error('learner@example.com https://private.example token');
      },
    });

    expect(bundle.operational_events).toEqual([]);
    expect(formatDebugBundle(bundle)).not.toContain('learner@example.com');
  });

  it('shares only the sanitized serialization', async () => {
    mockGetOperationalDiagnosticsSnapshot.mockResolvedValue([
      {
        event: 'authentication_failure',
        severity: 'error',
        code: 'USER_EMAIL_learner@example.com',
        occurred_at: '2026-08-12T09:00:00.000Z',
        token: 'secret',
      },
    ]);

    await shareDebugBundle();

    expect(mockShare).toHaveBeenCalledTimes(1);
    expect(mockShare.mock.calls[0][0].title).toBe('معلومات دعم ركن');
    const message = mockShare.mock.calls[0][0].message.toLowerCase();
    expect(message).toContain('authentication_error');
    expect(message).not.toMatch(/learner@example\.com|secret|https?:\/\//);
  });
});
