import {publicRequest} from '../../constants/api';
import {subscriptionMessages} from '../../constants/subscriptionMessages';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {uploadPortfolioVideo} from '../portfolioVideoUpload';
import type {PortfolioTransferObserver} from '../portfolioUploadProgress';
import {assertPortfolioTransferActive} from '../portfolioTransferControl';
import {withUploadStallTimeout} from '../uploadStallTimeout';
import {
  isApiRecord,
  isResourceListPayload,
  payload,
  resourceList,
} from './common';
import {
  type EligibleProject,
  type EligibleProjectDto,
  isValidPortfolioList,
  mapPortfolioItem,
  mapPortfolioMedia,
  mapPortfolioMutation,
  mapPortfolioProfile,
  normalizePortfolioChips,
  PORTFOLIO_SKILLS_MAX,
  PORTFOLIO_TOOLS_MAX,
  type PortfolioItem,
  type PortfolioItemDto,
  type PortfolioMedia,
  type PortfolioProfile,
  type PortfolioUpload,
} from './portfolioContract';

export type {
  EligibleProject,
  PortfolioItem,
  PortfolioMedia,
  PortfolioProfile,
  PortfolioUpload,
} from './portfolioContract';

/** A fresh server decision, never an offline entitlement or a tier-name guess. */
export const assertPortfolioUploadAccess = async (
  boundary: AccountSessionBoundary,
  signal?: AbortSignal,
): Promise<void> => {
  assertAccountSessionBoundary(boundary);
  assertPortfolioTransferActive(signal);
  const data = payload<unknown>(
    await (signal
      ? publicRequest.get('portfolio/upload-access', {signal})
      : publicRequest.get('portfolio/upload-access')),
  );
  assertAccountSessionBoundary(boundary);
  if (!isApiRecord(data) || typeof data.can_upload !== 'boolean') {
    throw new Error('PORTFOLIO_UPLOAD_ACCESS_CONTRACT_INVALID');
  }
  if (!data.can_upload) {
    throw {
      status: 403,
      data: {
        code: 'PORTFOLIO_CERTIFICATE_SUBSCRIPTION_REQUIRED',
        has_subscription: data.has_subscription === true,
        message:
          data.has_subscription === true
            ? subscriptionMessages.portfolioUpgrade.body
            : subscriptionMessages.portfolioSubscribe.body,
      },
    };
  }
};

export const getPortfolioProfile = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioProfile> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const data = payload<unknown>(await publicRequest.get('portfolio-profile'));
  assertAccountSessionBoundary(boundary);
  return mapPortfolioProfile(data);
};

/** Only fields present in the patch are sent (PortfolioProfileController). */
export type PortfolioProfilePatch = {
  headline?: string;
  skills?: string[];
};

export const updatePortfolioProfile = async (
  patch: PortfolioProfilePatch,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioProfile> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const body: Record<string, unknown> = {};
  if (patch.headline !== undefined) body.portfolio_headline = patch.headline;
  if (patch.skills !== undefined) {
    body.portfolio_skills = normalizePortfolioChips(
      patch.skills,
      PORTFOLIO_SKILLS_MAX,
    );
  }
  const data = payload<unknown>(
    await publicRequest.put('portfolio-profile', body),
  );
  assertAccountSessionBoundary(boundary);
  return mapPortfolioProfile(data, {headline: patch.headline});
};

/** The owner's portfolio list (`summary=1`), validated as one contract. */
export const getPortfolio = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload(
    await publicRequest.get('portfolio', {params: {summary: 1}}),
  );
  assertAccountSessionBoundary(boundary);
  if (!isResourceListPayload(data)) {
    throw new Error('PORTFOLIO_LIST_CONTRACT_INVALID');
  }
  const items = resourceList<PortfolioItemDto>(data);
  if (!isValidPortfolioList(items)) {
    throw new Error('PORTFOLIO_LIST_CONTRACT_INVALID');
  }
  return items.map(item => mapPortfolioItem(item));
};

export const getPortfolioItem = async (
  id: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload(await publicRequest.get(`portfolio/${id}`));
  assertAccountSessionBoundary(boundary);
  return mapPortfolioMutation(data, id);
};

export const createPortfolioItem = async (
  input: {
    title: string;
    summary: string;
    sourceProjectId?: string;
    courseId?: string;
    clientRequestId: string;
    expectedMediaCount: number;
  },
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload(
    await publicRequest.post(
      'portfolio',
      {
        client_request_id: input.clientRequestId,
        title: input.title,
        description: input.summary,
        expected_media_count: Math.max(
          0,
          Math.min(12, input.expectedMediaCount),
        ),
        ...(input.sourceProjectId
          ? {source_project_id: input.sourceProjectId}
          : {}),
        ...(input.courseId ? {course_id: input.courseId} : {}),
      },
      {headers: {'Idempotency-Key': input.clientRequestId}},
    ),
  );
  assertAccountSessionBoundary(boundary);
  return mapPortfolioMutation(data);
};

export const finalizePortfolioItem = async (
  id: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload(await publicRequest.post(`portfolio/${id}/finalize`));
  assertAccountSessionBoundary(boundary);
  return mapPortfolioMutation(data, id);
};

/**
 * Partial project update (POST portfolio/{id}). Only present keys are sent,
 * so a hide/show or reorder never rewrites the text fields.
 */
export type PortfolioItemPatch = {
  title?: string;
  summary?: string;
  role?: string;
  tools?: string[];
  externalUrl?: string;
  /** false hides the project from the public link without deleting it. */
  visible?: boolean;
  /** An image media id of this project; null returns to the first image. */
  coverMediaId?: string | null;
  featured?: boolean;
  sortOrder?: number;
};

export const portfolioItemPatchBody = (input: PortfolioItemPatch) => {
  const body: Record<string, unknown> = {};
  if (input.title !== undefined) body.title = input.title;
  if (input.summary !== undefined) body.description = input.summary;
  if (input.role !== undefined) body.role = input.role;
  if (input.tools !== undefined)
    body.tools = normalizePortfolioChips(input.tools, PORTFOLIO_TOOLS_MAX);
  if (input.externalUrl !== undefined)
    body.external_url = input.externalUrl.trim() || null;
  if (input.visible !== undefined) body.is_public = input.visible;
  if (input.coverMediaId !== undefined)
    body.cover_media_id =
      input.coverMediaId === null ? null : Number(input.coverMediaId);
  if (input.featured !== undefined) body.is_featured = input.featured;
  if (input.sortOrder !== undefined)
    body.sort_order = Math.max(0, Math.min(10000, Math.round(input.sortOrder)));
  return body;
};

export const updatePortfolioItem = async (
  id: string,
  input: PortfolioItemPatch,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload(
    await publicRequest.post(`portfolio/${id}`, portfolioItemPatchBody(input)),
  );
  assertAccountSessionBoundary(boundary);
  return mapPortfolioMutation(data, id);
};

export const appendPortfolioMedia = async (
  id: string,
  file: PortfolioUpload,
  clientRequestId: string,
  ownerBoundary?: AccountSessionBoundary,
  onProgress?: PortfolioTransferObserver,
  signal?: AbortSignal,
): Promise<PortfolioMedia> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  // Also covers durable queue replay before sending image bytes or resuming video.
  await assertPortfolioUploadAccess(boundary, signal);
  assertPortfolioTransferActive(signal);
  const type = String(file.type || '')
    .toLowerCase()
    .startsWith('video/')
    ? 'video'
    : 'image';
  if (type === 'video') {
    const direct = await uploadPortfolioVideo(
      id,
      file,
      clientRequestId,
      boundary,
      onProgress,
      signal,
    );
    assertAccountSessionBoundary(boundary);
      const item = mapPortfolioMedia([direct])[0];
    if (!item) throw new Error('PORTFOLIO_MEDIA_CONTRACT_INVALID');
    return item;
  }
  const form = new FormData();
  form.append('client_request_id', clientRequestId);
  form.append('file_type', type);
  form.append('file', {
    uri: file.uri,
    type: file.type || 'image/jpeg',
    name: file.fileName || `portfolio-${Date.now()}.jpg`,
  } as unknown as Blob);
  let active = true;
  assertAccountSessionBoundary(boundary);
  assertPortfolioTransferActive(signal);
  try {
    onProgress?.({loaded: 0, total: null, phase: 'uploading'});
  } catch {
    // Presentation cannot fail delivery.
  }
  let response;
  try {
    // A stall timeout, not a 60 s total: a moving slow upload must finish.
    response = await withUploadStallTimeout(
      transport =>
        publicRequest.post(`portfolio/${id}/media`, form, {
          ...transport,
          headers: {'Idempotency-Key': clientRequestId},
        }),
      {
        signal,
        onUploadProgress: event => {
          if (
            signal?.aborted ||
            !active ||
            !onProgress ||
            !Number.isFinite(event.loaded) ||
            event.loaded < 0
          )
            return;
          const total =
            typeof event.total === 'number' &&
            Number.isFinite(event.total) &&
            event.total > 0
              ? event.total
              : null;
          try {
            assertAccountSessionBoundary(boundary);
            onProgress({
              loaded: event.loaded,
              total,
              phase: total && event.loaded >= total ? 'saving' : 'uploading',
            });
          } catch {
            /* Progress is presentation, not a mutation result. */
          }
        },
      },
    );
  } finally {
    active = false;
  }
  const data = payload(response);
  assertAccountSessionBoundary(boundary);
  const item = mapPortfolioMedia([data])[0];
  if (!item) throw new Error('PORTFOLIO_MEDIA_CONTRACT_INVALID');
  return item;
};

const alreadyDeleted = (error: unknown) => {
  const failure = error as {status?: unknown; response?: {status?: unknown}};
  return Number(failure.status ?? failure.response?.status ?? 0) === 404;
};

export const deletePortfolioMedia = async (
  portfolioId: string,
  mediaId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  try {
    await publicRequest.delete(`portfolio/${portfolioId}/media/${mediaId}`);
    assertAccountSessionBoundary(boundary);
  } catch (error) {
    assertAccountSessionBoundary(boundary);
    if (!alreadyDeleted(error)) throw error;
  }
};

export const getEligibleProjects = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<EligibleProject[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload(
    await publicRequest.get('portfolio/eligible-projects', {
      params: {per_page: 50},
    }),
  );
  assertAccountSessionBoundary(boundary);
  if (!isApiRecord(data) || !isResourceListPayload(data.items)) {
    throw new Error('PORTFOLIO_ELIGIBLE_PROJECTS_CONTRACT_INVALID');
  }
  const items = resourceList<EligibleProjectDto>(data.items);
  if (
    items.some(
      item => !isApiRecord(item) || !item.project_id || !item.course?.id,
    )
  ) {
    throw new Error('PORTFOLIO_ELIGIBLE_PROJECTS_CONTRACT_INVALID');
  }
  return items.map(item => {
    const course = item.course!;
    return {
      projectId: String(item.project_id),
      courseId: String(course.id),
      title: String(item.title || 'مشروع تطبيقي'),
      summary: String(item.requirements || ''),
      courseName: String(course.title || course.title_en || 'كورس ركن'),
      courseImage: course.image ? String(course.image) : undefined,
      moduleName: item.module?.title ? String(item.module.title) : undefined,
      passedAt: item.passed_at ? String(item.passed_at) : undefined,
    };
  });
};

export const deletePortfolioItem = async (
  id: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  try {
    await publicRequest.delete(`portfolio/${id}`);
    assertAccountSessionBoundary(boundary);
  } catch (error) {
    assertAccountSessionBoundary(boundary);
    if (!alreadyDeleted(error)) throw error;
  }
};
