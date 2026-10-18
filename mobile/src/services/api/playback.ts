import {publicRequest} from '../../constants/api';

/** Playback manifest and watch-evidence requests. */
export const postWatchHistorySample = (payload: Record<string, unknown>) =>
  publicRequest.post('user/watch-history', payload);

export const requestPlaybackManifest = (
  lessonId: string | number,
  body: Record<string, unknown>,
) =>
  publicRequest.post(`lessons/${lessonId}/playback-manifest`, body, {
    timeout: 8000,
  });
