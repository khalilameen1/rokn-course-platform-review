import {useCallback, useEffect, useRef, useState} from 'react';
import type {VideoQuality} from '../../components/VideoPlayer/types';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
} from '../../constants/helpers';
import {getProfile, hasSession} from '../../services/roknApi';
import {settleWithin} from '../../utils/settleWithin';
import {
  playbackPreferenceReadIsCurrent,
  playbackPreferenceVersions,
  withAccountPreferenceWrite,
} from '../../services/accountPreferenceWrites';
import {
  flushPlaybackPreferenceWrites,
  readPendingPlaybackPreferences,
  savePlaybackPreferencePatch,
} from '../../services/playbackPreferenceSync';

export const usePlaybackPreferences = (accountIdentity: string) => {
  const [playbackSpeed, setPlaybackSpeed] = useState(1);
  const playbackSpeedRef = useRef(1);
  const preferenceWriteEpoch = useRef({quality: 0, speed: 0});
  const [selectedQuality, setSelectedQuality] = useState<VideoQuality>('auto');
  const [dataSaver, setDataSaver] = useState(false);
  const [playbackPreferencesReady, setPlaybackPreferencesReady] =
    useState(false);
  playbackSpeedRef.current = playbackSpeed;

  useEffect(() => {
    let active = true;
    preferenceWriteEpoch.current.quality += 1;
    preferenceWriteEpoch.current.speed += 1;
    const readEpoch = {...preferenceWriteEpoch.current};
    const ownsPreferenceRead = (field: 'quality' | 'speed') =>
      active && preferenceWriteEpoch.current[field] === readEpoch[field];
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
      fields = {quality: true, speed: true},
    ) => {
      const dataSaverPreference =
        savedQuality === 'data_saver' || savedQuality === 'توفير البيانات';
      const normalizedQuality = dataSaverPreference
        ? '360p'
        : savedQuality === 'تلقائي'
        ? 'auto'
        : savedQuality ?? 'auto';
      if (fields.quality && ownsPreferenceRead('quality')) {
        setDataSaver(dataSaverPreference);
      }
      if (
        fields.quality &&
        ownsPreferenceRead('quality') &&
        ['auto', '1080p', '720p', '480p', '360p'].includes(
          String(normalizedQuality),
        )
      ) {
        setSelectedQuality(normalizedQuality as VideoQuality);
      }
      const normalizedSpeed = Number(savedSpeed);
      if (
        fields.speed &&
        ownsPreferenceRead('speed') &&
        [0.75, 1, 1.25, 1.5, 2].includes(normalizedSpeed)
      ) {
        setPlaybackSpeed(normalizedSpeed);
      }
    };
    const durablePreferenceRead = (async () => {
      const boundary = await captureAccountSessionBoundary();
      const readVersions = playbackPreferenceVersions(boundary);
      const [qualityKey, speedKey] = await Promise.all([
        accountScopedStorageKey('VIDEO_QUALITY', boundary),
        accountScopedStorageKey('VIDEO_PLAYBACK_SPEED', boundary),
      ]);
      const [cachedQuality, cachedSpeed, pending] = await Promise.all([
        getItem(qualityKey),
        getItem(speedKey),
        readPendingPlaybackPreferences(boundary),
      ]);
      assertAccountSessionBoundary(boundary);
      return {
        boundary,
        qualityKey,
        speedKey,
        savedQuality: pending.videoQualityPreference ?? cachedQuality,
        savedSpeed: pending.playbackSpeed ?? cachedSpeed,
        readVersions,
      };
    })();
    const currentReadFields = (
      read: Awaited<typeof durablePreferenceRead>,
    ) => ({
      quality: playbackPreferenceReadIsCurrent(
        read.boundary,
        read.readVersions,
        'quality',
      ),
      speed: playbackPreferenceReadIsCurrent(
        read.boundary,
        read.readVersions,
        'speed',
      ),
    });
    void (async () => {
      const timely = await settleWithin(durablePreferenceRead, null, 250);
      if (!active) return;
      if (timely) {
        applyResolvedPreferences(
          timely.savedQuality,
          timely.savedSpeed,
          currentReadFields(timely),
        );
      } else {
        // Unknown data policy must not allow aggressive preloading while a
        // native read is stuck. Start the current reel conservatively instead
        // of holding its signed manifest indefinitely behind device storage.
        applyResolvedPreferences('data_saver', 1);
      }
      setPlaybackPreferencesReady(true);
      // A late read may restore the real preference, but never undo a learner
      // choice made after entry or leak an old account's preference.
      const local = timely ?? (await durablePreferenceRead);
      if (!active) return;
      const {boundary, qualityKey, speedKey, readVersions} = local;
      if (!timely) {
        applyResolvedPreferences(
          local.savedQuality,
          local.savedSpeed,
          currentReadFields(local),
        );
      }
      const pendingAtProfileRead = await readPendingPlaybackPreferences(
        boundary,
      );
      void flushPlaybackPreferenceWrites(boundary).catch(() => undefined);
      const profile = (await hasSession())
        ? await getProfile(boundary).catch(() => null)
        : null;
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      if (profile) {
        const savedQuality =
          local.savedQuality === 'data_saver' &&
          profile.videoQualityPreference === '360p'
            ? 'data_saver'
            : profile.videoQualityPreference;
        const savedSpeed = profile.playbackSpeed;
        const accepted = {quality: false, speed: false};
        await withAccountPreferenceWrite(boundary, async () => {
          const pending = await readPendingPlaybackPreferences(boundary);
          // Recheck inside the queue: a manual write may have been admitted
          // while the profile request or an earlier native write was pending.
          if (
            ownsPreferenceRead('quality') &&
            pendingAtProfileRead.videoQualityPreference === undefined &&
            pending.videoQualityPreference === undefined &&
            playbackPreferenceReadIsCurrent(boundary, readVersions, 'quality')
          ) {
            await saveItem(qualityKey, savedQuality);
            accepted.quality = true;
          }
          if (
            ownsPreferenceRead('speed') &&
            pendingAtProfileRead.playbackSpeed === undefined &&
            pending.playbackSpeed === undefined &&
            playbackPreferenceReadIsCurrent(boundary, readVersions, 'speed')
          ) {
            await saveItem(speedKey, savedSpeed);
            accepted.speed = true;
          }
        });
        applyResolvedPreferences(savedQuality, savedSpeed, accepted);
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

  const changeQuality = useCallback((quality: VideoQuality) => {
    preferenceWriteEpoch.current.quality += 1;
    setDataSaver(false);
    setSelectedQuality(quality);
    void captureAccountSessionBoundary()
      .then(boundary =>
        savePlaybackPreferencePatch(
          {videoQualityPreference: quality},
          boundary,
        ),
      )
      .catch(() => undefined);
  }, []);

  const changePlaybackSpeed = useCallback((speed: number) => {
    preferenceWriteEpoch.current.speed += 1;
    setPlaybackSpeed(speed);
    void captureAccountSessionBoundary()
      .then(boundary =>
        savePlaybackPreferencePatch({playbackSpeed: speed}, boundary),
      )
      .catch(() => undefined);
  }, []);

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
