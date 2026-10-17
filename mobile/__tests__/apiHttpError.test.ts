jest.mock('../src/constants/api', () => ({
  DEFAULT_READ_RECOVERY_BUDGET_MS: 12_000,
  publicRequest: {get: jest.fn()},
}));
jest.mock('../src/utils/serverClock', () => ({
  serverNowMs: () => Date.now(),
}));

import {
  ApiError,
  httpFailureOf,
  isApiError,
  toApiRejection,
} from '../src/constants/apiHttpError';
import {getExceptionPayload} from '../src/constants/apiErrors';
import {
  friendlyNetworkMessage,
  networkFailureKind,
  retryAfterMs,
  transientReadFailureAllowsCache,
} from '../src/services/networkExperience';
import {errorCode, errorPayload, errorStatus} from '../src/utils/errorPayload';
import {
  courseReadFailureStatus,
  isCourseRevisionChangedError,
} from '../src/services/api/courseDetailsRequest';
import {socialAuthFailureCode} from '../src/services/socialAuthErrors';

const apiError = (
  status: number,
  data: Record<string, unknown> = {},
  headers: Record<string, string> = {},
) =>
  toApiRejection(
    Object.assign(new Error(`Request failed with status code ${status}`), {
      code: status >= 500 ? 'ERR_BAD_RESPONSE' : 'ERR_BAD_REQUEST',
      response: {status, statusText: '', data, headers, config: {}},
    }),
  );

describe('ApiError compatibility with existing failure probes', () => {
  it('is a real Error that keeps the raw response fields callers read', () => {
    const error = apiError(422, {status: 422, message: 'راجع البيانات'});
    expect(isApiError(error)).toBe(true);
    expect(error).toBeInstanceOf(Error);
    expect(error).toMatchObject({
      status: 422,
      data: {status: 422, message: 'راجع البيانات'},
      response: {status: 422},
      retryable: false,
    });
    expect(toApiRejection(error)).toBe(error);
  });

  it('classifies like the raw response did', () => {
    expect(networkFailureKind(apiError(401))).toBe('unauthenticated');
    expect(networkFailureKind(apiError(404))).toBe('missing');
    expect(networkFailureKind(apiError(409))).toBe('conflict');
    expect(networkFailureKind(apiError(503))).toBe('maintenance');
    expect(networkFailureKind(apiError(500))).toBe('server');
    expect(
      networkFailureKind(apiError(500, {code: 'COURSE_CONTRACT_INVALID'})),
    ).toBe('contract');
    expect(transientReadFailureAllowsCache(apiError(502))).toBe(true);
    expect(transientReadFailureAllowsCache(apiError(403))).toBe(false);
  });

  it('reads RFC 9110 Retry-After as seconds or HTTP-date', () => {
    expect(retryAfterMs(apiError(429, {}, {'retry-after': '30'}))).toBe(30_000);
    const date = new Date(Date.now() + 120_000).toUTCString();
    const fromDate = retryAfterMs(apiError(503, {}, {'Retry-After': date}));
    expect(fromDate).toBeGreaterThan(110_000);
    expect(fromDate).toBeLessThanOrEqual(120_000);
    expect(retryAfterMs(apiError(503))).toBeNull();
    expect(retryAfterMs(apiError(503, {}, {'retry-after': 'soon'}))).toBeNull();
    // Same copy as the raw response the interceptor used to reject with.
    expect(
      friendlyNetworkMessage(apiError(429, {}, {'retry-after': '30'})),
    ).toBe(
      friendlyNetworkMessage({status: 429, headers: {'retry-after': '30'}}),
    );
    expect(
      friendlyNetworkMessage(apiError(429, {}, {'retry-after': '30'})),
    ).not.toBe(friendlyNetworkMessage(apiError(429)));
  });

  it('keeps payload helpers and domain probes working unchanged', () => {
    const revision = apiError(409, {
      status: 409,
      code: 'course_revision_changed',
      message: 'تغيّر الكورس',
    });
    expect(getExceptionPayload(revision)).toMatchObject({
      code: 409,
      message: 'تغيّر الكورس',
      diagnostic_code: 'course_revision_changed',
    });
    expect(errorStatus(revision)).toBe(409);
    expect(errorCode(revision)).toBe('course_revision_changed');
    expect(errorPayload(revision)).toMatchObject({status: 409});
    expect(courseReadFailureStatus(revision)).toBe(409);
    expect(isCourseRevisionChangedError(revision)).toBe(true);
  });

  it('does not turn Axios transport codes into social-auth diagnostic codes', () => {
    expect(socialAuthFailureCode(apiError(500))).toBe('PROVIDER_UNAVAILABLE');
    expect(socialAuthFailureCode(apiError(400))).toBe('LOGIN_FAILED');
    expect(
      socialAuthFailureCode(apiError(403, {code: 'DEVICE_LOGIN_DENIED'})),
    ).toBe('DEVICE_LOGIN_DENIED');
  });

  it('reads status, body and headers from every legacy shape in one place', () => {
    const headers = {'retry-after': '5'};
    const data = {code: 'x'};
    expect(httpFailureOf({status: 404, data, headers})).toEqual({
      status: 404,
      data,
      headers,
    });
    expect(httpFailureOf({response: {status: 404, data, headers}})).toEqual({
      status: 404,
      data,
      headers,
    });
    expect(httpFailureOf(new ApiError({status: 404, data, headers}))).toEqual({
      status: 404,
      data,
      headers,
    });
    expect(httpFailureOf(new Error('offline')).status).toBe(0);
  });
});
