/**
 * Serializes operations for a key within one JS runtime. Each owner creates its
 * own queue; unrelated keys and owners remain independent. This is not a
 * cross-process lock. An operation must not await another operation on its key.
 */
export function createKeyedAsyncQueue() {
  const tails = new Map<string, Promise<void>>();

  const run = <T>(key: string, operation: () => Promise<T>): Promise<T> => {
    const previous = tails.get(key) ?? Promise.resolve();
    const result = previous.then(operation, operation);
    // A failed operation rejects its caller, not the next operation in line.
    const tail = result.then(
      () => undefined,
      () => undefined,
    );
    tails.set(key, tail);
    void tail.then(() => {
      if (tails.get(key) === tail) tails.delete(key);
    });
    return result;
  };

  return Object.assign(run, {
    // Snapshot the already queued work without reserving a slot for a read.
    // Later operations and other keys do not extend this barrier.
    waitForPending: (key: string): Promise<void> =>
      tails.get(key) ?? Promise.resolve(),
  });
}
