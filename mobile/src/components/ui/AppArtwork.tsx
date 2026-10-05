import React, {createContext, useContext, useEffect, useState} from 'react';
import {type ImageProps} from 'react-native';
import {RasterImage as Image} from './RasterImage';
import type {AppArtworkUrls} from '../../services/publicAppSettings';

export type AppArtworkState = {
  urls: AppArtworkUrls;
  defaults?: AppArtworkUrls;
};
export const ArtworkContext = createContext<AppArtworkState>({urls: {}});

const bundled = {
  coin: require('../../assets/images/coins/rokn-coin-minted.png'),
  coin_stack: require('../../assets/images/coins/rokn-coin-stack-3d-alpha.png'),
  badge_junior: require('../../assets/images/badges/junior-printed.png'),
  badge_mid: require('../../assets/images/badges/mid-level-printed.png'),
  badge_senior: require('../../assets/images/badges/senior-printed.png'),
};

export const levelArtworkKey = (order = 1): keyof AppArtworkUrls =>
  order <= 1 ? 'badge_junior' : order === 2 ? 'badge_mid' : 'badge_senior';

/** Per-level upload, dashboard default, then a bundled offline fallback. */
export const AppArtwork = ({
  asset,
  uri,
  onError,
  style,
  defaultArtworkStyle,
  ...props
}: Omit<ImageProps, 'source'> & {
  asset: keyof AppArtworkUrls;
  uri?: string;
  /** Optional framing of this known padded shipped image, never an upload. */
  defaultArtworkStyle?: ImageProps['style'];
}) => {
  const artwork = useContext(ArtworkContext);
  const configured = artwork.urls[asset];
  const [failedUrls, setFailedUrls] = useState<string[]>([]);
  useEffect(() => {
    setFailedUrls([]);
  }, [uri, configured]);
  const remote = [uri, configured].find(
    url => url && !failedUrls.includes(url),
  );
  const isDefaultArtwork = !remote || remote === artwork.defaults?.[asset];
  return (
    <Image
      key={remote || asset}
      defaultSource={bundled[asset]}
      resizeMethod="resize"
      fadeDuration={0}
      {...props}
      style={
        isDefaultArtwork && defaultArtworkStyle
          ? [style, defaultArtworkStyle]
          : style
      }
      source={remote ? {uri: remote} : bundled[asset]}
      onError={event => {
        if (remote) setFailedUrls(previous => [...previous, remote]);
        onError?.(event);
      }}
    />
  );
};
