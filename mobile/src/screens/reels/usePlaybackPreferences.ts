import {useCallback, useEffect, useRef, useState} from 'react';
import type {VideoQuality} from '../../components/VideoPlayer/types';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
} from '../../constants/helpers';
import {
  getProfile,
  hasSession,
  updatePlaybackPreferences,
} from '../../services/roknApi';

export const usePlaybackPreferences = (
  serverSession: boolean | null,
  accountIdentity: string,
) => {
  const [playbackSpeed, setPlaybackSpeed] = useState(1);
  const playbackSpeedRef = useRef(1);
  const [selectedQuality, setSelectedQuality] = useState<VideoQuality>('auto');
  const [dataSaver, setDataSaver] = useState(false);
  const [playbackPreferencesReady, setPlaybackPreferencesReady] =
    useState(false);
  playbackSpeedRef.current = playbackSpeed;

  useEffect(() => {
    let active = true;
    // A stack route may survive logout and direct account replacement. Do not
    // play the new owner's course with the previous owner's speed/data policy
    // while this account's durable preferences are still being resolved.
    playbackSpeedRef.current = 1;
    setPlaybackSpeed(1);
    setSelectedQuality('auto');
    setDataSaver(false);
    setPlaybackPreferencesReady(false);
    const applyResolvedPreferences = (
      savedQuality: unknown,
      savedSpeed: unknown,
    ) => {
      if (!active) return;
      const dataSaverPreference =
        savedQuality === 'data_saver' || savedQuality === 'توفير البيانات';
      setDataSaver(dataSaverPreference);
      const normalizedQuality = dataSaverPreference
        ? '360p'
        : savedQuality === 'تلقائي'
        ? 'auto'
        : savedQuality;
      if (
        ['auto', '1080p', '720p', '480p', '360p'].includes(
          String(normalizedQuality),
        )
      ) {
        setSelectedQuality(normalizedQuality as VideoQuality);
      }
      const normalizedSpeed = Number(savedSpeed);
      if ([0.75, 1, 1.25, 1.5, 2].includes(normalizedSpeed)) {
        setPlaybackSpeed(normalizedSpeed);
      }
    };
    void (async () => {
      const boundary = await captureAccountSessionBoundary();
      const qualityKey = await accountScopedStorageKey(
        'VIDEO_QUALITY',
        boundary,
      );
      const speedKey = await accountScopedStorageKey(
        'VIDEO_PLAYBACK_SPEED',
        boundary,
      );
      let [savedQuality, savedSpeed] = await Promise.all([
        getItem(qualityKey),
        getItem(speedKey),
      ]);
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      applyResolvedPreferences(savedQuality, savedSpeed);
      // Playback can start from the durable device preference (or the reset
      // defaults) now. Profile reconciliation is useful but must never hold
      // the first signed manifest behind a separate network request.
      setPlaybackPreferencesReady(true);
      const profile = (await hasSession())
        ? await getProfile(boundary).catch(() => null)
        : null;
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      if (profile) {
        savedQuality = profile.videoQualityPreference;
        savedSpeed = profile.playbackSpeed;
        await Promise.all([
          saveItem(qualityKey, savedQuality),
          saveItem(speedKey, savedSpeed),
        ]);
        assertAccountSessionBoundary(boundary);
        applyResolvedPreferences(savedQuality, savedSpeed);
      }
    })()
      .catch(() => undefined)
      .finally(() => {
        if (active) setPlaybackPreferencesReady(true);
      });
    return () => {
      active = false;
    };
  }, [accountIdentity]);

  const changeQuality = useCallback(
    (quality: VideoQuality) => {
      setDataSaver(false);
      setSelectedQuality(quality);
      void captureAccountSessionBoundary()
        .then(async boundary => {
          await saveItem(
            await accountScopedStorageKey('VIDEO_QUALITY', boundary),
            quality,
          );
          assertAccountSessionBoundary(boundary);
          if (serverSession) {
            await updatePlaybackPreferences(
              {videoQualityPreference: quality},
              boundary,
            );
            assertAccountSessionBoundary(boundary);
          }
        })
        .catch(() => undefined);
    },
    [serverSession],
  );

  const changePlaybackSpeed = useCallback(
    (speed: number) => {
      setPlaybackSpeed(speed);
      void captureAccountSessionBoundary()
        .then(async boundary => {
          await saveItem(
            await accountScopedStorageKey('VIDEO_PLAYBACK_SPEED', boundary),
            speed,
          );
          assertAccountSessionBoundary(boundary);
          if (serverSession) {
            await updatePlaybackPreferences({playbackSpeed: speed}, boundary);
            assertAccountSessionBoundary(boundary);
          }
        })
        .catch(() => undefined);
    },
    [serverSession],
  );

  const getPlaybackSpeed = useCallback(() => playbackSpeedRef.current, []);

  return {
    autoplay: true,
    changePlaybackSpeed,
    changeQuality,
    dataSaver,
    getPlaybackSpeed,
    playbackPreferencesReady,
    playbackSpeed,
    selectedQuality,
  };
};
