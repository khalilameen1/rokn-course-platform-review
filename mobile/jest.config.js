module.exports = {
  preset: 'react-native',
  cacheDirectory: '<rootDir>/.cache/jest',
  setupFiles: ['./jest.setup.js'],
  // The desktop recorder owns a separate `node:test` suite. Let Jest run only
  // the React Native app tests instead of treating those files as empty suites.
  testPathIgnorePatterns: [
    '/node_modules/',
    '/scripts/tests/',
    '/tools/rokn-recorder/test/',
    '/.codex-tmp/',
  ],
  // Expo's Babel preset rewrites EXPO_PUBLIC_* reads to expo/virtual/env. Expo
  // ships that module as ESM, so the React Native Jest preset must transform it
  // instead of trying to execute the raw `export` in CommonJS mode.
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|@react-navigation/.*|@reduxjs/.*|immer|redux|react-native-(?:gesture-handler|safe-area-context|screens)|expo|@expo/.*)/)',
  ],
};
