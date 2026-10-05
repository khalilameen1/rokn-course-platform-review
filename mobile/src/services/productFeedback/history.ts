import type {ProductFeedbackCase} from './contracts';

/** An incomplete read is usable data, but never an authoritative replacement. */
export class ProductFeedbackHistoryIncompleteError extends Error {
  readonly cases: ProductFeedbackCase[];
  readonly reason: unknown;

  constructor(cases: ProductFeedbackCase[], reason: unknown) {
    super('SUPPORT_HISTORY_INCOMPLETE');
    this.name = 'ProductFeedbackHistoryIncompleteError';
    this.cases = cases;
    this.reason = reason;
  }
}

export const mergeProductFeedbackHistory = (
  previous: ProductFeedbackCase[],
  received: ProductFeedbackCase[],
) => {
  const cases = new Map(previous.map(item => [item.publicId, item]));
  received.forEach(item => cases.set(item.publicId, item));
  return [...cases.values()].sort(
    (a, b) =>
      b.updatedAt.localeCompare(a.updatedAt) ||
      b.publicId.localeCompare(a.publicId),
  );
};
