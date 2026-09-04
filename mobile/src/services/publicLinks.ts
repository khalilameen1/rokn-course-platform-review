import {roknApiUrl} from '../constants/apiBaseUrl';

const publicWebBaseUrl = roknApiUrl.replace(/api(?:\/v1)?\/?$/i, '');
const configuredPublicBase = String(
  process.env.EXPO_PUBLIC_PORTFOLIO_URL || 'https://rokn.app',
)
  .trim()
  .replace(/\/$/, '');
const portfolioBaseUrl = /^https:\/\/(?:www\.)?rokn\.app$/i.test(
  configuredPublicBase,
)
  ? configuredPublicBase
  : 'https://rokn.app';
const trustedPublicHosts = new Set(['rokn.app', 'www.rokn.app']);
const certificateCredentialPattern =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
try {
  // Test deployments legitimately issue unlisted links on the exact API
  // origin until the canonical domain is attached. Trust that one configured
  // origin as a unit; never widen this to arbitrary Laravel Cloud hosts.
  trustedPublicHosts.add(new URL(publicWebBaseUrl).hostname.toLowerCase());
} catch {
  // Release configuration validation reports a malformed API URL separately.
}

export const accountDeletionUrl =
  process.env.EXPO_PUBLIC_ACCOUNT_DELETION_URL?.trim() ||
  `${publicWebBaseUrl}account-deletion`;

// Kept as a contract export for older callers and tests. The policy remains
// inside the unified legal pages and is intentionally not a settings row.
export const returnsPolicyUrl = `${publicWebBaseUrl}returns-policy`;

export const portfolioUrlFor = (username: string) =>
  `${portfolioBaseUrl}/@${encodeURIComponent(username)}`;

/** Portfolio links are server-issued unlisted capabilities, never usernames. */
export const trustedPortfolioShareUrl = (value: unknown) => {
  if (typeof value !== 'string' || !value.trim()) return null;
  try {
    const url = new URL(value.trim());
    const hostname = url.hostname.toLowerCase();
    const token = decodeURIComponent(url.pathname.slice(2));
    if (
      url.protocol !== 'https:' ||
      url.username ||
      url.password ||
      url.port ||
      url.search ||
      url.hash ||
      !trustedPublicHosts.has(hostname) ||
      !url.pathname.startsWith('/@') ||
      !/^rokn-(?:[a-z0-9]{24}|[a-f0-9]{32})$/.test(token) ||
      url.pathname !== `/@${encodeURIComponent(token)}`
    ) {
      return null;
    }
    return url.toString();
  } catch {
    return null;
  }
};

/** Certificate destinations are server data, not trusted navigation input. */
export const trustedCertificateVerificationUrl = (
  value: unknown,
  credential: string,
) => {
  const normalizedCredential = credential.trim();
  if (
    typeof value !== 'string' ||
    !value.trim() ||
    !certificateCredentialPattern.test(normalizedCredential)
  ) {
    return null;
  }
  try {
    const url = new URL(value.trim());
    const hostname = url.hostname.toLowerCase();
    if (
      url.protocol !== 'https:' ||
      url.username ||
      url.password ||
      url.port ||
      url.search ||
      url.hash ||
      !trustedPublicHosts.has(hostname) ||
      url.pathname !== `/c/${encodeURIComponent(normalizedCredential)}`
    ) {
      return null;
    }
    return url.toString();
  } catch {
    return null;
  }
};

/** Certificate files must stay on the same public origin and credential. */
export const trustedCertificateFileUrl = (
  value: unknown,
  credential: string,
  kind: 'artifact' | 'download',
) => {
  const normalizedCredential = credential.trim();
  if (
    typeof value !== 'string' ||
    !value.trim() ||
    !certificateCredentialPattern.test(normalizedCredential)
  ) {
    return null;
  }
  try {
    const url = new URL(value.trim());
    const hostname = url.hostname.toLowerCase();
    if (
      url.protocol !== 'https:' ||
      url.username ||
      url.password ||
      url.port ||
      url.search ||
      url.hash ||
      !trustedPublicHosts.has(hostname) ||
      url.pathname !== `/c/${encodeURIComponent(normalizedCredential)}/${kind}`
    ) {
      return null;
    }
    return url.toString();
  } catch {
    return null;
  }
};

export const configuredAppStoreUrl = () =>
  process.env.EXPO_PUBLIC_APP_STORE_URL?.trim() || '';
