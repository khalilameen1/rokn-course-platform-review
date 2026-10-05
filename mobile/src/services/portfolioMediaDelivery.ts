import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {appendPortfolioMedia, type PortfolioMedia} from './api/profile';
import {
  completePortfolioMediaUpload,
  discardPortfolioMediaUploads,
  portfolioMediaUploadIsPaused,
  setPortfolioMediaUploadsPaused,
  type PortfolioMediaOutboxEntry,
} from './portfolioMediaOutbox';
import {portfolioMediaFailureDisposition} from './portfolioMediaPolicy';
import type {
  PortfolioTransferObserver,
  PortfolioTransferProgress,
} from './portfolioUploadProgress';

export type PortfolioMediaDeliveryResult =
  | {state: 'uploaded'; media: PortfolioMedia}
  | {state: 'discarded_file' | 'discarded_project' | 'retry' | 'paused'};

type DeliveryFlight = {
  promise: Promise<PortfolioMediaDeliveryResult>;
  observers: Set<PortfolioTransferObserver>;
  progress?: PortfolioTransferProgress;
  active: boolean;
  controller: AbortController;
  projectId: string;
  scope: string;
};
const flights = new Map<string, DeliveryFlight>();

const observe = (
  flight: DeliveryFlight,
  observer?: PortfolioTransferObserver,
) => {
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

const responseStatus = (error: unknown) =>
  Number(
    (error as {status?: unknown; response?: {status?: unknown}})?.status ??
      (error as {response?: {status?: unknown}})?.response?.status ??
      0,
  );

const deliveryKey = (
  entry: PortfolioMediaOutboxEntry,
  boundary: AccountSessionBoundary,
) => `${boundary.scope}:${boundary.epoch}:${entry.clientRequestId}`;

/** One foreground/replay owner for a durable media entry. */
export const deliverPortfolioMedia = (
  entry: PortfolioMediaOutboxEntry,
  boundary: AccountSessionBoundary,
  onProgress?: PortfolioTransferObserver,
): Promise<PortfolioMediaDeliveryResult> => {
  const key = deliveryKey(entry, boundary);
  const existing = flights.get(key);
  if (existing) {
    assertAccountSessionBoundary(boundary);
    observe(existing, onProgress);
    return existing.promise;
  }

  const flight: DeliveryFlight = {
    promise: Promise.resolve({state: 'retry'}),
    observers: new Set(),
    active: true,
    controller: new AbortController(),
    projectId: entry.projectId,
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

  flight.promise = (async (): Promise<PortfolioMediaDeliveryResult> => {
    try {
      assertAccountSessionBoundary(boundary);
      if (await portfolioMediaUploadIsPaused(entry, boundary))
        return {state: 'paused'};
      if (flight.controller.signal.aborted) return {state: 'paused'};
      const media = await appendPortfolioMedia(
        entry.projectId,
        entry.file,
        entry.clientRequestId,
        boundary,
        publish,
        flight.controller.signal,
      );
      assertAccountSessionBoundary(boundary);
      await completePortfolioMediaUpload(entry, boundary);
      assertAccountSessionBoundary(boundary);
      return {state: 'uploaded', media};
    } catch (error: unknown) {
      if (
        error instanceof Error &&
        error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
      ) {
        throw error;
      }
      if (flight.controller.signal.aborted) return {state: 'paused'};
      const disposition = portfolioMediaFailureDisposition(
        responseStatus(error),
      );
      if (disposition === 'discard_project') {
        await discardPortfolioMediaUploads(entry.projectId, boundary);
        return {state: 'discarded_project'};
      }
      if (disposition === 'discard_file') {
        await completePortfolioMediaUpload(entry, boundary);
        return {state: 'discarded_file'};
      }
      return {state: 'retry'};
    }
  })().finally(() => {
    flight.active = false;
    flight.observers.clear();
    if (flights.get(key) === flight) flights.delete(key);
  });
  flights.set(key, flight);
  return flight.promise;
};

/** Persist first. A failed storage write must not falsely acknowledge a pause. */
export const pausePortfolioMediaDelivery = async (
  projectId: string,
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  const count = await setPortfolioMediaUploadsPaused(projectId, true, boundary);
  assertAccountSessionBoundary(boundary);
  if (!count) return false;
  flights.forEach(flight => {
    if (flight.projectId === projectId && flight.scope === boundary.scope)
      flight.controller.abort();
  });
  return true;
};

export const resetPortfolioMediaDeliveryForTests = () => flights.clear();
