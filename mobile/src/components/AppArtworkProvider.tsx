import React, {useEffect, useState} from 'react';
import {useAppForegroundState} from '../hooks/useAppActiveState';
import {getPublicAppSettings} from '../services/publicAppSettings';
import {ArtworkContext, type AppArtworkState} from './ui/AppArtwork';

/** Network lifecycle belongs to the app root, not individual images. */
export const AppArtworkProvider = ({children}: React.PropsWithChildren) => {
  const active = useAppForegroundState();
  const [artwork, setArtwork] = useState<AppArtworkState>({urls: {}});
  useEffect(() => {
    if (!active) return;
    let mounted = true;
    void getPublicAppSettings()
      .then(settings => {
        if (mounted)
          setArtwork({
            urls: settings.artwork || {},
            defaults: settings.artwork_defaults,
          });
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
