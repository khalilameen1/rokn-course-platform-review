import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {appendPortfolioMedia, type PortfolioMedia} from './api/profile';
import {
  completePortfolioMediaUpload,
  discardPortfolioMediaUploads,
  type PortfolioMediaOutboxEntry,
} from './portfolioMediaOutbox';
import {portfolioMediaFailureDisposition} from './portfolioMediaPolicy';

export type PortfolioMediaDeliveryResult =
  | {state: 'uploaded'; media: PortfolioMedia}
  | {state: 'discarded_file' | 'discarded_project' | 'retry'};

const flights = new Map<string, Promise<PortfolioMediaDeliveryResult>>();

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
): Promise<PortfolioMediaDeliveryResult> => {
  const key = deliveryKey(entry, boundary);
  const existing = flights.get(key);
  if (existing) return existing;

  const flight = (async (): Promise<PortfolioMediaDeliveryResult> => {
    try {
      assertAccountSessionBoundary(boundary);
      const media = await appendPortfolioMedia(
        entry.projectId,
        entry.file,
        entry.clientRequestId,
        boundary,
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
    if (flights.get(key) === flight) flights.delete(key);
  });
  flights.set(key, flight);
  return flight;
};

export const resetPortfolioMediaDeliveryForTests = () => flights.clear();
