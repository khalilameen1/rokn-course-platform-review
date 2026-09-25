import {Dimensions, Platform} from 'react-native';
import appConfig from '../../../app.json';
import {firstBoolean} from '../api/common';
import {
  backendCategory,
  isFeedbackPublicId,
  isRecord,
  safeAccessToken,
  type FeedbackAttachment,
  type ProductFeedbackCategory,
  type ProductFeedbackContext,
  type ProductFeedbackArtifact,
  type ProductFeedbackMessage,
  type ProductFeedbackCase,
  type ProductFeedbackReceipt,
} from './contracts';

const normalizeScreenKey = (value?: string) => {
  const normalized = String(value || 'feedback')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64);
  return normalized || 'feedback';
};

const osMajor = () => {
  const value = Number.parseInt(String(Platform.Version).split('.')[0], 10);
  return Number.isInteger(value) && value > 0 && value <= 255
    ? value
    : undefined;
};

const buildNumber = () => {
  const value = Number(
    Platform.OS === 'ios'
      ? appConfig.expo.ios?.buildNumber
      : appConfig.expo.android?.versionCode,
  );
  return Number.isInteger(value) && value > 0 ? value : undefined;
};

type NativeUpload = {name: string; type: string; uri: string};

type NativeFormData = FormData & {
  append(name: string, value: string | NativeUpload): void;
};

export const createFeedbackBody = ({
  attachment,
  category,
  context,
  message,
  clientRequestId,
}: {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  context?: ProductFeedbackContext;
  message: string;
  clientRequestId: string;
}) => {
  const form = new FormData() as NativeFormData;
  form.append('client_request_id', clientRequestId);
  form.append('category', backendCategory[category]);
  form.append('message', message.trim());
  if (context?.includeDiagnostics) {
    const screen = Dimensions.get('window');
    form.append('platform', Platform.OS);
    form.append('app_version', appConfig.expo.version);
    form.append('screen_key', normalizeScreenKey(context.sourceScreen));
    form.append('locale', String(context.locale || 'ar').slice(0, 16));
    form.append(
      'screen_size',
      `${Math.round(screen.width)}x${Math.round(screen.height)}`,
    );
    form.append('font_scale', String(screen.fontScale || 1));
    const currentBuildNumber = buildNumber();
    if (currentBuildNumber) {
      form.append('build_number', String(currentBuildNumber));
    }
    const currentOsMajor = osMajor();
    if (currentOsMajor) {
      form.append('os_major', String(currentOsMajor));
    }
    form.append('device_tier', 'unknown');
    form.append('network_type', 'unknown');
  }
  if (attachment) {
    form.append('screenshot', {
      name: attachment.fileName || `rokn-feedback-${Date.now()}.jpg`,
      type: attachment.type || 'image/jpeg',
      uri: attachment.uri,
    });
  }
  return form;
};

export const createReplyBody = (input: {
  clientRequestId: string;
  message: string;
  attachment?: FeedbackAttachment;
}) => {
  const form = new FormData() as NativeFormData;
  form.append('client_request_id', input.clientRequestId);
  form.append('message', input.message.trim());
  if (input.attachment) {
    form.append('screenshot', {
      name: input.attachment.fileName || 'rokn-support.jpg',
      type: input.attachment.type || 'image/jpeg',
      uri: input.attachment.uri,
    });
  }
  return form;
};

const safeCaseNumber = (value: unknown, publicId: string) => {
  const candidate = String(value || '')
    .trim()
    .toUpperCase();
  return /^[0-9A-Z]{6,12}$/.test(candidate)
    ? candidate
    : publicId.slice(-8).toUpperCase();
};

const parseArtifacts = (value: unknown): ProductFeedbackArtifact[] =>
  (Array.isArray(value) ? value : [])
    .map((item): ProductFeedbackArtifact | null => {
      if (!isRecord(item)) return null;
      const id = String(item.id || '').trim();
      const name = String(item.name || '').trim();
      const mime = String(item.mime || '')
        .trim()
        .toLowerCase();
      const url = String(item.url || '').trim();
      const expiresAt = String(item.expires_at || '').trim();
      if (
        !/^\d+$/.test(id) ||
        !name ||
        !mime.startsWith('image/') ||
        !/^https:\/\//i.test(url) ||
        !Number.isFinite(Date.parse(expiresAt))
      )
        return null;
      const width = Number(item.width);
      const height = Number(item.height);
      return {
        expiresAt,
        height: Number.isFinite(height) && height > 0 ? height : undefined,
        id,
        mime,
        name,
        size: Math.max(0, Number(item.size) || 0),
        url,
        width: Number.isFinite(width) && width > 0 ? width : undefined,
      };
    })
    .filter((item): item is ProductFeedbackArtifact => item !== null);

const parseMessages = (value: unknown): ProductFeedbackMessage[] =>
  (Array.isArray(value) ? value : [])
    .map(item => {
      if (!isRecord(item)) return null;
      const publicId = String(item.public_id || '').trim();
      const text = String(item.text || '').trim();
      const createdAt = String(item.created_at || '').trim();
      if (!publicId || !text || !Number.isFinite(Date.parse(createdAt)))
        return null;
      return {
        attachments: parseArtifacts(item.attachments),
        author:
          item.author === 'learner'
            ? ('learner' as const)
            : ('support' as const),
        createdAt,
        hasAttachment: firstBoolean(item.has_attachment) ?? false,
        publicId,
        text,
      };
    })
    .filter((item): item is ProductFeedbackMessage => item !== null);

export const parseCase = (value: unknown): ProductFeedbackCase => {
  if (!isRecord(value)) throw new Error('INVALID_SUPPORT_CASE');
  const publicId = String(value.public_id || '').trim();
  const createdAt = String(value.created_at || '').trim();
  const updatedAt = String(value.updated_at || '').trim();
  const message = String(value.message || '').trim();
  if (
    !isFeedbackPublicId(publicId) ||
    !message ||
    !Number.isFinite(Date.parse(createdAt)) ||
    !Number.isFinite(Date.parse(updatedAt))
  ) {
    throw new Error('INVALID_SUPPORT_CASE');
  }
  return {
    attachments: parseArtifacts(value.attachments),
    caseNumber: safeCaseNumber(value.case_number, publicId),
    category: String(value.category || 'bug'),
    createdAt,
    message,
    messages: parseMessages(value.messages),
    publicId,
    status: String(value.status || 'in_progress'),
    updatedAt,
  };
};

export const parseReceipt = (
  payload: Record<string, unknown>,
): ProductFeedbackReceipt => {
  const publicId = String(payload.public_id || '').trim();
  const createdAt = String(payload.created_at || '').trim();
  if (
    !isFeedbackPublicId(publicId) ||
    !createdAt ||
    !Number.isFinite(Date.parse(createdAt))
  ) {
    throw new Error('INVALID_FEEDBACK_RECEIPT');
  }
  const receipt = {
    accessToken: safeAccessToken(payload.access_token),
    attachments: parseArtifacts(payload.attachments),
    caseNumber: safeCaseNumber(payload.case_number, publicId),
    publicId,
    status: String(payload.status || 'new'),
    createdAt,
    replayed: firstBoolean(payload.replayed) ?? false,
    messages: parseMessages(payload.messages),
  };
  return receipt;
};
