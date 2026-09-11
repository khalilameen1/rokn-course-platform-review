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
jest.mock('../src/utils/serverClock', () => ({
  serverNowMs: () => 1_000_000,
  isServerTimestampFresh: (saved: number, ttl: number) =>
    saved <= 1_000_000 && 1_000_000 - saved <= ttl,
}));

import {
  bundledPublicContent,
  getPublicContent,
} from '../src/services/publicContent';

describe('shared public documents', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    mockRead.mockResolvedValue(null);
    mockSave.mockResolvedValue(true);
  });

  it('uses application content from the API instead of an older screen constant', async () => {
    const content = {
      ...bundledPublicContent('privacy'),
      intro_text: 'نص السيرفر الحالي',
    };
    mockGet.mockResolvedValue({data: {data: {source: 'application', content}}});
    expect(await getPublicContent('privacy')).toMatchObject(content);
    expect(mockGet).toHaveBeenCalledWith('content/pages/privacy', {
      skipAuthorization: true,
      headers: {'Accept-Language': 'ar'},
    });
    expect(mockSave.mock.calls[0][0]).toContain('/v3/ar/privacy');
  });

  it('shows a dashboard document without mixing in a stale intro or date', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          source: 'dashboard',
          title: 'سياسة الخصوصية',
          managed_body: '<p>نص منشور</p><p>فقرة أخرى</p>',
        },
      },
    });
    expect(await getPublicContent('privacy')).toMatchObject({
      intro_text: '',
      last_updated: '',
      closing: '',
      sections: [{title: '', body: ['نص منشور', 'فقرة أخرى']}],
    });
  });

  it('replaces a cached dashboard body when the server returns to application content', async () => {
    mockRead.mockResolvedValue({
      document: {...bundledPublicContent('terms'), intro_text: 'نص قديم'},
      savedAt: 900_000,
    });
    mockGet.mockResolvedValue({
      data: {
        data: {source: 'application', content: bundledPublicContent('terms')},
      },
    });
    expect(await getPublicContent('terms')).toMatchObject(
      bundledPublicContent('terms'),
    );
    expect(mockSave).toHaveBeenCalledTimes(1);
  });

  it('keeps a fresh cached document without fetching again', async () => {
    mockRead.mockResolvedValue({
      document: bundledPublicContent('about'),
      savedAt: 990_000,
    });
    expect(await getPublicContent('about')).toMatchObject(
      bundledPublicContent('about'),
    );
    expect(mockGet).not.toHaveBeenCalled();
  });

  it('keeps usable recent content offline but never an expired or malformed cache', async () => {
    mockGet.mockRejectedValue(new Error('offline'));
    mockRead.mockResolvedValue({
      document: {...bundledPublicContent('privacy'), intro_text: 'منشور حديث'},
      savedAt: 900_000,
    });
    expect((await getPublicContent('privacy')).intro_text).toBe('منشور حديث');
    mockRead.mockResolvedValue({
      document: {...bundledPublicContent('privacy'), intro_text: 'منتهي'},
      savedAt: -100_000_000,
    });
    expect(await getPublicContent('privacy')).toEqual(
      bundledPublicContent('privacy'),
    );
    mockRead.mockResolvedValue({
      document: {title: 'بيانات تالفة'},
      savedAt: 900_000,
    });
    expect(await getPublicContent('privacy')).toEqual(
      bundledPublicContent('privacy'),
    );
  });

  it('still returns the server document if local storage fails', async () => {
    mockRead.mockRejectedValue(new Error('storage'));
    mockSave.mockRejectedValue(new Error('storage'));
    const content = {...bundledPublicContent('returns'), intro_text: 'نص جديد'};
    mockGet.mockResolvedValue({data: {data: {source: 'application', content}}});
    expect(await getPublicContent('returns')).toMatchObject(content);
  });
});
