/**
 * @format
 */

import React from 'react';
import ReactTestRenderer from 'react-test-renderer';
import {SafeAreaProvider} from 'react-native-safe-area-context';

jest.mock('../src/screens/AppInitializer', () => () => null);
jest.mock('../src/localization/i18n.config', () => ({
  __esModule: true,
  default: {changeLanguage: jest.fn()},
}));
jest.mock('../src/services/productAnalytics', () => ({
  flushProductEvents: jest.fn().mockResolvedValue(undefined),
  trackProductEvent: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  bootstrapOperationalDiagnostics: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../src/services/productFeatures', () => ({
  bootstrapProductFeatures: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('react-redux', () => ({
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({settings: {language: 'ar'}}),
}));

import App from '../App';

test('renders correctly', async () => {
  let renderer: ReactTestRenderer.ReactTestRenderer;
  await ReactTestRenderer.act(() => {
    renderer = ReactTestRenderer.create(
      <SafeAreaProvider
        initialMetrics={{
          frame: {x: 0, y: 0, width: 390, height: 844},
          insets: {top: 44, right: 0, bottom: 34, left: 0},
        }}>
        <App />
      </SafeAreaProvider>,
    );
  });
  await ReactTestRenderer.act(() => {
    renderer.unmount();
  });
});
