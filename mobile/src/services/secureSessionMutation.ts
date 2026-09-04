let mutationTail: Promise<unknown> = Promise.resolve();

export const serializeSecureSessionMutation = <T>(
  operation: () => Promise<T>,
) => {
  const result = mutationTail.then(operation, operation);
  mutationTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

export const resetSecureSessionMutationForTests = () => {
  mutationTail = Promise.resolve();
};
