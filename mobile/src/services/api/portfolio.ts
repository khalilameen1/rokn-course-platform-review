import {publicRequest} from '../../constants/api';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {uploadPortfolioVideo} from '../portfolioVideoUpload';
import {settleWithin} from '../../utils/settleWithin';
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

const PORTFOLIO_CACHE_KEY = '@rokn/portfolio-cache/v1';
type PortfolioCache = {version: 1; items: PortfolioItemDto[]};
type PortfolioCacheState = {
  revision: number;
  usable: boolean;
  write: Promise<void>;
};
const portfolioCacheStates = new Map<string, PortfolioCacheState>();
const cacheState = (boundary: AccountSessionBoundary) => {
  let state = portfolioCacheStates.get(boundary.scope);
  if (!state) {
    state = {revision: 0, usable: true, write: Promise.resolve()};
    portfolioCacheStates.set(boundary.scope, state);
  }
  return state;
};

const queuePortfolioCacheWrite = (
  boundary: AccountSessionBoundary,
  items: PortfolioItemDto[],
  revision: number,
  authoritative: boolean,
) => {
  const state = cacheState(boundary);
  state.write = state.write
    .then(async () => {
      assertAccountSessionBoundary(boundary);
      if (state.revision !== revision) return;
      const key = await accountScopedStorageKey(PORTFOLIO_CACHE_KEY, boundary);
      assertAccountSessionBoundary(boundary);
      if (state.revision !== revision) return;
      const saved = await saveItem(key, {
        version: 1,
        items,
      } satisfies PortfolioCache);
      assertAccountSessionBoundary(boundary);
      if (saved && authoritative && state.revision === revision) {
        state.usable = true;
      }
    })
    .catch(() => undefined);
};

// Acknowledged mutations invalidate the cached aggregate, not invented local
// publication/count metadata. Native storage never delays the successful action.
const invalidatePortfolioCache = (boundary: AccountSessionBoundary) => {
  const state = cacheState(boundary);
  state.revision += 1;
  state.usable = false;
  queuePortfolioCacheWrite(boundary, [], state.revision, false);
};

export const getPortfolioProfile = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioProfile> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const data = payload<unknown>(await publicRequest.get('portfolio-profile'));
  assertAccountSessionBoundary(boundary);
  return mapPortfolioProfile(data);
};

export const updatePortfolioProfile = async ({
  slug,
  headline,
}: {
  slug: string;
  headline: string;
}): Promise<PortfolioProfile> => {
  const boundary = await captureAccountSessionBoundary();
  assertAccountSessionBoundary(boundary);
  const data = payload<unknown>(
    await publicRequest.put('portfolio-profile', {
      portfolio_slug: slug,
      portfolio_headline: headline,
    }),
  );
  assertAccountSessionBoundary(boundary);
  return mapPortfolioProfile(data, {headline, slug});
};

export const getPortfolio = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const state = cacheState(boundary);
  for (;;) {
    assertAccountSessionBoundary(boundary);
    const revision = state.revision;
    const data = payload(
      await publicRequest.get('portfolio', {params: {summary: 1}}),
    );
    assertAccountSessionBoundary(boundary);
    // Only repeat a read overtaken by an acknowledged mutation, never a write.
    if (state.revision !== revision) continue;
    if (!isResourceListPayload(data)) {
      throw new Error('PORTFOLIO_LIST_CONTRACT_INVALID');
    }
    const items = resourceList<PortfolioItemDto>(data);
    if (!isValidPortfolioList(items)) {
      throw new Error('PORTFOLIO_LIST_CONTRACT_INVALID');
    }
    queuePortfolioCacheWrite(boundary, items, revision, true);
    return items.map(item => mapPortfolioItem(item));
  }
};

export const getCachedPortfolio = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const state = cacheState(boundary);
  const revision = state.revision;
  const cached = await settleWithin(
    (async () => {
      await state.write;
      assertAccountSessionBoundary(boundary);
      if (!state.usable || state.revision !== revision) return null;
      const key = await accountScopedStorageKey(PORTFOLIO_CACHE_KEY, boundary);
      return getItem<Partial<PortfolioCache>>(key);
    })(),
    null,
  );
  assertAccountSessionBoundary(boundary);
  if (
    !state.usable ||
    state.revision !== revision ||
    cached?.version !== 1 ||
    !Array.isArray(cached.items) ||
    !isValidPortfolioList(cached.items)
  ) {
    return [];
  }
  return cached.items.map(item => mapPortfolioItem(item));
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
  invalidatePortfolioCache(boundary);
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
  invalidatePortfolioCache(boundary);
  return mapPortfolioMutation(data, id);
};

export const updatePortfolioItem = async (
  id: string,
  input: {title: string; summary: string},
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioItem> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const data = payload(
    await publicRequest.post(`portfolio/${id}`, {
      title: input.title,
      description: input.summary,
    }),
  );
  assertAccountSessionBoundary(boundary);
  invalidatePortfolioCache(boundary);
  return mapPortfolioMutation(data, id);
};

export const appendPortfolioMedia = async (
  id: string,
  file: PortfolioUpload,
  clientRequestId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioMedia> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
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
    );
    assertAccountSessionBoundary(boundary);
    invalidatePortfolioCache(boundary);
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
  const data = payload(
    await publicRequest.post(`portfolio/${id}/media`, form, {
      timeout: 60_000,
      headers: {'Idempotency-Key': clientRequestId},
    }),
  );
  assertAccountSessionBoundary(boundary);
  invalidatePortfolioCache(boundary);
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
  invalidatePortfolioCache(boundary);
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
  invalidatePortfolioCache(boundary);
};
