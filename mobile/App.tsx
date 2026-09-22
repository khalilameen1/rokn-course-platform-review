import React, {useEffect} from 'react';
import './src/localization/i18n.config';
import AppInitializer from './src/screens/AppInitializer';
import {AttachmentDownloadNoticeHost} from './src/components/VideoPlayer/AttachmentDownloadNoticeHost';
import {
  flushProductEvents,
  trackProductEvent,
} from './src/services/productAnalytics';
import {bootstrapOperationalDiagnostics} from './src/services/operationalTelemetry';
import {bootstrapProductFeatures} from './src/services/productFeatures';
import {AppArtworkProvider} from './src/components/AppArtworkProvider';

const App = () => {
  useEffect(() => {
    void trackProductEvent({event_name: 'app_opened', screen_key: 'app'}).catch(
      () => undefined,
    );
    void flushProductEvents().catch(() => undefined);
    void bootstrapOperationalDiagnostics().catch(() => undefined);
    void bootstrapProductFeatures().catch(() => undefined);
  }, []);
  return (
    <AppArtworkProvider>
      <AppInitializer />
      <AttachmentDownloadNoticeHost />
    </AppArtworkProvider>
  );
};
export default App;
