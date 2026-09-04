import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import type {
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
  SubmissionSyncResult,
} from './projectSubmissionTypes';

export type ProjectSubmissionOperation = Readonly<{
  boundary: AccountSessionBoundary;
  generation: number;
}>;

const submissionFlights = new Map<string, Promise<SubmissionSyncResult>>();
const foregroundFlights = new Map<string, Promise<ProjectSubmissionOutcome>>();
const retryFlights = new Map<
  string,
  Promise<ProjectSubmissionRetryOutcome[]>
>();
const projectOperationTails = new Map<string, Promise<void>>();
let runtimeGeneration = 0;

const operationKey = (operation: ProjectSubmissionOperation) =>
  `${operation.boundary.scope}:${operation.boundary.epoch}`;

const projectKey = (projectId: string, operation: ProjectSubmissionOperation) =>
  `${operationKey(operation)}:${projectId}`;

export const assertProjectSubmissionOwner = (
  operation: ProjectSubmissionOperation,
) => {
  if (operation.generation !== runtimeGeneration) {
    throw new Error('ACCOUNT_SESSION_CHANGED');
  }
  assertAccountSessionBoundary(operation.boundary);
};

export const beginProjectSubmissionOperation = async () => {
  const generation = runtimeGeneration;
  const boundary = await captureAccountSessionBoundary();
  const operation = {boundary, generation};
  assertProjectSubmissionOwner(operation);
  return operation;
};

const runFlight = <T>(
  flights: Map<string, Promise<T>>,
  key: string,
  start: () => Promise<T>,
) => {
  const existing = flights.get(key);
  if (existing) return existing;
  let flight: Promise<T>;
  flight = Promise.resolve()
    .then(start)
    .finally(() => {
      if (flights.get(key) === flight) flights.delete(key);
    });
  flights.set(key, flight);
  return flight;
};

export const runForegroundSubmission = (
  projectId: string,
  operation: ProjectSubmissionOperation,
  start: () => Promise<ProjectSubmissionOutcome>,
) => runFlight(foregroundFlights, projectKey(projectId, operation), start);

export const runSubmissionSync = (
  clientSubmissionId: string,
  operation: ProjectSubmissionOperation,
  start: () => Promise<SubmissionSyncResult>,
) =>
  runFlight(
    submissionFlights,
    `${operationKey(operation)}:${clientSubmissionId}`,
    start,
  );

export const runPendingSubmissionRetry = (
  operation: ProjectSubmissionOperation,
  start: () => Promise<ProjectSubmissionRetryOutcome[]>,
) => runFlight(retryFlights, operationKey(operation), start);

/** Serialize replacement, upload and retry for one project and account. */
export const withProjectSubmissionLock = async <T>(
  projectId: string,
  operation: ProjectSubmissionOperation,
  task: () => Promise<T>,
): Promise<T> => {
  const key = projectKey(projectId, operation);
  const previous = projectOperationTails.get(key) ?? Promise.resolve();
  let release: () => void = () => undefined;
  const current = new Promise<void>(resolve => {
    release = resolve;
  });
  projectOperationTails.set(key, current);
  await previous.catch(() => undefined);
  try {
    assertProjectSubmissionOwner(operation);
    return await task();
  } finally {
    release();
    if (projectOperationTails.get(key) === current) {
      projectOperationTails.delete(key);
    }
  }
};

export const quiesceProjectSubmissionOwnership = () => {
  runtimeGeneration += 1;
  submissionFlights.clear();
  foregroundFlights.clear();
  retryFlights.clear();
  projectOperationTails.clear();
};
