import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('expo-crypto', () => ({
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: jest.fn(),
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));

import {
  clearProjectSubmissionDraft,
  loadProjectSubmissionDraft,
  saveProjectSubmissionDraft,
} from '../src/services/projectSubmissionDraft';

describe('learner draft persistence', () => {
  beforeEach(async () => {
    jest.restoreAllMocks();
    await AsyncStorage.clear();
  });

  it('does not resurrect a project draft when a slow save finishes after clear starts', async () => {
    const originalSetItem = (
      AsyncStorage.setItem as jest.Mock
    ).getMockImplementation();
    expect(originalSetItem).toBeDefined();
    let releaseSlowWrite: (() => void) | undefined;
    const slowWrite = new Promise<void>(resolve => {
      releaseSlowWrite = resolve;
    });
    let delayed = false;

    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (key, value) => {
        if (key.includes('@rokn/project-editor-draft/v1') && !delayed) {
          delayed = true;
          await slowWrite;
        }
        await originalSetItem?.(key, value);
      });

    const save = saveProjectSubmissionDraft('77', {
      note: 'مسودة مهمة',
      updatedAt: Date.now(),
    });
    await Promise.resolve();
    const clear = clearProjectSubmissionDraft('77');
    releaseSlowWrite?.();

    await Promise.all([save, clear]);
    await expect(loadProjectSubmissionDraft('77')).resolves.toBeNull();
  });
});
