import type {ImageSourcePropType} from 'react-native';
import type {PortfolioItem, PortfolioMedia} from '../../../services/roknApi';
import {portfolioLifecycleAfterMediaRemoval} from '../portfolioState';

export type Project = {
  id: string;
  title: string;
  summary: string;
  cover: ImageSourcePropType;
  skills: string[];
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

export const toPortfolioProject = (item: PortfolioItem): Project => ({
  id: item.id,
  title: item.title,
  summary: item.summary,
  cover: item.coverUri ? {uri: item.coverUri} : fallbackPortfolioCover,
  skills: item.skills,
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
  const firstImage = next.media.find(
    candidate => candidate.type === 'image' && candidate.uri,
  );
  if (firstImage?.uri) next.cover = {uri: firstImage.uri};
  return next;
};

export const removePortfolioMediaFromProject = (
  project: Project,
  mediaId: string,
): Project => {
  const media = project.media.filter(candidate => candidate.id !== mediaId);
  const firstImage = media.find(
    candidate => candidate.type === 'image' && candidate.uri,
  );
  return {
    ...project,
    media,
    ...portfolioLifecycleAfterMediaRemoval(project, media.length),
    cover: firstImage?.uri ? {uri: firstImage.uri} : fallbackPortfolioCover,
  };
};

export const isPortfolioAccountChangedError = (error: unknown) =>
  error instanceof Error && error.message === 'ACCOUNT_CHANGED_DURING_REQUEST';
