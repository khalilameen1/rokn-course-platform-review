module.exports = {
  // Jest runs CommonJS. Exercise lazy imports there instead of swallowing
  // unsupported VM-import errors in fire-and-forget diagnostics.
  env: {
    test: {
      plugins: [
        require.resolve('@babel/plugin-transform-dynamic-import', {
          paths: [require.resolve('@babel/preset-env')],
        }),
      ],
    },
  },
  // Resolve Expo's compatible preset from Expo's own dependency tree. This
  // works whether npm hoists the preset or keeps it nested under Expo.
  presets: [
    require.resolve('babel-preset-expo', {
      paths: [require.resolve('expo/package.json')],
    }),
  ],
  plugins: [
    '@babel/plugin-transform-export-namespace-from',
    'react-native-worklets/plugin',
  ],
};
