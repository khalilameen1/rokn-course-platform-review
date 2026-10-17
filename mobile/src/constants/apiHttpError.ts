/**
 * HTTP failure raised by the shared Rokn Axios client when the server answered
 * with a non-2xx status.
 *
 * Shape follows Axios' own `AxiosError` convention (an `Error` carrying
 * `response`, `config`, `request` and `status`; see axios/lib/core/AxiosError.js)
 * so stack traces, Sentry grouping and `instanceof Error` checks work.
 *
 * Compatibility: the interceptor used to reject with the raw `AxiosResponse`,
 * and existing callers read `error.status`, `error.data` and `error.headers`
 * directly (or `error.response.*`). Those fields stay as own properties.
 * `code` is the backend diagnostic code from the body (never Axios'
 * `ERR_BAD_REQUEST`, which callers would otherwise mistake for a server code);
 * Axios' transport code is kept separately as `axiosCode`.
 */
type HttpResponseLike = {
  status: number;
  statusText?: string;
  data?: unknown;
  headers?: unknown;
  config?: unknown;
  request?: unknown;
};

const asRecord = (value: unknown): Record<string, unknown> =>
  typeof value === 'object' && value !== null
    ? (value as Record<string, unknown>)
    : {};

/** Statuses a caller may repeat unchanged later (RFC 9110 §15.5.9, §15.6.3-5; RFC 6585 §4; RFC 8470 §5.2). */
const RETRYABLE_HTTP_STATUSES = [408, 425, 429, 502, 503, 504];

export class ApiError extends Error {
  readonly isRoknApiError = true;
  readonly status: number;
  readonly statusText: string;
  readonly code: string | undefined;
  readonly axiosCode: string | undefined;
  readonly data: unknown;
  readonly headers: unknown;
  readonly config: unknown;
  readonly request: unknown;
  readonly response: HttpResponseLike;
  readonly retryable: boolean;
  readonly cause: unknown;

  constructor(response: HttpResponseLike, cause?: unknown) {
    const original = asRecord(cause);
    super(
      typeof original.message === 'string' && original.message
        ? original.message
        : `Request failed with status code ${response.status}`,
    );
    // Hermes/Babel class transforms can lose the subclass prototype.
    Object.setPrototypeOf(this, new.target.prototype);
    this.name = 'ApiError';
    const body = asRecord(response.data);
    this.status = response.status;
    this.statusText = String(response.statusText || '');
    this.code =
      typeof body.code === 'string' && body.code.trim()
        ? body.code.trim()
        : undefined;
    this.axiosCode =
      typeof original.code === 'string' ? original.code : undefined;
    this.data = response.data;
    this.headers = response.headers;
    this.config = response.config ?? original.config;
    this.request = response.request ?? original.request;
    this.response = response;
    this.retryable = RETRYABLE_HTTP_STATUSES.includes(response.status);
    this.cause = cause;
  }
}

export const isApiError = (value: unknown): value is ApiError =>
  value instanceof ApiError || asRecord(value).isRoknApiError === true;

const httpResponseOf = (value: unknown): HttpResponseLike | null => {
  const record = asRecord(value);
  const status = Number(record.status);
  return Number.isInteger(status) && status > 0
    ? (record as HttpResponseLike)
    : null;
};

/**
 * Wrap a server-answered failure in `ApiError`; transport failures (no
 * response: offline, timeout, cancellation) keep Axios' original error.
 */
export const toApiRejection = (error: unknown): unknown => {
  if (isApiError(error)) return error;
  const response = httpResponseOf(asRecord(error).response);
  return response ? new ApiError(response, error) : error;
};

export type HttpFailure = {
  status: number;
  data: Record<string, unknown>;
  headers: unknown;
};

/**
 * One place that reads status/body/headers from any failure shape the app
 * still meets: `ApiError`, an Axios-style error with `response`, or a legacy
 * raw response-like object (`{status, data, headers}`) from mocks/adapters.
 */
export const httpFailureOf = (error: unknown): HttpFailure => {
  if (isApiError(error)) {
    return {
      status: error.status,
      data: asRecord(error.data),
      headers: error.headers,
    };
  }
  const root = asRecord(error);
  const nested = asRecord(root.response);
  const directStatus = Number(root.status);
  const nestedStatus = Number(nested.status);
  const status =
    Number.isFinite(directStatus) && directStatus > 0
      ? directStatus
      : Number.isFinite(nestedStatus) && nestedStatus > 0
      ? nestedStatus
      : 0;
  const hasNested = Object.keys(nested).length > 0;
  return {
    status,
    data: asRecord(hasNested && nested.data !== undefined ? nested.data : root.data),
    headers: hasNested ? nested.headers : root.headers,
  };
};
