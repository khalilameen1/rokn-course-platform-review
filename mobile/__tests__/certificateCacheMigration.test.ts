const mockGetItem = jest.fn();

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, boundary: {scope: string}) =>
    `${key}:${boundary.scope}`,
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'account-a',
  })),
  getItem: (...args: unknown[]) => mockGetItem(...args),
  saveItem: jest.fn(async () => true),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {getCachedCertificates} from '../src/services/api/certificates';

const legacyCertificate = {
  publicId: '11111111-1111-4111-8111-111111111111',
  courseId: '52',
  verificationUrl: 'https://rokn.app/c/11111111-1111-4111-8111-111111111111',
  certificateUrl:
    'https://rokn.app/c/11111111-1111-4111-8111-111111111111/artifact',
  certificatePdfUrl:
    'https://rokn.app/c/11111111-1111-4111-8111-111111111111/download',
  holderName: 'طالب ركن',
  courseName: 'كورس قديم',
  status: 'active',
  verificationLevel: 'completion',
  verificationLabel: 'إتمام الكورس',
  certificateTextTemplateKey: 'applied',
  certificateText: 'تقديرًا لإتمام الدراسة والتطبيق في كورس',
};

describe('certificate cache QR migration', () => {
  it('keeps a valid legacy certificate offline while hiding its unknown QR', async () => {
    mockGetItem.mockResolvedValue({
      version: 2,
      certificates: [legacyCertificate],
    });

    await expect(
      getCachedCertificates({epoch: 1, scope: 'account-a'}),
    ).resolves.toEqual([
      expect.objectContaining({
        qrDestination: null,
      }),
    ]);
  });

  it('does not hide a malformed current destination behind the legacy fallback', async () => {
    mockGetItem.mockResolvedValue({
      version: 2,
      certificates: [
        {...legacyCertificate, qrDestination: {type: 'portfolio', url: ''}},
      ],
    });

    await expect(
      getCachedCertificates({epoch: 1, scope: 'account-a'}),
    ).resolves.toEqual([]);
  });
});
