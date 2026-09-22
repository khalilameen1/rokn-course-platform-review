import React, {useEffect, useState} from 'react';
import {useAppForegroundState} from '../hooks/useAppActiveState';
import {
  getPublicAppSettings,
  type AppArtworkUrls,
} from '../services/publicAppSettings';
import {ArtworkContext} from './ui/AppArtwork';

/** Network lifecycle belongs to the app root, not individual images. */
export const AppArtworkProvider = ({children}: React.PropsWithChildren) => {
  const active = useAppForegroundState();
  const [artwork, setArtwork] = useState<AppArtworkUrls>({});
  useEffect(() => {
    if (!active) return;
    let mounted = true;
    void getPublicAppSettings()
      .then(settings => {
        if (mounted) setArtwork(settings.artwork || {});
      })
      .catch(() => undefined);
    return () => {
      mounted = false;
    };
  }, [active]);
  return (
    <ArtworkContext.Provider value={artwork}>
      {children}
    </ArtworkContext.Provider>
  );
};
