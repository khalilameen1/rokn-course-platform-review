export type ProductFeedbackCategory =
  | 'problem'
  | 'idea'
  | 'content'
  | 'playback';

export type FeedbackAttachment = {
  fileName?: string;
  size?: number;
  type?: string;
  uri: string;
};

export type ProductFeedbackContext = {
  includeDiagnostics?: boolean;
  locale?: string;
  sourceScreen?: string;
};

export type ProductFeedbackDraft = {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  clientRequestId: string;
  includeDiagnostics: boolean;
  message: string;
  sourceScreen?: string;
  updatedAt: number;
};

export type ProductFeedbackReceipt = {
  accessToken?: string;
  attachments: ProductFeedbackArtifact[];
  caseNumber: string;
  createdAt: string;
  messages: ProductFeedbackMessage[];
  publicId: string;
  replayed: boolean;
  status: string;
};

export type ProductFeedbackMessage = {
  attachments: ProductFeedbackArtifact[];
  author: 'learner' | 'support';
  createdAt: string;
  hasAttachment: boolean;
  publicId: string;
  text: string;
};

export type ProductFeedbackArtifact = {
  expiresAt: string;
  height?: number;
  id: string;
  mime: string;
  name: string;
  size: number;
  url: string;
  width?: number;
};

export type ProductFeedbackReplyDraft = {
  attachment?: FeedbackAttachment;
  clientRequestId: string;
  message: string;
};

export type ProductFeedbackCase = Omit<ProductFeedbackReceipt, 'replayed'> & {
  category: string;
  message: string;
  updatedAt: string;
};

export type ProductFeedbackDraftConflict = {
  id: string;
  publicId?: string;
  type: 'new' | 'reply';
};

const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export const backendCategory: Record<ProductFeedbackCategory, string> = {
  problem: 'bug',
  idea: 'suggestion',
  content: 'course_content',
  playback: 'playback',
};

export const isUuid = (value: unknown) =>
  UUID_PATTERN.test(String(value || ''));

export const isFeedbackPublicId = (value: string): boolean =>
  /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(value);

export const isProductFeedbackCategory = (
  value: unknown,
): value is ProductFeedbackCategory =>
  Object.prototype.hasOwnProperty.call(backendCategory, String(value));

export const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

export const safeAccessToken = (value: unknown) => {
  const token = String(value || '').trim();
  return /^[A-Za-z0-9_-]{32,128}$/.test(token) ? token : undefined;
};
