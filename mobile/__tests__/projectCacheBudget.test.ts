jest.mock('react-native-fs', () => ({}));

import {
  assertPendingProjectCacheCapacity,
  PENDING_PROJECT_FILES_MAX_BYTES,
  PROJECT_SUBMISSION_MAX_BYTES,
  validatedProjectFileSize,
} from '../src/config/projects';

describe('pending project cache budget', () => {
  it('accepts a replacement that remains inside the 75 MiB queue budget', () => {
    expect(() =>
      assertPendingProjectCacheCapacity(
        PENDING_PROJECT_FILES_MAX_BYTES - 25 * 1024 * 1024,
        25 * 1024 * 1024,
      ),
    ).not.toThrow();
  });

  it('refuses a new file instead of deleting unsent submissions', () => {
    expect(() =>
      assertPendingProjectCacheCapacity(
        PENDING_PROJECT_FILES_MAX_BYTES - 10,
        11,
      ),
    ).toThrow('PROJECT_PENDING_CACHE_FULL');
  });

  it('accepts the same 25 MiB per-file ceiling as the server', async () => {
    await expect(
      validatedProjectFileSize({
        uri: 'file:///project.pdf',
        size: PROJECT_SUBMISSION_MAX_BYTES,
      }),
    ).resolves.toBe(PROJECT_SUBMISSION_MAX_BYTES);
    await expect(
      validatedProjectFileSize({
        uri: 'file:///too-large.pdf',
        size: PROJECT_SUBMISSION_MAX_BYTES + 1,
      }),
    ).rejects.toThrow('PROJECT_FILE_TOO_LARGE');
  });
});
