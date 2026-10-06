'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const ROOT = path.resolve(__dirname, '..', '..');
const manifest = JSON.parse(
  fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'),
);
const lock = JSON.parse(
  fs.readFileSync(path.join(ROOT, 'package-lock.json'), 'utf8'),
);

const DIRECT_TOOLCHAIN = [
  '@react-native/babel-preset',
  '@react-native/eslint-config',
  '@react-native/metro-config',
  '@react-native/typescript-config',
];
const TRANSITIVE_TOOLCHAIN = [
  '@react-native/babel-plugin-codegen',
  '@react-native/codegen',
  '@react-native/eslint-plugin',
  '@react-native/js-polyfills',
  '@react-native/metro-babel-transformer',
];

test('release preserves Expo Field annotation instances and their key method', () => {
  const rules = fs.readFileSync(
    path.join(ROOT, 'android/app/proguard-rules.pro'),
    'utf8',
  );
  // Native minified APK selection remains the acceptance gate. This only
  // prevents removal/weakening of the reflection contract that caused it.
  assert.match(
    rules,
    /-keep\s+@interface\s+expo\.modules\.kotlin\.records\.Field\s*\{\s*\*;\s*\}/,
  );
  assert.match(
    rules,
    /-keepattributes\s+RuntimeVisibleAnnotations,AnnotationDefault/,
  );
  assert.doesNotMatch(rules, /-dont(?:optimize|shrink|obfuscate)\b/);
  assert.doesNotMatch(rules, /-keep\s+class\s+expo\.modules\.\*\*/);
});

test('React Native JS toolchain uses the exact React Native patch version', () => {
  const reactNativeVersion = manifest.dependencies?.['react-native'];
  assert.match(reactNativeVersion || '', /^\d+\.\d+\.\d+$/);
  assert.equal(
    lock.packages?.['node_modules/react-native']?.version,
    reactNativeVersion,
  );

  for (const packageName of DIRECT_TOOLCHAIN) {
    assert.equal(
      manifest.devDependencies?.[packageName],
      reactNativeVersion,
      `${packageName} must be declared as exact ${reactNativeVersion}`,
    );
  }

  for (const packageName of [...DIRECT_TOOLCHAIN, ...TRANSITIVE_TOOLCHAIN]) {
    const matchingPaths = Object.entries(lock.packages || {}).filter(
      ([packagePath]) =>
        packagePath === `node_modules/${packageName}` ||
        packagePath.endsWith(`/node_modules/${packageName}`),
    );
    assert.ok(matchingPaths.length > 0, `${packageName} is missing from package-lock.json`);
    for (const [packagePath, record] of matchingPaths) {
      assert.equal(
        record.version,
        reactNativeVersion,
        `${packagePath} drifted from react-native ${reactNativeVersion}`,
      );
    }
  }
});
