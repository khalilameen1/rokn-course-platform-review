// ARCHITECTURE.md M3: network calls live in src/services (the HTTP client is
// defined in src/constants/api*), device storage in src/services storage
// modules plus the low-level helpers in src/constants and src/utils. Screens
// and components call hooks; hooks call services. Tests and scripts are exempt.
const HTTP_CLIENT_MESSAGE =
  'Network calls live in src/services (ARCHITECTURE.md M3). Add or reuse a request function in src/services/api and call that.';
const STORAGE_MESSAGE =
  'Device storage lives in src/services storage modules (ARCHITECTURE.md M3). Add or reuse a service function and call that.';
const HTTP_CLIENT_MODULES = [
  '**/constants/api',
  '**/constants/apiRequestPolicy',
  '**/constants/apiSessionBoundary',
];
const TEST_FILES = ['**/__tests__/**', '**/*.test.{js,jsx,ts,tsx}'];
const httpGlobals = [
  {name: 'fetch', message: HTTP_CLIENT_MESSAGE},
  {name: 'XMLHttpRequest', message: HTTP_CLIENT_MESSAGE},
];
const restrictedRequire = (pattern, message) => ({
  selector: `CallExpression[callee.name='require'][arguments.0.value=${pattern}], ImportExpression[source.value=${pattern}]`,
  message,
});

module.exports = {
  root: true,
  extends: '@react-native',
  rules: {
    // Fire-and-forget promises use an explicit `void` marker.
    'no-void': 'off',
    // Responsive values and pressed states are data-driven.
    'react-native/no-inline-styles': 'off',
    // Header slots are render props.
    'react/no-unstable-nested-components': ['error', {allowAsProps: true}],
    eqeqeq: 'error',
  },
  overrides: [
    {
      // Screens, components, hooks, navigation, store: neither HTTP nor storage.
      files: ['App.tsx', 'index.js', 'src/**/*.{js,jsx,ts,tsx}'],
      excludedFiles: [
        'src/services/**',
        'src/constants/**',
        'src/utils/**',
        ...TEST_FILES,
      ],
      rules: {
        'no-restricted-imports': [
          'error',
          {
            paths: [
              {name: 'axios', message: HTTP_CLIENT_MESSAGE},
              {
                name: '@react-native-async-storage/async-storage',
                message: STORAGE_MESSAGE,
              },
            ],
            patterns: [{group: HTTP_CLIENT_MODULES, message: HTTP_CLIENT_MESSAGE}],
          },
        ],
        'no-restricted-globals': ['error', ...httpGlobals],
        'no-restricted-syntax': [
          'error',
          restrictedRequire('/^axios$|constants\\u002Fapi(RequestPolicy|SessionBoundary)?$/', HTTP_CLIENT_MESSAGE),
          restrictedRequire('/^@react-native-async-storage\\u002Fasync-storage$/', STORAGE_MESSAGE),
        ],
      },
    },
    {
      // Low-level constants and utilities may keep device storage helpers but
      // never call the network; only the client definition itself uses axios.
      files: ['src/constants/**/*.{js,jsx,ts,tsx}', 'src/utils/**/*.{js,jsx,ts,tsx}'],
      excludedFiles: [
        'src/constants/api.ts',
        'src/constants/apiRequestPolicy.ts',
        'src/constants/apiSessionBoundary.ts',
        ...TEST_FILES,
      ],
      rules: {
        'no-restricted-imports': [
          'error',
          {
            paths: [{name: 'axios', message: HTTP_CLIENT_MESSAGE}],
            patterns: [{group: HTTP_CLIENT_MODULES, message: HTTP_CLIENT_MESSAGE}],
          },
        ],
        'no-restricted-globals': ['error', ...httpGlobals],
        'no-restricted-syntax': [
          'error',
          restrictedRequire('/^axios$|constants\\u002Fapi(RequestPolicy|SessionBoundary)?$/', HTTP_CLIENT_MESSAGE),
        ],
      },
    },
    {
      files: ['scripts/**/*.js'],
      env: {
        node: true,
        es2022: true,
      },
      globals: {
        AbortSignal: 'readonly',
        AggregateError: 'readonly',
        Buffer: 'readonly',
        Response: 'readonly',
        TextDecoder: 'readonly',
      },
      rules: {
        // Archive hashing and binary parsers intentionally use bitwise operations.
        'no-bitwise': 'off',
        // Legal-notice fixtures use aligned literal spaces in audited patterns.
        'no-regex-spaces': 'off',
      },
    },
  ],
};
