const DEFAULT_ROKN_API_URL =
  'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/';

const canonicalApiBase = (configured: string) => {
  try {
    const url = new URL(configured);
    if (!['http:', 'https:'].includes(url.protocol)) return null;

    // A release accepts either an origin or an API URL. If an endpoint was
    // accidentally pasted into EXPO_PUBLIC_API_URL, keeping its suffix makes
    // Axios resolve every relative request underneath that endpoint. Locate
    // the first API segment and rebuild the one contract this client speaks.
    const segments = url.pathname.split('/').filter(Boolean);
    const apiIndex = segments.findIndex(
      segment => segment.toLowerCase() === 'api',
    );
    const deploymentPrefix = apiIndex >= 0 ? segments.slice(0, apiIndex) : [];
    const path = [...deploymentPrefix, 'api', 'v1'].join('/');
    return `${url.origin}/${path}/`;
  } catch {
    return null;
  }
};

/**
 * Expo accepts either the public origin or the full API base in release
 * environments. Keep that deployment detail out of screens and services so a
 * bare Laravel Cloud origin cannot silently send every request to `/`.
 */
export const normalizeRoknApiUrl = (value?: string) => {
  const configured = value?.trim() || DEFAULT_ROKN_API_URL;
  return (
    canonicalApiBase(configured) ?? canonicalApiBase(DEFAULT_ROKN_API_URL)!
  );
};

export const roknApiUrl = normalizeRoknApiUrl(process.env.EXPO_PUBLIC_API_URL);
