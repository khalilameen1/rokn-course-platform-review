export const numericRouteId = (value: string, field: string): string => {
  const normalized = String(value).trim();
  if (!/^\d+$/.test(normalized) || Number(normalized) <= 0) {
    throw new Error(`INVALID_${field}_ID`);
  }
  return normalized;
};

export const nonNegativeNumberOr = (
  value: unknown,
  fallback: number,
): number => {
  if (value === null || value === undefined || value === '') return fallback;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.max(0, parsed) : fallback;
};

export const displayText = (value: unknown): string =>
  typeof value === 'string' || typeof value === 'number'
    ? String(value).trim()
    : '';

export const displayImageUrl = (value: unknown): string | undefined => {
  const candidate = displayText(value);
  const path = candidate.split(/[?#]/, 1)[0];
  return /^(?:https?:\/\/|file:\/\/|content:\/\/)/i.test(candidate) &&
    !/\.svg$/i.test(path)
    ? candidate
    : undefined;
};

export const stableCourseContentId = (value: unknown): string => {
  const id = String(value ?? '').trim();
  return /^[1-9]\d*$/.test(id) ? id : '';
};

export const catalogueMetric = (value: unknown): number | undefined => {
  if (value === null || value === undefined || value === '') return undefined;
  const number = Number(value);
  return Number.isFinite(number) && number >= 0 ? number : undefined;
};
