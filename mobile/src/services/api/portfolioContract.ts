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

export type PortfolioProfile = {
  slug: string;
  headline: string;
  location: string;
  skills: string[];
  publicUrl: string;
  shareMode: 'unlisted';
};

export type PortfolioItem = {
  id: string;
  title: string;
  summary: string;
  coverUri?: string;
  skills: string[];
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

export const mapPortfolioProfile = (
  value: unknown,
  fallback: Partial<Pick<PortfolioProfile, 'headline' | 'slug'>> = {},
): PortfolioProfile => {
  if (!isApiRecord(value)) {
    throw new Error('PORTFOLIO_PROFILE_CONTRACT_INVALID');
  }
  return {
    slug: String(value.slug || fallback.slug || ''),
    headline: String(value.headline || fallback.headline || ''),
    location: String(value.location || ''),
    skills: Array.isArray(value.skills) ? value.skills.map(String) : [],
    publicUrl: String(value.public_url || ''),
    shareMode: 'unlisted',
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

export const mapPortfolioItem = (
  item: PortfolioItemDto,
  fallback?: Partial<PortfolioItem>,
): PortfolioItem => {
  const media = mapPortfolioMedia(item.media);
  const cover = media.find(entry => entry.type === 'image' && entry.uri);
  const course = item.course as PortfolioCourseDto | undefined;
  const uploadState = String(item.upload_state);
  return {
    id: String(item.id ?? fallback?.id ?? ''),
    title: String(item.title || fallback?.title || 'مشروع بدون عنوان'),
    summary: String(item.description ?? fallback?.summary ?? ''),
    coverUri: cover?.uri ?? fallback?.coverUri,
    skills: Array.isArray(item.tools)
      ? item.tools.map(String)
      : fallback?.skills ?? [],
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
