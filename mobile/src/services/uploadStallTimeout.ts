import type {AxiosProgressEvent} from 'axios';

/**
 * Inactivity timeout for one HTTP upload, instead of a total deadline.
 *
 * Behaviour reproduced from Uppy's XHRUpload `timeout` option (its
 * ProgressTimeout helper, default 30 s): the request is aborted only when no
 * upload progress arrived for the window, so a slow but moving 1-2 Mbps
 * uplink is never cut off by a fixed 30-60 s budget. Unlike Uppy, the wait
 * after the last byte is bounded too, because Rokn endpoints answer within
 * their own server deadline. This is an independent implementation, not
 * copied Uppy source.
 */
export const UPLOAD_STALL_TIMEOUT_MS = 30_000;
export const UPLOAD_RESPONSE_TIMEOUT_MS = 60_000;

export type UploadTransport = {
  signal: AbortSignal;
  /** Axios applies no total deadline; the stall guard owns cancellation. */
  timeout: 0;
  onUploadProgress: (event: AxiosProgressEvent) => void;
};

export const isUploadStalledError = (error: unknown) =>
  Boolean(
    error &&
      typeof error === 'object' &&
      (error as {uploadStalled?: unknown}).uploadStalled === true,
  );

export const withUploadStallTimeout = async <T>(
  send: (transport: UploadTransport) => Promise<T>,
  {
    signal,
    onUploadProgress,
    stallMs = UPLOAD_STALL_TIMEOUT_MS,
    responseMs = UPLOAD_RESPONSE_TIMEOUT_MS,
  }: {
    signal?: AbortSignal;
    onUploadProgress?: (event: AxiosProgressEvent) => void;
    stallMs?: number;
    responseMs?: number;
  } = {},
): Promise<T> => {
  const controller = new AbortController();
  let stalled = false;
  let timer: ReturnType<typeof setTimeout> | undefined;
  const arm = (milliseconds: number) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      stalled = true;
      controller.abort();
    }, milliseconds);
  };
  // The caller's pause/cancel stays authoritative and is never reported as a
  // stall, so existing pause and retry classification is unchanged.
  const forwardAbort = () => controller.abort();
  if (signal?.aborted) controller.abort();
  else signal?.addEventListener?.('abort', forwardAbort, {once: true});
  arm(stallMs);
  try {
    return await send({
      signal: controller.signal,
      timeout: 0,
      onUploadProgress: event => {
        if (controller.signal.aborted) return;
        const total = Number(event.total);
        const sent =
          Number.isFinite(total) && total > 0 && Number(event.loaded) >= total;
        arm(sent ? responseMs : stallMs);
        try {
          onUploadProgress?.(event);
        } catch {
          // Progress is presentation; it can never fail the transfer.
        }
      },
    });
  } catch (error) {
    if (stalled && !signal?.aborted) {
      // Same shape as an Axios timeout so retry policies treat it as one.
      throw Object.assign(new Error('timeout'), {
        code: 'ECONNABORTED',
        uploadStalled: true,
        cause: error,
      });
    }
    throw error;
  } finally {
    if (timer) clearTimeout(timer);
    signal?.removeEventListener?.('abort', forwardAbort);
  }
};

/** Whole-number percent for a progress event, or null when unknown. */
export const uploadProgressPercent = (
  event: Pick<AxiosProgressEvent, 'loaded' | 'total'>,
): number | null => {
  const total = Number(event.total);
  const loaded = Number(event.loaded);
  if (!Number.isFinite(total) || total <= 0 || !Number.isFinite(loaded))
    return null;
  return Math.max(0, Math.min(100, Math.floor((loaded / total) * 100)));
};
