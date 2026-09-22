const mockGet = jest.fn();
const mockRead = jest.fn();
const mockSave = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  getItem: (...args: unknown[]) => mockRead(...args),
  saveItem: (...args: unknown[]) => mockSave(...args),
}));

const readFresh = () => {
  let read!: typeof import('../src/services/publicAppSettings').getPublicAppSettings;
  jest.isolateModules(() => {
    read = require('../src/services/publicAppSettings').getPublicAppSettings;
  });
  return read;
};
describe('Public artwork settings', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockRead.mockResolvedValue(null);
    mockSave.mockResolvedValue(true);
  });
  it('normalizes known artwork URLs and ignores unsafe or unknown fields', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          revision: 'new',
          artwork: {
            coin: 'https://rokn.test/coin.png',
            // eslint-disable-next-line no-script-url -- adversarial URL fixture
            coin_stack: 'javascript:alert(1)',
            badge_junior: 'https://user:secret@rokn.test/a.png',
            badge_mid: 'https://rokn.test/mid.png',
            unknown: 'https://rokn.test/extra.png',
          },
        },
      },
    });
    const result = await readFresh()();
    expect(result.artwork).toEqual({
      coin: 'https://rokn.test/coin.png',
      coin_stack: undefined,
      badge_junior: undefined,
      badge_mid: 'https://rokn.test/mid.png',
      badge_senior: undefined,
    });
    expect(mockSave).toHaveBeenCalledWith(
      '@rokn/public-app-settings/v3/ar',
      expect.objectContaining({settings: result}),
    );
  });
  it('can use a recent cached dashboard image while offline', async () => {
    mockRead.mockResolvedValue({
      savedAt: Date.now() - 120000,
      settings: {artwork: {coin: 'https://rokn.test/cached.png'}},
    });
    mockGet.mockRejectedValue(new Error('offline'));
    expect((await readFresh()()).artwork?.coin).toBe(
      'https://rokn.test/cached.png',
    );
  });
});
