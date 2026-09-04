import {
  manifestRefreshDelayMs,
  qualityForTrackHeight,
  scheduledManifestRefreshDelayMs,
  sanitizePlaybackDiagnostics,
  sanitizePlaybackErrorCode,
} from '../src/components/VideoPlayer/playbackTelemetry';

describe('playback telemetry policy', () => {
  it('keeps useful diagnostics while dropping URLs, tokens and arbitrary keys', () => {
    expect(
      sanitizePlaybackDiagnostics({
        source_type: 'm3u8',
        stage: 'fallback',
        buffered_seconds: 3,
        source_url: 'https://signed.example/playlist.m3u8?token=secret',
        failure_kind: 'https://should-not-leave.test',
        email: 'student@example.com',
      }),
    ).toEqual({
      source_type: 'm3u8',
      stage: 'fallback',
      buffered_seconds: 3,
    });
  });

  it('normalizes native error codes without retaining native messages', () => {
    expect(sanitizePlaybackErrorCode('Decoder init failed (1007)')).toBe(
      'decoder_init_failed_1007',
    );
    expect(sanitizePlaybackErrorCode('https://cdn.test/video')).toBeUndefined();
  });

  it('maps only observed track heights to an effective quality', () => {
    expect(qualityForTrackHeight(undefined)).toBeUndefined();
    expect(qualityForTrackHeight(1080)).toBe('1080p');
    expect(qualityForTrackHeight(720)).toBe('720p');
    expect(qualityForTrackHeight(480)).toBe('480p');
    expect(qualityForTrackHeight(240)).toBe('360p');
  });

  it('refreshes a signed manifest before expiry without immediate churn', () => {
    const now = Date.parse('2026-08-11T10:00:00.000Z');
    expect(
      manifestRefreshDelayMs('2026-08-11T10:10:00.000Z', now),
    ).toBe(510_000);
    expect(
      manifestRefreshDelayMs('2026-08-11T10:00:04.000Z', now),
    ).toBe(0);
    expect(manifestRefreshDelayMs(undefined, now)).toBeNull();
    expect(
      scheduledManifestRefreshDelayMs(
        '2026-08-11T10:08:30.000Z',
        '2026-08-11T10:10:00.000Z',
        now,
      ),
    ).toBe(510_000);
    expect(
      scheduledManifestRefreshDelayMs(
        '2026-08-11T10:20:00.000Z',
        '2026-08-11T10:10:00.000Z',
        now,
      ),
    ).toBe(510_000);
  });
});
