const normalizedVideoUri = (value?: string) =>
  String(value || '')
    .trim()
    .replace(/&amp;/gi, '&');

export type VideoSourceReachability =
  | 'reachable'
  | 'expired'
  | 'missing'
  | 'server'
  | 'offline'
  | 'timeout';

/** HTML page URLs are invalid media inputs for ExoPlayer and AVPlayer. */
export const isUnsupportedVideoPageUri = (value?: string) => {
  const uri = normalizedVideoUri(value).replace(/^\/\//, 'https://');
  const host = uri.match(/^https?:\/\/([^/:?#]+)/i)?.[1]?.toLowerCase();
  if (!host) return false;
  return (
    host === 'youtu.be' ||
    host === 'youtube.com' ||
    host.endsWith('.youtube.com') ||
    host === 'youtube-nocookie.com' ||
    host.endsWith('.youtube-nocookie.com')
  );
};

/**
 * A very small diagnostic request used only after playback has already failed.
 * Distinguishes device connectivity from an expired link, missing object,
 * provider failure or an HTML suspension page after playback already failed.
 */
export const probeVideoSource = async (
  value?: string,
  timeoutMs = 4500,
): Promise<VideoSourceReachability> => {
  const uri = normalizedVideoUri(value).replace(/^\/\//, 'https://');
  if (!/^https?:\/\//i.test(uri)) {
    return 'offline';
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(uri, {
      method: 'HEAD',
      headers: {Range: 'bytes=0-1'},
      signal: controller.signal,
    });
    if (response.status === 401 || response.status === 403) return 'expired';
    if (response.status === 404 || response.status === 410) return 'missing';
    if (response.status >= 500) return 'server';
    const contentType = String(response.headers.get('content-type') || '')
      .toLowerCase();
    // A suspended or misrouted CDN hostname often answers 200 with an HTML
    // holding page. Treat that as provider failure, not source reachability.
    if (contentType.includes('text/html')) return 'server';
    return 'reachable';
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return controller.signal.aborted || /abort|timeout/i.test(message)
      ? 'timeout'
      : 'offline';
  } finally {
    clearTimeout(timer);
  }
};
