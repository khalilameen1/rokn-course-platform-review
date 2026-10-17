/**
 * Catch handler for best-effort writes whose failure must not block the
 * caller (session cleanup, durable intents) but must not vanish either.
 * Reporting goes through the existing PII-free operational telemetry; the
 * import is lazy because telemetry itself depends on secureSession and the
 * durable outbox.
 */
export const reportBackgroundWriteFailure =
  (source: string) =>
  (error: unknown): undefined => {
    const reported =
      error instanceof Error
        ? error
        : new Error(`${source.toUpperCase()}_FAILED`);
    void import('./operationalTelemetry')
      .then(({reportClientError}) => reportClientError(reported, {source}))
      .catch(() => undefined);
    return undefined;
  };
