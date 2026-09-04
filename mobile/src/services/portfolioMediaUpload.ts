import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsManaged,
  removeLearnerDraftFile,
  type LearnerDraftFile,
} from './learnerDraftFiles';
import type {PortfolioMedia} from './roknApi';
import {
  stagePortfolioMediaUpload,
  type PortfolioMediaOutboxEntry,
} from './portfolioMediaOutbox';
import {deliverPortfolioMedia} from './portfolioMediaDelivery';

type PortfolioMediaSource = Pick<
  LearnerDraftFile,
  'uri' | 'type' | 'fileName' | 'size'
>;

type StagePortfolioMediaOptions = {
  boundary: AccountSessionBoundary;
  projectId: string;
  requestIdAt: (index: number) => string;
  sources: PortfolioMediaSource[];
};

type UploadPortfolioMediaOptions = {
  boundary: AccountSessionBoundary;
  entries: PortfolioMediaOutboxEntry[];
  onProgress?: (completed: number, total: number) => void;
  onUploaded: (projectId: string, media: PortfolioMedia) => void;
};

export type PortfolioMediaUploadResult = {
  discardedFiles: number;
  interrupted: boolean;
};

export const stagePortfolioMediaFiles = async ({
  boundary,
  projectId,
  requestIdAt,
  sources,
}: StagePortfolioMediaOptions): Promise<PortfolioMediaOutboxEntry[]> => {
  const staged: PortfolioMediaOutboxEntry[] = [];
  for (const [index, source] of sources.entries()) {
    assertAccountSessionBoundary(boundary);
    // The editor already owns managed picks. Transfer that same local file to
    // the durable outbox instead of copying every large video a second time.
    const cached = learnerDraftFileIsManaged(source)
      ? source
      : await cacheLearnerDraftFile(
          'portfolio',
          source,
          50 * 1024 * 1024,
          boundary,
        );
    assertAccountSessionBoundary(boundary);
    try {
      staged.push(
        await stagePortfolioMediaUpload(
          {
            projectId,
            clientRequestId: requestIdAt(index),
            file: cached,
            createdAt: Date.now() + index,
          },
          boundary,
        ),
      );
    } catch (error) {
      await removeLearnerDraftFile(cached);
      throw error;
    }
  }
  assertAccountSessionBoundary(boundary);
  return staged;
};

export const uploadPortfolioMediaFiles = async ({
  boundary,
  entries,
  onProgress,
  onUploaded,
}: UploadPortfolioMediaOptions): Promise<PortfolioMediaUploadResult> => {
  let discardedFiles = 0;

  for (const [index, entry] of entries.entries()) {
    assertAccountSessionBoundary(boundary);
    const result = await deliverPortfolioMedia(entry, boundary);
    assertAccountSessionBoundary(boundary);
    if (result.state === 'uploaded') {
      onUploaded(entry.projectId, result.media);
      onProgress?.(index + 1, entries.length);
      continue;
    }
    if (result.state === 'discarded_file') {
      discardedFiles += 1;
      onProgress?.(index + 1, entries.length);
      continue;
    }
    return {discardedFiles, interrupted: true};
  }

  return {discardedFiles, interrupted: false};
};
