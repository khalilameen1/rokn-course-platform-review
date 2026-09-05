import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {cleanUnicodeText} from '../../utils/unicodeText';
import {formatAuthoredDisplayText} from '../../constants/arabicFormatting';
import {firstBoolean, payload} from './common';

export type EngagementMessageKey =
  | 'guest_registration_prompt'
  | 'welcome_bonus_received'
  | 'coin_offer';

export type EngagementMessage = {
  id: string;
  key: EngagementMessageKey;
  title: string;
  description: string;
  actionLabel: string;
  secondaryActionLabel: string;
  imageUrl?: string;
  link?: string;
  coins: number;
  dismissible: boolean;
  cooldownHours: number;
  version: string;
  campaignKey?: string;
  taskId?: string;
};

type EngagementMessageDto = {
  id?: unknown;
  key?: unknown;
  title_ar?: unknown;
  description_ar?: unknown;
  action_label_ar?: unknown;
  secondary_action_label_ar?: unknown;
  image_url?: unknown;
  link?: unknown;
  coins?: unknown;
  dismissible?: unknown;
  cooldown_hours?: unknown;
  version?: unknown;
  campaign_key?: unknown;
  task_id?: unknown;
};

const rawText = (value: unknown): string =>
  typeof value === 'string' ? value.trim() : '';

const copyText = (value: unknown): string =>
  typeof value === 'string'
    ? formatAuthoredDisplayText(cleanUnicodeText(value).slice(0, 240))
    : '';

const imageUrl = (value: unknown) => {
  const url = rawText(value);
  return /^https:\/\//i.test(url) ? url : undefined;
};

const mapEngagementMessage = (
  item: EngagementMessageDto | null,
  expectedKey: EngagementMessageKey,
): EngagementMessage | null => {
  const id = rawText(item?.id);
  const title = copyText(item?.title_ar);
  const description = copyText(item?.description_ar);
  const actionLabel = copyText(item?.action_label_ar);
  const secondaryActionLabel = copyText(item?.secondary_action_label_ar);
  const dismissible = firstBoolean(item?.dismissible) ?? true;
  if (
    !item ||
    rawText(item.key) !== expectedKey ||
    !id ||
    !title ||
    !description ||
    !actionLabel ||
    (dismissible && !secondaryActionLabel)
  ) {
    return null;
  }
  return {
    id,
    key: expectedKey,
    title,
    description,
    actionLabel,
    secondaryActionLabel,
    imageUrl: imageUrl(item.image_url),
    link: rawText(item.link) || undefined,
    coins: Math.max(0, Number(item.coins || 0) || 0),
    dismissible,
    cooldownHours: Math.max(0, Number(item.cooldown_hours || 0) || 0),
    version: rawText(item.version) || id,
    campaignKey: rawText(item.campaign_key) || undefined,
    taskId: rawText(item.task_id) || undefined,
  };
};

export const getEngagementMessage = async (
  key: EngagementMessageKey,
  ownerBoundary?: AccountSessionBoundary,
): Promise<EngagementMessage | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.get(`engagement/messages/${key}`);
  assertAccountSessionBoundary(boundary);
  const item = payload<EngagementMessageDto | null>(response);
  const message = mapEngagementMessage(item, key);
  assertAccountSessionBoundary(boundary);
  return message;
};

export const getNextEngagementMessage = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<EngagementMessage | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.get('engagement/next');
  assertAccountSessionBoundary(boundary);
  const item = payload<EngagementMessageDto | null>(response);
  const message = mapEngagementMessage(item, 'coin_offer');
  // A template by itself is not an eligible task. Home only presents the
  // personalized candidate whose one-time identity the backend selected.
  const eligible = message?.taskId && message.campaignKey ? message : null;
  assertAccountSessionBoundary(boundary);
  return eligible;
};
