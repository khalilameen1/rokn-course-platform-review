import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {appendPortfolioMedia, type PortfolioMedia} from './api/profile';
import {
  type DurableOutboxDelivery,
  type DurableOutboxItem,
} from './durableOutbox';
import {
  portfolioMediaOutbox,
  setPortfolioMediaUploadsPaused,
  type PortfolioMediaOutboxEntry,
  type PortfolioMediaPayload,
} from './portfolioMediaOutbox';
import type {
  PortfolioTransferObserver,
  PortfolioTransferProgress,
} from './portfolioUploadProgress';
import {invalidatePortfolio} from './query/portfolioInvalidation';

export type PortfolioMediaDeliveryResult =
  | {state: 'uploaded'; media: PortfolioMedia}
  | {state: 'discarded_file' | 'discarded_project' | 'retry' | 'paused'};

type Delivery = DurableOutboxDelivery<
  PortfolioMediaPayload,
  PortfolioMediaDeliveryResult
>;

type UploadFlight = {
  promise: Promise<Delivery>;
  observers: Set<PortfolioTransferObserver>;
  progress?: PortfolioTransferProgress;
  active: boolean;
  controller: AbortController;
  projectId: string;
  scope: string;
};
const uploads = new Map<string, UploadFlight>();

const observe = (flight: UploadFlight, observer?: PortfolioTransferObserver) => {
  if (!observer) return;
  flight.observers.add(observer);
  if (flight.progress) {
    try {
      observer(flight.progress);
    } catch {
      /* Presentation cannot fail delivery. */
    }
  }
};

const resultFor = (
  outcome: Delivery['outcome'],
): PortfolioMediaDeliveryResult => ({
  state:
    outcome === 'drop_group'
      ? 'discarded_project'
      : outcome === 'drop'
      ? 'discarded_file'
      : outcome === 'keep' || outcome === 'pause'
      ? 'paused'
      : 'retry',
});

/**
 * The deliver function of the portfolio media adapter. One upload per entry
 * and account epoch: startup replay, foreground upload and Gallery focus join
 * the same request and its progress instead of sending the bytes twice.
 */
const upload = (
  payload: PortfolioMediaPayload,
  boundary: AccountSessionBoundary,
  onProgress?: PortfolioTransferObserver,
): Promise<Delivery> => {
  const key = `${boundary.scope}:${boundary.epoch}:${payload.clientRequestId}`;
  const existing = uploads.get(key);
  if (existing) {
    assertAccountSessionBoundary(boundary);
    observe(existing, onProgress);
    return existing.promise;
  }
  const flight: UploadFlight = {
    promise: Promise.resolve({outcome: 'keep'}),
    observers: new Set(),
    active: true,
    controller: new AbortController(),
    projectId: payload.projectId,
    scope: boundary.scope,
  };
  observe(flight, onProgress);
  const publish: PortfolioTransferObserver = progress => {
    if (!flight.active || flight.controller.signal.aborted) return;
    try {
      assertAccountSessionBoundary(boundary);
    } catch {
      return;
    }
    flight.progress = progress;
    flight.observers.forEach(observer => {
      try {
        observer(progress);
      } catch {
        /* Presentation cannot fail delivery. */
      }
    });
  };
  flight.promise = (async (): Promise<Delivery> => {
    try {
      assertAccountSessionBoundary(boundary);
      const media = await appendPortfolioMedia(
        payload.projectId,
        payload.file,
        payload.clientRequestId,
        boundary,
        publish,
        flight.controller.signal,
      );
      assertAccountSessionBoundary(boundary);
      // The server now holds a project the gallery's own mutations did not
      // write; the owner's list is stale (ARCHITECTURE.md M2).
      invalidatePortfolio(boundary.scope);
      return {outcome: 'ack', value: {state: 'uploaded', media}};
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        throw error;
      }
      // A learner pause is already durable; the item stays as it is.
      if (flight.controller.signal.aborted) {
        return {outcome: 'keep', value: {state: 'paused'}};
      }
      const outcome = portfolioMediaOutbox.policy.classify!(error);
      return {outcome, value: resultFor(outcome)};
    }
  })().finally(() => {
    flight.active = false;
    flight.observers.clear();
    if (uploads.get(key) === flight) uploads.delete(key);
  });
  uploads.set(key, flight);
  return flight.promise;
};

const itemFor = (
  entry: PortfolioMediaOutboxEntry,
): DurableOutboxItem<PortfolioMediaPayload> => ({
  id: entry.clientRequestId,
  // The engine resolves the stored generation by id before delivering.
  generation: 0,
  payload: entry,
  createdAt: new Date(entry.createdAt).toISOString(),
  expiresAt: new Date(entry.createdAt).toISOString(),
  attempts: 0,
  nextAttemptAt: new Date(entry.createdAt).toISOString(),
});

/** Foreground delivery of one staged file (Gallery upload). */
export const deliverPortfolioMedia = async (
  entry: PortfolioMediaOutboxEntry,
  boundary: AccountSessionBoundary,
  onProgress?: PortfolioTransferObserver,
): Promise<PortfolioMediaDeliveryResult> => {
  assertAccountSessionBoundary(boundary);
  const delivered = await portfolioMediaOutbox.deliver(
    itemFor(entry),
    item => upload(item.payload, boundary, onProgress),
    {boundary},
  );
  assertAccountSessionBoundary(boundary);
  if (delivered.state === 'paused') return {state: 'paused'};
  return delivered.value ?? resultFor(delivered.outcome);
};

/** Persist first. A failed storage write must not falsely acknowledge a pause. */
export const pausePortfolioMediaDelivery = async (
  projectId: string,
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  const count = await setPortfolioMediaUploadsPaused(projectId, true, boundary);
  assertAccountSessionBoundary(boundary);
  if (!count) return false;
  uploads.forEach(flight => {
    if (flight.projectId === projectId && flight.scope === boundary.scope)
      flight.controller.abort();
  });
  return true;
};

export type PortfolioMediaReplayResult = {
  attempted: number;
  completed: number;
  completedProjectIds: string[];
  completionRevision: number;
};

const replays = new Map<string, Promise<PortfolioMediaReplayResult>>();
const completionRevisions = new Map<string, number>();

/**
 * Replays one account's durable media queue independently from screen data
 * hydration. Startup, foreground and Gallery focus are lifecycle triggers:
 * they join one flight and do not wait for the automatic backoff clock.
 */
export const replayPendingPortfolioMediaUploads = async () => {
  const boundary = await captureAccountSessionBoundary();
  const flightKey = `${boundary.scope}:${boundary.epoch}`;
  const existing = replays.get(flightKey);
  if (existing) return existing;

  const flight = (async (): Promise<PortfolioMediaReplayResult> => {
    assertAccountSessionBoundary(boundary);
    const report = await portfolioMediaOutbox.flush({
      boundary,
      ignoreBackoff: true,
      deliver: item => upload(item.payload, boundary),
    });
    assertAccountSessionBoundary(boundary);
    const completedProjects = new Set(
      report.results
        .filter(result => result.outcome === 'ack')
        .map(result => result.item.payload.projectId),
    );
    const completed = report.results.filter(
      result => result.outcome === 'ack',
    ).length;
    const completionRevision =
      (completionRevisions.get(flightKey) || 0) + (completed ? 1 : 0);
    if (completed) completionRevisions.set(flightKey, completionRevision);
    return {
      attempted: report.attempted,
      completed,
      completedProjectIds: [...completedProjects],
      completionRevision,
    };
  })().finally(() => {
    if (replays.get(flightKey) === flight) replays.delete(flightKey);
  });
  replays.set(flightKey, flight);
  return flight;
};

export const resetPortfolioMediaDeliveryForTests = () => {
  uploads.clear();
  replays.clear();
  completionRevisions.clear();
};
