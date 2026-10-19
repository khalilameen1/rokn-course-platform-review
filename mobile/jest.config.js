module.exports = {
  preset: 'react-native',
  cacheDirectory: '<rootDir>/.cache/jest',
  setupFiles: ['./jest.setup.js'],
  moduleNameMapper: {
    // Metro resolves tus-js-client through its `browser` field (the
    // XMLHttpRequest stack the app runs); Jest would take `main` (Node http).
    // Upload tests fake XMLHttpRequest against the same build as the app.
    '^tus-js-client$':
      '<rootDir>/node_modules/tus-js-client/lib.es5/browser/index.js',
  },
  // The desktop recorder owns a separate `node:test` suite. Let Jest run only
  // the React Native app tests instead of treating those files as empty suites.
  testPathIgnorePatterns: [
    '/node_modules/',
    '/scripts/tests/',
    '/tools/rokn-recorder/test/',
    '/.codex-tmp/',
    // Shared render helpers (QueryClientProvider wrapper), not suites.
    '<rootDir>/__tests__/helpers/',
    // Local toolchain binaries and npm's own command named test.js are not
    // application suites. Keep all app tests, including failing ones, in scope.
    '<rootDir>/\\.tools/',
  ],
  // Expo's Babel preset rewrites EXPO_PUBLIC_* reads to expo/virtual/env. Expo
  // ships that module as ESM, so the React Native Jest preset must transform it
  // instead of trying to execute the raw `export` in CommonJS mode.
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|@react-navigation/.*|@reduxjs/.*|immer|redux|react-native-(?:gesture-handler|safe-area-context|screens)|expo|@expo/.*)/)',
  ],
};
