import type {AccountSessionBoundary} from '../../../constants/helpers';

export const singleFlight = <T>(
  flights: Map<string, Promise<T>>,
  key: string,
  operation: () => Promise<T>,
): Promise<T> => {
  const running = flights.get(key);
  if (running) return running;
  const flight = operation();
  flights.set(key, flight);
  flight.then(
    () => {
      if (flights.get(key) === flight) flights.delete(key);
    },
    () => {
      if (flights.get(key) === flight) flights.delete(key);
    },
  );
  return flight;
};

export const ownerKey = (boundary: AccountSessionBoundary) =>
  `${boundary.scope}:${boundary.epoch}`;
