export const settleWithin = <T>(
  operation: Promise<T>,
  fallback: T,
  timeoutMs = 750,
): Promise<T> =>
  new Promise(resolve => {
    let settled = false;
    const finish = (value: T) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      resolve(value);
    };
    const timer = setTimeout(() => finish(fallback), timeoutMs);
    operation.then(finish, () => finish(fallback));
  });
