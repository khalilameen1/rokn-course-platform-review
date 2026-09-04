import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../constants/helpers';
import {deliverPortfolioMedia} from './portfolioMediaDelivery';
import {listPortfolioMediaUploads} from './portfolioMediaOutbox';

export type PortfolioMediaReplayResult = {
  attempted: number;
  completed: number;
  completedProjectIds: string[];
  completionRevision: number;
};

const flights = new Map<string, Promise<PortfolioMediaReplayResult>>();
const completionRevisions = new Map<string, number>();

/**
 * Replays one account's durable media queue independently from screen data
 * hydration. Every caller for that account joins the same flight, so startup,
 * foreground and Gallery focus cannot upload the same entry concurrently.
 */
export const replayPendingPortfolioMediaUploads = async () => {
  const boundary = await captureAccountSessionBoundary();
  const flightKey = `${boundary.scope}:${boundary.epoch}`;
  const existing = flights.get(flightKey);
  if (existing) return existing;

  const flight = (async (): Promise<PortfolioMediaReplayResult> => {
    assertAccountSessionBoundary(boundary);
    const pending = await listPortfolioMediaUploads(undefined, boundary);
    const failedProjects = new Set<string>();
    const completedProjects = new Set<string>();
    let attempted = 0;
    let completed = 0;

    for (const entry of pending) {
      assertAccountSessionBoundary(boundary);
      if (failedProjects.has(entry.projectId)) continue;
      attempted += 1;
      const result = await deliverPortfolioMedia(entry, boundary);
      assertAccountSessionBoundary(boundary);
      if (result.state === 'uploaded') {
        completed += 1;
        completedProjects.add(entry.projectId);
      } else if (result.state !== 'discarded_file') {
        failedProjects.add(entry.projectId);
      }
    }

    const completionRevision = completed
      ? (completionRevisions.get(flightKey) || 0) + 1
      : completionRevisions.get(flightKey) || 0;
    if (completed) completionRevisions.set(flightKey, completionRevision);
    return {
      attempted,
      completed,
      completedProjectIds: [...completedProjects],
      completionRevision,
    };
  })().finally(() => {
    if (flights.get(flightKey) === flight) flights.delete(flightKey);
  });
  flights.set(flightKey, flight);
  return flight;
};

export const resetPortfolioMediaReplayForTests = () => {
  flights.clear();
  completionRevisions.clear();
};
