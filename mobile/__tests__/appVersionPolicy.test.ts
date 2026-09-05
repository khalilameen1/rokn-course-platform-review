import {
  createVersionCheckPayload,
  MOBILE_API_CAPABILITIES,
  parseAppVersionResponse,
  trustedUpdateUrl,
} from '../src/services/appVersionPolicy';
import {cleanUnicodeText} from '../src/utils/unicodeText';

describe('app update policy', () => {
  it('preserves authored technical release copy without changing update gating or URLs', () => {
    const message = 'New in Rokn 2: OAuth fixes and ريلز 2026';
    const notes =
      'SQLSTATE explanations\n\n  const limit = 3;\n<input required>';
    const url = 'https://rokn.app/releases/Rokn.apk';
    const notice = parseAppVersionResponse(
      {
        update_required: true,
        is_force_update: true,
        download_url: url,
        update_message: message + '\u202e',
        release_notes: notes + '\u0000',
        latest_version_code: 42,
        minimum_supported_version_code: 40,
      },
      'direct',
    );

    expect(cleanUnicodeText(notice?.message)).toBe(message);
    expect(cleanUnicodeText(notice?.releaseNotes)).toBe(notes);
    expect(notice).toMatchObject({
      isBlocking: true,
      downloadUrl: url,
      hasUnsafeDownloadUrl: false,
      latestVersionCode: 42,
      minimumSupportedVersionCode: 40,
    });
  });

  it('keeps authored copy bounds and uses the default only when update copy is missing', () => {
    const notice = parseAppVersionResponse(
      {
        update_required: true,
        update_message: 'Release '.repeat(50),
        release_notes: 'Detail '.repeat(100),
      },
      'direct',
    );
    expect(cleanUnicodeText(notice?.message)).toBe(
      'Release '.repeat(50).slice(0, 240).trim(),
    );
    expect(cleanUnicodeText(notice?.releaseNotes)).toBe(
      'Detail '.repeat(100).slice(0, 600).trim(),
    );
    expect(
      parseAppVersionResponse({update_required: true}, 'direct'),
    ).toMatchObject({
      message: 'نسخة أحدث من ركن جاهزة',
      releaseNotes: null,
      isBlocking: false,
    });
  });
  it('sends Android versionCode and iOS version plus build_number', () => {
    expect(
      createVersionCheckPayload({
        platform: 'android',
        version: '1.0.14',
        androidVersionCode: 15,
        iosBuildNumber: '14',
        distributionChannel: 'play',
      }),
    ).toEqual({
      platform: 'android',
      version: 15,
      distribution_channel: 'play',
      api_contract_version: 1,
      capabilities: MOBILE_API_CAPABILITIES,
    });
    expect(
      createVersionCheckPayload({
        platform: 'ios',
        version: '1.0.14',
        androidVersionCode: 15,
        iosBuildNumber: '14',
        distributionChannel: 'appstore',
      }),
    ).toEqual({
      platform: 'ios',
      version: '1.0.14',
      build_number: 14,
      distribution_channel: 'appstore',
      api_contract_version: 1,
      capabilities: MOBILE_API_CAPABILITIES,
    });
  });

  it('rejects a distribution channel that cannot belong to the platform', () => {
    expect(
      createVersionCheckPayload({
        platform: 'android',
        version: '1.0.15',
        androidVersionCode: 16,
        iosBuildNumber: '15',
        distributionChannel: 'appstore',
      }),
    ).toBeNull();
    expect(
      createVersionCheckPayload({
        platform: 'ios',
        version: '1.0.15',
        androidVersionCode: 16,
        iosBuildNumber: '15',
        distributionChannel: 'direct',
      }),
    ).toBeNull();
  });

  it.each([
    ['play', 'https://play.google.com/store/apps/details?id=com.rokn'],
    ['appstore', 'https://apps.apple.com/eg/app/rokn/id123'],
    ['direct', 'https://rokn.app/downloads/Rokn.apk'],
    ['direct', 'https://www.rokn.app/releases/Rokn.apk'],
  ] as const)('allows the %s channel store host', (channel, url) => {
    expect(trustedUpdateUrl(url, channel)).toBe(url);
  });

  it.each([
    ['play', 'https://rokn.app/Rokn.apk'],
    ['play', 'https://play.google.com.evil.example/store/apps/com.rokn'],
    ['appstore', 'https://itunes.apple.com/app/rokn'],
    ['direct', 'https://cdn.example/Rokn.apk'],
    ['play', 'https://play.google.com/store/apps/details?id=another.app'],
    ['play', 'https://play.google.com/about'],
    ['appstore', 'https://apps.apple.com/eg/developer/rokn/id123'],
    ['direct', 'https://rokn.app/downloads/latest'],
    ['direct', 'https://rokn.com/releases/Rokn.apk'],
    ['direct', 'http://rokn.app/Rokn.apk'],
  ] as const)('rejects an unsafe %s channel URL', (channel, url) => {
    expect(trustedUpdateUrl(url, channel)).toBeNull();
  });

  it('maps the exact backend fields and blocks an actionable forced update', () => {
    expect(
      parseAppVersionResponse(
        {
          data: {
            update_required: true,
            is_force_update: true,
            latest_version: '1.0.15',
            update_message: 'نسخة أحدث وأخف',
            download_url:
              'https://play.google.com/store/apps/details?id=com.rokn',
            release_notes: 'تحسين تشغيل الفيديو',
          },
        },
        'play',
      ),
    ).toMatchObject({
      latestVersion: '1.0.15',
      latestVersionCode: null,
      latestBuildNumber: null,
      message: 'نسخة أحدث وأخف',
      isBlocking: true,
      hasUnsafeDownloadUrl: false,
    });
  });

  it('does not brick launch when a forced update has an unsafe URL', () => {
    expect(
      parseAppVersionResponse(
        {
          data: {
            update_required: true,
            is_force_update: true,
            download_url: 'https://evil.example/Rokn.apk',
          },
        },
        'direct',
      ),
    ).toMatchObject({
      downloadUrl: null,
      isBlocking: false,
      hasUnsafeDownloadUrl: true,
    });
  });

  it('stays silent when the backend says no update is required', () => {
    expect(
      parseAppVersionResponse(
        {data: {update_required: false, is_force_update: false}},
        'play',
      ),
    ).toBeNull();
  });
});
