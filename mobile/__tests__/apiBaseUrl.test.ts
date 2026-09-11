import {normalizeRoknApiUrl} from '../src/constants/apiBaseUrl';

describe('normalizeRoknApiUrl', () => {
  it('preserves the legacy origin when it is explicitly configured', () => {
    expect(
      normalizeRoknApiUrl(
        'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud',
      ),
    ).toBe(
      'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
    );
  });

  it('keeps a complete API base stable', () => {
    expect(normalizeRoknApiUrl('https://rokn.app/api/v1/')).toBe(
      'https://rokn.app/api/v1/',
    );
  });

  it('completes an API root without a version', () => {
    expect(normalizeRoknApiUrl('https://rokn.app/api')).toBe(
      'https://rokn.app/api/v1/',
    );
  });

  it('repairs a pasted endpoint or stale version to the client contract root', () => {
    expect(
      normalizeRoknApiUrl(
        'https://rokn.app/api/v2/courses/list?from=dashboard#preview',
      ),
    ).toBe('https://rokn.app/api/v1/');
  });

  it('does not let an invalid release value strand every request', () => {
    expect(normalizeRoknApiUrl('not a url')).toBe(
      'https://rokn.app/api/v1/',
    );
  });

  it('uses the branded API for releases without an override', () => {
    expect(normalizeRoknApiUrl()).toBe('https://rokn.app/api/v1/');
    expect(normalizeRoknApiUrl('https://rokn.app')).toBe(
      'https://rokn.app/api/v1/',
    );
  });
});
