import {useEffect, useMemo, useState} from 'react';
import {NativeModules, Platform} from 'react-native';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import type {
  CourseFeedItem,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import {manifestRefreshDelayMs} from '../../components/VideoPlayer/playbackTelemetry';
import {selectVideoSource} from '../../components/VideoPlayer/video/policy';
import {sha256Hex} from '../../utils/sha256';

type NativeEntry = {
  uri: string;
  index: number;
  type: string;
  expiresAt: number;
};
type NativePreloader = {
  setWindow: (
    owner: string,
    command: number,
    currentIndex: number,
    items: NativeEntry[],
    enabled: boolean,
  ) => Promise<boolean>;
  clear: (owner: string, command: number) => void;
};
const bridge =
  Platform.OS === 'android'
    ? (NativeModules.RoknReelPreload as NativePreloader | undefined)
    : undefined;
let ownerSerial = 0;
let commandSerial = 0;

/** Protected media only; no catalogue URI is promoted into a playback grant. */
export function buildNativeReelWindow(
  feedItems: CourseFeedItem[],
  currentIndex: number,
  quality: VideoQuality,
): NativeEntry[] {
  const entries: NativeEntry[] = [];
  for (const index of [currentIndex, currentIndex + 1, currentIndex - 1]) {
    const item = feedItems[index];
    if (
      item?.type !== 'reel' ||
      item.reel.isLocked ||
      !item.reel.playbackSessionId ||
      manifestRefreshDelayMs(item.reel.playbackExpiresAt) === 0
    )
      continue;
    const selection = selectVideoSource({
      effectiveQuality: quality,
      qualitySources: item.reel.qualitySources,
      videoUrl: item.reel.videoUrl,
      usingFallback: false,
    });
    const expiresAt = Date.parse(item.reel.playbackExpiresAt || '');
    if (
      !selection.source.uri.startsWith('https://') ||
      !selection.sourceType ||
      selection.unsupportedSource ||
      !Number.isFinite(expiresAt) ||
      entries.some(entry => entry.uri === selection.source.uri)
    )
      continue;
    entries.push({
      uri: selection.source.uri,
      type: selection.sourceType,
      index,
      expiresAt,
    });
  }
  return entries;
}

export const useReelsNativePreloading = ({
  scopeKey,
  active,
  preloadEnabled,
  feedItems,
  currentIndex,
  quality,
}: {
  scopeKey: string;
  active: boolean;
  preloadEnabled: boolean;
  feedItems: CourseFeedItem[];
  currentIndex: number;
  quality: VideoQuality;
}) => {
  // Opaque route lifetime, no account token or signed URI is put in metadata.
  const owner = useMemo(
    () =>
      `rokn-reels:${sha256Hex(scopeKey).slice(
        0,
        12,
      )}:${Date.now()}:${++ownerSerial}`,
    [scopeKey],
  );
  const entries = useMemo(
    () => buildNativeReelWindow(feedItems, currentIndex, quality),
    [feedItems, currentIndex, quality],
  );
  const signature = JSON.stringify(entries);
  const [acknowledged, setAcknowledged] = useState<{
    owner: string;
    uris: string[];
  }>({owner: '', uris: []});
  const [failedOwner, setFailedOwner] = useState('');
  const currentUri = entries.find(entry => entry.index === currentIndex)?.uri;
  const requested = Boolean(bridge && active && currentUri);

  useEffect(
    () => () => {
      bridge?.clear(owner, ++commandSerial);
    },
    [owner],
  );

  useEffect(() => {
    if (!bridge) return;
    if (!active || !currentUri) {
      bridge.clear(owner, ++commandSerial);
      setAcknowledged({owner: '', uris: []});
      return;
    }
    let live = true;
    const command = ++commandSerial;
    void (async () => {
      const boundary = await captureAccountSessionBoundary();
      if (!live) return;
      assertAccountSessionBoundary(boundary);
      const accepted = await bridge.setWindow(
        owner,
        command,
        currentIndex,
        JSON.parse(signature) as NativeEntry[],
        preloadEnabled,
      );
      assertAccountSessionBoundary(boundary);
      if (!live || !accepted) return;
      setAcknowledged({
        owner,
        uris: (JSON.parse(signature) as NativeEntry[]).map(item => item.uri),
      });
      setFailedOwner('');
    })().catch(() => {
      if (!live) return;
      bridge.clear(owner, ++commandSerial);
      setFailedOwner(owner);
    });
    return () => {
      live = false;
    };
  }, [active, currentIndex, currentUri, owner, preloadEnabled, signature]);

  const registered =
    acknowledged.owner === owner &&
    acknowledged.uris.includes(currentUri || '');
  return {
    nativePreloadOwner:
      requested && registered && failedOwner !== owner ? owner : undefined,
    nativePreloadPending: requested && !registered && failedOwner !== owner,
    // Even a bridge failure must not bring back another adjacent decoder.
    nativePreloadMode: requested,
  };
};
