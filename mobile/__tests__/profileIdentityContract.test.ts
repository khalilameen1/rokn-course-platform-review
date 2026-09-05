const mockPost = jest.fn();

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-seven',
  })),
}));

import {updateProfile} from '../src/services/api/accountProfile';

const formFieldNames = (body: unknown): string[] => {
  const candidate = body as {
    _parts?: Array<[string, unknown]>;
    entries?: () => IterableIterator<[string, unknown]>;
    getParts?: () => Array<{fieldName?: string}>;
  };
  if (candidate._parts) return candidate._parts.map(([name]) => name);
  if (candidate.getParts) {
    return candidate
      .getParts()
      .map(part => String(part.fieldName || ''))
      .filter(Boolean);
  }
  if (candidate.entries) {
    return [...candidate.entries()].map(([name]) => name);
  }
  return [];
};

const response = (profileRevision: number) => ({
  data: {
    data: {
      email: 'learner@example.test',
      id: 7,
      name: 'الاسم الجديد',
      profile_image: 'https://cdn.example.test/new.jpg',
      profile_revision: profileRevision,
    },
  },
});

describe('profile identity write contract', () => {
  beforeEach(() => jest.clearAllMocks());

  it('requires the server revision to advance before the app adopts the identity', async () => {
    mockPost.mockResolvedValue(response(2));

    await expect(
      updateProfile({
        clientRequestId: '11111111-1111-4111-8111-111111111111',
        expectedProfileRevision: 2,
        jobTitle: '',
        name: 'الاسم الجديد',
      }),
    ).rejects.toThrow('PROFILE_REVISION_NOT_ADVANCED');
  });

  it('returns the authoritative identity after an advanced revision', async () => {
    mockPost.mockResolvedValue(response(3));

    await expect(
      updateProfile({
        clientRequestId: '11111111-1111-4111-8111-111111111111',
        expectedProfileRevision: 2,
        jobTitle: '',
        name: 'الاسم الجديد',
      }),
    ).resolves.toEqual(
      expect.objectContaining({
        avatar: 'https://cdn.example.test/new.jpg',
        name: 'الاسم الجديد',
        profileRevision: 3,
      }),
    );
  });

  it('does not overwrite the account job title when saving the public headline', async () => {
    mockPost.mockResolvedValue({
      data: {
        data: {
          ...response(3).data.data,
          job_title: 'قيمة حساب قديمة',
          portfolio_headline: 'مصمم منتجات رقمية',
        },
      },
    });

    await updateProfile({
      clientRequestId: '11111111-1111-4111-8111-111111111111',
      expectedProfileRevision: 2,
      name: 'الاسم الجديد',
      portfolioHeadline: 'مصمم منتجات رقمية',
    });

    const requestBody = mockPost.mock.calls[0][1];
    expect(formFieldNames(requestBody)).toContain('portfolio_headline');
    expect(formFieldNames(requestBody)).not.toContain('job_title');
  });
});
