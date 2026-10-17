import {usablePortfolioMediaUrl} from '../portfolioMediaPolicy';
import {
  type ApiRecord,
  firstBoolean,
  isApiRecord,
  isResourceListPayload,
  resourceList,
} from './common';

export type PortfolioMediaDto = ApiRecord & {
  file_type?: unknown;
  image_url?: unknown;
  playback_url?: unknown;
  url_expires_at?: unknown;
};
export type PortfolioCourseDto = {
  name?: unknown;
  id?: unknown;
  image?: unknown;
};
export type PortfolioItemDto = ApiRecord & {
  media?: unknown;
  course?: PortfolioCourseDto;
};
export type EligibleProjectDto = ApiRecord & {
  course?: {id?: unknown; title?: unknown; title_en?: unknown; image?: unknown};
  module?: {title?: unknown};
};

export type PortfolioLink = {label: string; url: string};

export type PortfolioProfile = {
  slug: string;
  headline: string;
  location: string;
  skills: string[];
  links: PortfolioLink[];
  /** Live public link; present whenever an approved version is published. */
  publicUrl: string;
  published: boolean;
  publishedAt: string;
  /** Newer edits wait for review while the published version stays live. */
  hasUnpublishedChanges: boolean;
  shareMode: 'unlisted';
  sharingSuspended?: boolean;
  sharingStatus:
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'suspended'
    | 'unavailable';
  sharingRevision: number;
  sharingRejectionReason: string;
};

export type PortfolioItem = {
  id: string;
  /** Public project page id: `${publicUrl}/p/${publicId}`. */
  publicId?: string;
  title: string;
  summary: string;
  coverUri?: string;
  coverMediaId?: string;
  role?: string;
  /** Project tools (the backend also mirrors them as `skills`). */
  tools: string[];
  externalUrl?: string;
  /** Owner hid it from the public link without deleting it. */
  hidden?: boolean;
  sortOrder?: number;
  courseName?: string;
  courseId?: string;
  courseImage?: string;
  sourceProjectId?: string;
  featured: boolean;
  media: PortfolioMedia[];
  publicationState: 'draft' | 'uploading' | 'published' | 'deleting';
  uploadedMediaCount: number;
  expectedMediaCount: number;
};

export type PortfolioMedia = {
  id: string;
  type: 'image' | 'video';
  uri?: string;
  status: 'ready' | 'processing' | 'failed';
  caption?: string;
  width?: number;
  height?: number;
  durationSeconds?: number;
  urlExpiresAt?: string;
};

export type PortfolioUpload = {
  uri: string;
  type?: string;
  fileName?: string;
  size?: number;
};

export type EligibleProject = {
  projectId: string;
  courseId: string;
  title: string;
  summary: string;
  courseName: string;
  courseImage?: string;
  moduleName?: string;
  passedAt?: string;
};

const stringList = (value: unknown): string[] =>
  Array.isArray(value)
    ? value.map(entry => String(entry ?? '').trim()).filter(Boolean)
    : [];

export const mapPortfolioProfile = (
  value: unknown,
  fallback: Partial<Pick<PortfolioProfile, 'headline' | 'slug'>> = {},
): PortfolioProfile => {
  if (!isApiRecord(value)) {
    throw new Error('PORTFOLIO_PROFILE_CONTRACT_INVALID');
  }
  const sharingSuspended = firstBoolean(value.sharing_suspended) === true;
  const sharingStatus: PortfolioProfile['sharingStatus'] = sharingSuspended
    ? 'suspended'
    : ['pending', 'approved', 'rejected', 'suspended'].includes(
        String(value.sharing_status),
      )
    ? (value.sharing_status as PortfolioProfile['sharingStatus'])
    : 'unavailable';
  const publicUrl =
    typeof value.public_url === 'string' ? value.public_url.trim() : '';
  // Publication, not the latest review decision, controls the live link:
  // an approved version stays public while newer edits wait for review.
  const published =
    sharingStatus !== 'suspended' &&
    (firstBoolean(value.published) === true ||
      (value.published === undefined && sharingStatus === 'approved')) &&
    Boolean(publicUrl);
  return {
    slug: String(value.slug || fallback.slug || ''),
    headline: String(value.headline || fallback.headline || ''),
    location: String(value.location || ''),
    skills: stringList(value.skills),
    links: Array.isArray(value.links)
      ? value.links.flatMap(link =>
          isApiRecord(link) &&
          typeof link.url === 'string' &&
          /^https:\/\//i.test(link.url.trim())
            ? [{label: String(link.label || '').trim(), url: link.url.trim()}]
            : [],
        )
      : [],
    publicUrl: published ? publicUrl : '',
    published,
    publishedAt:
      published && typeof value.published_at === 'string'
        ? value.published_at
        : '',
    hasUnpublishedChanges:
      published && firstBoolean(value.has_unpublished_changes) === true,
    shareMode: 'unlisted',
    sharingSuspended: sharingStatus === 'suspended',
    sharingStatus,
    sharingRevision: Math.max(0, Number(value.sharing_revision) || 0),
    sharingRejectionReason:
      sharingStatus === 'rejected' &&
      typeof value.sharing_rejection_reason === 'string'
        ? value.sharing_rejection_reason.trim()
        : '',
  };
};

export const mapPortfolioMedia = (value: unknown): PortfolioMedia[] => {
  if (!isResourceListPayload(value)) {
    throw new Error('PORTFOLIO_MEDIA_CONTRACT_INVALID');
  }
  const items = resourceList<PortfolioMediaDto>(value);
  if (
    items.some(
      item =>
        !isApiRecord(item) ||
        item.id === null ||
        item.id === undefined ||
        !/^\d+$/.test(String(item.id).trim()) ||
        !['image', 'video'].includes(String(item.file_type).toLowerCase()) ||
        !['ready', 'processing', 'failed'].includes(
          String(item.status).toLowerCase(),
        ),
    )
  ) {
    throw new Error('PORTFOLIO_MEDIA_CONTRACT_INVALID');
  }
  return items.map(item => {
    const type =
      String(item.file_type).toLowerCase() === 'video' ? 'video' : 'image';
    const status = String(
      item.status,
    ).toLowerCase() as PortfolioMedia['status'];
    const width = Number(item.width);
    const height = Number(item.height);
    const durationSeconds = Number(item.duration_seconds);
    const expiresAt = item.url_expires_at
      ? String(item.url_expires_at)
      : undefined;
    return {
      id: String(item.id),
      type,
      uri:
        type === 'video'
          ? usablePortfolioMediaUrl(item.playback_url, expiresAt)
          : usablePortfolioMediaUrl(item.image_url, expiresAt),
      status,
      caption: item.caption ? String(item.caption) : undefined,
      width: Number.isFinite(width) && width > 0 ? width : undefined,
      height: Number.isFinite(height) && height > 0 ? height : undefined,
      durationSeconds:
        Number.isFinite(durationSeconds) && durationSeconds >= 0
          ? durationSeconds
          : undefined,
      urlExpiresAt: expiresAt,
    };
  });
};

/** The owner's chosen cover image, else the first deliverable image. */
export const portfolioCoverMedia = (
  media: PortfolioMedia[],
  coverMediaId?: string,
) =>
  (coverMediaId
    ? media.find(
        entry =>
          entry.id === coverMediaId && entry.type === 'image' && entry.uri,
      )
    : undefined) ?? media.find(entry => entry.type === 'image' && entry.uri);

export const mapPortfolioItem = (
  item: PortfolioItemDto,
  fallback?: Partial<PortfolioItem>,
): PortfolioItem => {
  const media = mapPortfolioMedia(item.media);
  const coverMediaId =
    item.cover_media_id !== null && item.cover_media_id !== undefined
      ? String(item.cover_media_id)
      : undefined;
  const cover = portfolioCoverMedia(media, coverMediaId);
  const course = item.course as PortfolioCourseDto | undefined;
  const uploadState = String(item.upload_state);
  const publicId =
    typeof item.public_id === 'string' && item.public_id.trim()
      ? item.public_id.trim().toLowerCase()
      : fallback?.publicId;
  return {
    id: String(item.id ?? fallback?.id ?? ''),
    publicId,
    title: String(item.title || fallback?.title || 'مشروع بدون عنوان'),
    summary: String(item.description ?? fallback?.summary ?? ''),
    coverUri: cover?.uri ?? fallback?.coverUri,
    coverMediaId,
    role: typeof item.role === 'string' ? item.role : fallback?.role ?? '',
    // `tools` is the stored field; `skills` is the server's alias of it.
    tools: Array.isArray(item.tools)
      ? stringList(item.tools)
      : Array.isArray(item.skills)
      ? stringList(item.skills)
      : fallback?.tools ?? [],
    externalUrl:
      typeof item.external_url === 'string'
        ? item.external_url
        : fallback?.externalUrl ?? '',
    hidden: firstBoolean(item.is_hidden) ?? fallback?.hidden ?? false,
    sortOrder: Math.max(0, Number(item.sort_order) || 0),
    courseName: course?.name ? String(course.name) : fallback?.courseName,
    courseId: course?.id ? String(course.id) : fallback?.courseId,
    courseImage: course?.image ? String(course.image) : fallback?.courseImage,
    sourceProjectId: item.source_project_id
      ? String(item.source_project_id)
      : fallback?.sourceProjectId,
    featured: firstBoolean(item.is_featured) ?? fallback?.featured ?? false,
    media: media.length ? media : fallback?.media ?? [],
    publicationState:
      uploadState === 'ready'
        ? 'published'
        : ['draft', 'uploading', 'deleting'].includes(uploadState)
        ? (uploadState as Exclude<
            PortfolioItem['publicationState'],
            'published'
          >)
        : firstBoolean(item.is_public)
        ? 'published'
        : 'draft',
    uploadedMediaCount: Math.max(
      0,
      Number(item.uploaded_media_count) || media.length,
    ),
    expectedMediaCount: Math.max(0, Number(item.expected_media_count) || 0),
  };
};

export const mapPortfolioMutation = (
  value: unknown,
  expectedId?: string,
): PortfolioItem => {
  if (!isApiRecord(value)) {
    throw new Error('PORTFOLIO_ITEM_CONTRACT_INVALID');
  }
  const id = String(value.id ?? '').trim();
  if (!id || (expectedId !== undefined && id !== String(expectedId).trim())) {
    throw new Error('PORTFOLIO_ITEM_CONTRACT_INVALID');
  }
  if (
    typeof value.title !== 'string' ||
    !Object.prototype.hasOwnProperty.call(value, 'description') ||
    !isResourceListPayload(value.media) ||
    !['draft', 'uploading', 'ready', 'deleting'].includes(
      String(value.upload_state),
    ) ||
    !Number.isFinite(Number(value.uploaded_media_count)) ||
    !Number.isFinite(Number(value.expected_media_count))
  ) {
    throw new Error('PORTFOLIO_ITEM_CONTRACT_INVALID');
  }
  return mapPortfolioItem(value as PortfolioItemDto);
};

export const isValidPortfolioList = (
  items: unknown[],
): items is PortfolioItemDto[] => {
  const ids = new Set<string>();
  return items.every(item => {
    if (!isApiRecord(item)) return false;
    const id = String(item.id ?? '').trim();
    if (!/^\d+$/.test(id) || ids.has(id)) return false;
    try {
      mapPortfolioMutation(item, id);
      ids.add(id);
      return true;
    } catch {
      return false;
    }
  });
};

// Limits mirror PortfolioController (tools) and PortfolioProfileController.
export const PORTFOLIO_TOOLS_MAX = 20;
export const PORTFOLIO_SKILLS_MAX = 30;
export const PORTFOLIO_CHIP_MAX_LENGTH = 80;
export const PORTFOLIO_LINKS_MAX = 10;
export const PORTFOLIO_LINK_LABEL_MAX_LENGTH = 40;

const latinDigits = (value: string) =>
  value
    .replace(/[٠-٩]/g, digit =>
      String(digit.charCodeAt(0) - 0x0660),
    )
    .replace(/[۰-۹]/g, digit =>
      String(digit.charCodeAt(0) - 0x06f0),
    );

/** Trimmed, de-duplicated chips within the server's count and length limits. */
export const normalizePortfolioChips = (
  values: string[],
  maximum: number,
): string[] => {
  const seen = new Set<string>();
  const chips: string[] = [];
  for (const value of values) {
    const chip = String(value ?? '')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, PORTFOLIO_CHIP_MAX_LENGTH);
    const key = chip.toLocaleLowerCase();
    if (!chip || seen.has(key)) continue;
    seen.add(key);
    chips.push(chip);
    if (chips.length >= maximum) break;
  }
  return chips;
};

/**
 * WhatsApp's documented click-to-chat link is https://wa.me/<international
 * number without + or 0>. Egyptian mobiles written locally (01xxxxxxxxx)
 * become 201xxxxxxxxx; +20 / 0020 forms are accepted too.
 */
export const whatsAppLinkFromPhone = (input: string): string | null => {
  let digits = latinDigits(String(input || '')).replace(/[\s\-().]/g, '');
  if (digits.startsWith('+')) digits = digits.slice(1);
  else if (digits.startsWith('00')) digits = digits.slice(2);
  else if (/^01[0125]\d{8}$/.test(digits)) digits = `20${digits.slice(1)}`;
  return /^[1-9]\d{7,14}$/.test(digits) ? `https://wa.me/${digits}` : null;
};

/** Client-side mirror of the profile link rules: https only, <= 10 links. */
export const portfolioLinkError = (link: PortfolioLink): string => {
  const url = link.url.trim();
  if (!link.label.trim()) return 'اكتب اسم للرابط';
  if (link.label.trim().length > PORTFOLIO_LINK_LABEL_MAX_LENGTH)
    return 'اسم الرابط طويل';
  if (!/^https:\/\/[^\s/]+\.[^\s]+$/i.test(url) || url.length > 2000)
    return 'الرابط لازم يبدأ بـ https://';
  if (/^https:\/\/(?:www\.)?(?:wa\.me|wa\.link|(?:[a-z]+\.)?whatsapp\.com)\b/i.test(url)) {
    const waMe = url.match(/^https:\/\/(?:www\.)?wa\.me\/(\d+)\/?$/i);
    const send = url.match(/[?&]phone=\+?(\d+)/i);
    const digits = waMe?.[1] ?? send?.[1];
    if (!digits || !/^[1-9]\d{7,14}$/.test(digits))
      return 'اكتب رقم واتساب صحيح';
  }
  return '';
};
