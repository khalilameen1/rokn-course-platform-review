export type APIError = {
  message: string;
  code: number;
  errors: object;
  diagnostic_code?: string;
  need_activation?: unknown;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

export const InternalError: APIError = {
  message: 'تعذّر إكمال الطلب\nحاول مرة أخرى',
  errors: {},
  code: -500,
  diagnostic_code: 'INTERNAL_REQUEST_ERROR',
};

export const getExceptionPayload = (error: unknown): APIError => {
  if (!isRecord(error)) return InternalError;
  const response = isRecord(error.response) ? error.response : undefined;
  const data = response?.data ?? error.data;
  if (
    !isRecord(data) ||
    typeof data.message !== 'string' ||
    typeof data.status !== 'number'
  ) {
    return InternalError;
  }

  return {
    message: /[\u0600-\u06ff]/u.test(data.message)
      ? data.message
      : InternalError.message,
    errors: isRecord(data.errors) ? data.errors : {},
    code: data.status,
    diagnostic_code:
      typeof data.code === 'string' && data.code.trim()
        ? data.code.trim()
        : 'API_REQUEST_REJECTED',
    need_activation: data.need_activation,
  };
};
