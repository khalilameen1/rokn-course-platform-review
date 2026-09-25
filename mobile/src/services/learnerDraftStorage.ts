/**
 * Durable records that can own managed learner attachments. Producers and file
 * cleanup share these namespaces so adding a file-owning record also declares
 * its storage contract. Unrelated account values are not draft JSON.
 * Namespace strings and account positions are persisted contracts; do not
 * change them without migrating existing records.
 */
export const learnerDraftStorage = {
  feedbackDraft: {
    namespace: '@rokn/product-feedback-draft/v1',
    account: 'last',
  },
  feedbackReply: {
    namespace: '@rokn/product-feedback-reply/v1',
    account: 'last',
  },
  feedbackConflicts: {
    namespace: '@rokn/product-feedback-draft-conflicts/v1',
    account: 'last',
  },
  portfolioEditor: {
    namespace: '@rokn/portfolio-editor-draft/v1',
    account: 'last',
  },
  portfolioMedia: {
    namespace: '@rokn/portfolio-media-outbox/v1',
    account: 'last',
  },
  projectEditor: {namespace: '@rokn/project-editor-draft/v1', account: 'first'},
  projectFeedback: {
    namespace: '@rokn/project-feedback-draft/v1',
    account: 'first',
  },
  projectSubmission: {
    namespace: '@rokn/project-submission/v2',
    account: 'first',
  },
  courseChat: {namespace: '@rokn/course-chat-history/v2', account: 'first'},
} as const;

export const isLearnerDraftStorageKey = (
  key: string,
  accountScope: string,
): boolean => {
  // Quarantined records cannot be restored through the normal draft owners.
  if (key.endsWith(':corrupt')) return false;
  return Object.values(learnerDraftStorage).some(source => {
    const prefix = `${source.namespace}:`;
    if (!key.startsWith(prefix)) return false;
    const segments = key.slice(prefix.length).split(':');
    return (
      (source.account === 'first'
        ? segments[0]
        : segments[segments.length - 1]) === accountScope
    );
  });
};
