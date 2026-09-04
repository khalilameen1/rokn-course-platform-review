import type {CoinPackage} from './coinPackageMapper';
import {mapCoinPackages} from './coinPackageMapper';

export const numericCourseId = (courseId: string): number => {
  const parsed = Number(courseId);
  if (!Number.isSafeInteger(parsed) || parsed <= 0) {
    throw new Error('API_CONTRACT_INVALID_COURSE_ID');
  }
  return parsed;
};

export const canonicalAccessPlanCode = (accessPlanCode: string): string => {
  const normalized = String(accessPlanCode || '')
    .trim()
    .toLowerCase();
  if (!/^[a-z0-9][a-z0-9_-]{0,99}$/.test(normalized)) {
    throw new Error('API_CONTRACT_INVALID_ACCESS_PLAN_CODE');
  }
  return normalized;
};

export const mapFinancialPackages = (value: unknown): CoinPackage[] =>
  mapCoinPackages(value, 'API_CONTRACT_INVALID_RECOMMENDED_PACKAGES');
