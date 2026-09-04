import {remainingServerMilliseconds} from '../utils/serverClock';

export const usablePortfolioMediaUrl = (
  value: unknown,
  expiresAt?: unknown,
): string | undefined => {
  const url = typeof value === 'string' ? value.trim() : '';
  if (!url) return undefined;
  const expiry = typeof expiresAt === 'string' ? expiresAt.trim() : '';
  const remaining = remainingServerMilliseconds(expiry || undefined);
  return remaining !== null && remaining <= 0 ? undefined : url;
};

export type PortfolioMediaFailureDisposition =
  | 'discard_project'
  | 'discard_file'
  | 'retry_project';

export const portfolioMediaFailureDisposition = (
  status: number,
): PortfolioMediaFailureDisposition => {
  if (status === 404) return 'discard_project';
  if ([400, 413, 415, 422].includes(status)) return 'discard_file';
  return 'retry_project';
};
