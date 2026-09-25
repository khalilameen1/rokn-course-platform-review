import {
  isLearnerDraftStorageKey,
  learnerDraftStorage,
} from '../src/services/learnerDraftStorage';

describe('file-owning storage contracts', () => {
  it.each(Object.entries(learnerDraftStorage))(
    'selects only %s records belonging to the requested account',
    (_name, source) => {
      const suffix =
        source.account === 'first' ? 'account-1:item' : 'item:account-1';
      const key = `${source.namespace}:${suffix}`;
      expect(isLearnerDraftStorageKey(key, 'account-1')).toBe(true);
      expect(isLearnerDraftStorageKey(key, 'account')).toBe(false);
      expect(isLearnerDraftStorageKey(key, 'account-11')).toBe(false);
      expect(isLearnerDraftStorageKey(`${key}:corrupt`, 'account-1')).toBe(
        false,
      );
      expect(
        isLearnerDraftStorageKey(
          `${source.namespace}-other:${suffix}`,
          'account-1',
        ),
      ).toBe(false);
    },
  );

  it.each([
    '@rokn/native-course-checkout/v1/coins.600:account-1',
    '@rokn/product-feedback-receipts/v1:account-1',
    '@rokn/client-events-outbox/v1:account-1',
    '@rokn/default-folder:account-1',
    '@rokn/project-editor-draft/v1:account-2:account-1',
    '@rokn/product-feedback-reply/v1:account-1:account-2',
  ])('excludes unrelated data at %s', key => {
    expect(isLearnerDraftStorageKey(key, 'account-1')).toBe(false);
  });
});
