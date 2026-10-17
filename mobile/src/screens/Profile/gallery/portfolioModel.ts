import type {ImageSourcePropType} from 'react-native';
import type {PortfolioItem, PortfolioMedia} from '../../../services/roknApi';
import {portfolioCoverMedia} from '../../../services/api/portfolioContract';
import {trustedPortfolioShareUrl} from '../../../services/publicLinks';
import {portfolioLifecycleAfterMediaRemoval} from '../portfolioState';

export type Project = {
  id: string;
  publicId?: string;
  title: string;
  summary: string;
  cover: ImageSourcePropType;
  coverMediaId?: string;
  role?: string;
  tools: string[];
  externalUrl?: string;
  hidden?: boolean;
  featured?: boolean;
  sortOrder?: number;
  source: 'remote';
  courseName?: string;
  courseId?: string;
  sourceProjectId?: string;
  media: PortfolioMedia[];
  shareReady: boolean;
  uploadedMediaCount?: number;
  expectedMediaCount?: number;
};

export const fallbackPortfolioCover = require('../../../assets/images/courseSliderBackground.jpg');

export const portfolioProjectCoverUri = (
  project: Pick<Project, 'cover'>,
): string | undefined => {
  const source = project.cover;
  if (!source || typeof source === 'number') return undefined;
  const candidate = Array.isArray(source)
    ? source.find(item => typeof item?.uri === 'string' && item.uri.trim())
    : source;
  const uri = typeof candidate?.uri === 'string' ? candidate.uri.trim() : '';
  return uri || undefined;
};

/**
 * Same picker contract as the project submission photos: images are
 * downscaled to a 2560 px long edge and re-encoded (HEIC becomes JPEG), which
 * cuts phone photos to a fraction of their bytes before upload.
 */
export const PORTFOLIO_PICKER_IMAGE_OPTIONS = {
  maxWidth: 2560,
  maxHeight: 2560,
  quality: 0.8,
} as const;

export const toPortfolioProject = (item: PortfolioItem): Project => ({
  id: item.id,
  publicId: item.publicId,
  title: item.title,
  summary: item.summary,
  cover: item.coverUri ? {uri: item.coverUri} : fallbackPortfolioCover,
  coverMediaId: item.coverMediaId,
  role: item.role,
  tools: item.tools,
  externalUrl: item.externalUrl,
  hidden: item.hidden,
  featured: item.featured,
  sortOrder: item.sortOrder,
  source: 'remote',
  courseName: item.courseName,
  courseId: item.courseId,
  sourceProjectId: item.sourceProjectId,
  media: item.media,
  shareReady: item.publicationState === 'published',
  uploadedMediaCount: item.uploadedMediaCount,
  expectedMediaCount: item.expectedMediaCount,
});

export const appendPortfolioMediaToProject = (
  project: Project,
  uploaded: PortfolioMedia,
): Project => {
  if (project.media.some(media => media.id === uploaded.id)) return project;
  const media = [...project.media, uploaded];
  const next = {
    ...project,
    media,
    // A newly-added file changes the public aggregate. The backend unpublishes
    // it until every file is deliverable and finalize succeeds again.
    shareReady: false,
    uploadedMediaCount: Math.max(media.length, project.uploadedMediaCount || 0),
  };
  const cover = portfolioCoverMedia(next.media, project.coverMediaId);
  if (cover?.uri) next.cover = {uri: cover.uri};
  return next;
};

export const removePortfolioMediaFromProject = (
  project: Project,
  mediaId: string,
): Project => {
  const media = project.media.filter(candidate => candidate.id !== mediaId);
  // Deleting the chosen cover clears it on the server too.
  const coverMediaId =
    project.coverMediaId === mediaId ? undefined : project.coverMediaId;
  const cover = portfolioCoverMedia(media, coverMediaId);
  return {
    ...project,
    media,
    coverMediaId,
    ...portfolioLifecycleAfterMediaRemoval(project, media.length),
    cover: cover?.uri ? {uri: cover.uri} : fallbackPortfolioCover,
  };
};

/** Server order: featured first, then the owner's order, newest first. */
export const sortPortfolioProjects = (projects: Project[]): Project[] =>
  [...projects].sort(
    (a, b) =>
      Number(Boolean(b.featured)) - Number(Boolean(a.featured)) ||
      (a.sortOrder ?? 0) - (b.sortOrder ?? 0) ||
      Number(b.id) - Number(a.id),
  );

const PROJECT_PUBLIC_ID =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * One project's public page (RoknPublicUrl::portfolioProject). Only a live
 * portfolio link and a visible, published project can be shared.
 */
export const portfolioProjectShareUrl = (
  portfolioUrl: string | undefined,
  project: Pick<Project, 'publicId' | 'hidden' | 'shareReady'>,
): string | null => {
  const base = trustedPortfolioShareUrl(portfolioUrl);
  const publicId = project.publicId?.toLowerCase();
  if (
    !base ||
    !publicId ||
    !PROJECT_PUBLIC_ID.test(publicId) ||
    project.hidden ||
    !project.shareReady
  )
    return null;
  return `${base.replace(/\/$/, '')}/p/${encodeURIComponent(publicId)}`;
};

export const isPortfolioAccountChangedError = (error: unknown) =>
  error instanceof Error && error.message === 'ACCOUNT_CHANGED_DURING_REQUEST';
