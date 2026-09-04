/**
 * @format
 */

import {registerRootComponent} from 'expo';
import React from 'react';
import {I18nManager} from 'react-native';
import {Provider} from 'react-redux';
import {store} from './src/store/store';
import {GestureHandlerRootView} from 'react-native-gesture-handler';
import {SafeAreaProvider} from 'react-native-safe-area-context';
import {BottomSheetModalProvider} from '@gorhom/bottom-sheet';
import App from './App';
import AppErrorBoundary from './src/components/ui/AppErrorBoundary';
import {installGlobalErrorReporting} from './src/services/operationalTelemetry';
import {initializeSentry} from './src/services/sentryTelemetry';

// Rokn's shipping interface is Arabic-first. Apply RTL before the first React
// tree is mounted so navigation, lists and touch targets all agree on direction.
I18nManager.allowRTL(true);
I18nManager.forceRTL(true);
initializeSentry();
installGlobalErrorReporting();

const RNapp = () => {
  return (
    <Provider store={store}>
      <AppErrorBoundary>
        <GestureHandlerRootView style={{flex: 1}}>
          <SafeAreaProvider>
            <BottomSheetModalProvider>
              <App />
            </BottomSheetModalProvider>
          </SafeAreaProvider>
        </GestureHandlerRootView>
      </AppErrorBoundary>
    </Provider>
  );
};
registerRootComponent(RNapp);
