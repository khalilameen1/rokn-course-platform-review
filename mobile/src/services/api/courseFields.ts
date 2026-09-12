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

// Social-proof fields must be actual numeric values, not JavaScript's
// coercions of booleans/arrays into invented student or rating counts.
const socialProofNumber = (value: unknown): number | undefined => {
  if (
    typeof value !== 'number' &&
    (typeof value !== 'string' || value.trim() === '')
  ) {
    return undefined;
  }
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
};

export const courseCount = (value: unknown): number | undefined => {
  const parsed = socialProofNumber(value);
  return parsed !== undefined && Number.isSafeInteger(parsed) && parsed >= 0
    ? parsed
    : undefined;
};

export const courseAverageRating = (value: unknown): number | undefined => {
  const parsed = socialProofNumber(value);
  return parsed !== undefined && parsed >= 1 && parsed <= 5
    ? parsed
    : undefined;
};
