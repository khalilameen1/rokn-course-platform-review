jest.mock('../src/constants/helpers', () => {
  const actual = jest.requireActual('../src/constants/helpers');
  return {
    ...actual,
    assertAccountSessionBoundary: jest.fn(),
  };
});

jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {learnerDraftFileIsReadable} from '../src/services/learnerDraftFiles';
import {
  clearPortfolioEditorDraft,
  readPortfolioEditorDraft,
  writePortfolioEditorDraft,
} from '../src/services/portfolioDraft';

describe('portfolio editor recovery', () => {
  const owner = {epoch: 7, scope: 'user-portfolio-owner'};

  beforeEach(async () => {
    await AsyncStorage.clear();
    jest.clearAllMocks();
  });

  it('restores the explicit portfolio upload after leaving the screen', async () => {
    const draft = {
      clientRequestId: '11111111-1111-4111-8111-111111111111',
      title: 'مشروع الكورس',
      summary: 'النسخة التي اخترت نشرها',
      media: [
        {
          uri: 'file:///portfolio/selected.jpg',
          type: 'image/jpeg',
          fileName: 'selected.jpg',
          size: 4200,
        },
      ],
      updatedAt: Date.now(),
    };

    await writePortfolioEditorDraft(draft, owner);
    await expect(readPortfolioEditorDraft(owner)).resolves.toEqual(draft);
    await clearPortfolioEditorDraft(owner);
    await expect(readPortfolioEditorDraft(owner)).resolves.toBeNull();
  });

  it('never reads or clears another account draft on the same device', async () => {
    const anotherOwner = {epoch: 8, scope: 'user-portfolio-owner-b'};
    const firstDraft = {
      clientRequestId: '11111111-1111-4111-8111-111111111111',
      title: 'مشروع الحساب الأول',
      summary: '',
      updatedAt: Date.now(),
    };
    const secondDraft = {
      clientRequestId: '22222222-2222-4222-8222-222222222222',
      title: 'مشروع الحساب الثاني',
      summary: '',
      updatedAt: Date.now(),
    };

    await writePortfolioEditorDraft(firstDraft, owner);
    await writePortfolioEditorDraft(secondDraft, anotherOwner);
    await clearPortfolioEditorDraft(owner);

    await expect(readPortfolioEditorDraft(owner)).resolves.toBeNull();
    await expect(readPortfolioEditorDraft(anotherOwner)).resolves.toMatchObject(
      secondDraft,
    );
  });

  it('does not destroy a draft when the filesystem is temporarily unavailable', async () => {
    const draft = {
      clientRequestId: '11111111-1111-4111-8111-111111111111',
      title: 'مشروع محفوظ',
      summary: '',
      media: [{uri: 'file:///portfolio/selected.jpg'}],
      updatedAt: Date.now(),
    };
    await writePortfolioEditorDraft(draft, owner);
    (learnerDraftFileIsReadable as jest.Mock).mockRejectedValueOnce(
      new Error('FILESYSTEM_BUSY'),
    );

    await expect(readPortfolioEditorDraft(owner)).rejects.toThrow(
      'FILESYSTEM_BUSY',
    );
    const key = (await AsyncStorage.getAllKeys()).find(item =>
      item.startsWith('@rokn/portfolio-editor-draft/v1:'),
    );
    expect(key).toBeDefined();
    expect(await AsyncStorage.getItem(key!)).toContain('مشروع محفوظ');
  });
});
