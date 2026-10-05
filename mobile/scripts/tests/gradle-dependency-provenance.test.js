'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const ROOT = path.resolve(__dirname, '..', '..');
const {
  validateAutolinkedPublications,
  validateGradleDeclarations,
  validateLock,
  validateMetadata,
  validateRoot,
} = require('../verify-gradle-dependency-provenance');

test('Gradle plugins and release dependencies are hash-verified and locked', () => {
  const result = validateRoot(ROOT);
  assert.ok(result.componentCount >= 500);
  assert.ok(result.artifactCount >= 500);
  assert.ok(result.checksumCount >= result.artifactCount);
  assert.ok(result.buildscriptLockCount > 100);
  assert.ok(result.appLockCount > 150);
  assert.ok(result.autolinkedPublicationCount > 5);
});

test('native Media3 compilation is locked to the existing react-native-video release', () => {
  const appGradle = fs.readFileSync(
    path.join(ROOT, 'android', 'app', 'build.gradle'),
    'utf8',
  );
  const videoProperties = fs.readFileSync(
    path.join(
      ROOT,
      'node_modules',
      'react-native-video',
      'android',
      'gradle.properties',
    ),
    'utf8',
  );
  const appVersion = appGradle.match(/def roknMedia3Version = "([^"]+)"/)?.[1];
  const videoVersion = videoProperties
    .match(/^RNVideo_media3Version=(.+)$/m)?.[1]
    .trim();
  assert.ok(appVersion);
  assert.equal(appVersion, videoVersion);

  const locks = new Map(
    fs
      .readFileSync(
        path.join(ROOT, 'android', 'app', 'gradle.lockfile'),
        'utf8',
      )
      .split(/\r?\n/)
      .filter(line => line && !line.startsWith('#'))
      .map(line => {
        const [coordinate, configurations] = line.split('=');
        return [coordinate, new Set(configurations.split(','))];
      }),
  );
  // This is the compile closure resolved by Gradle for the existing player,
  // not a separate version pin or replacement for native dependency resolution.
  const compileCoordinates = [
    'common',
    'container',
    'database',
    'datasource',
    'decoder',
    'exoplayer',
    'exoplayer-dash',
    'exoplayer-hls',
    'extractor',
  ].map(module => `androidx.media3:media3-${module}:${appVersion}`);
  // Reuse the runtime-selected transitive versions rather than introducing
  // another Guava pin. Several tool configurations legitimately use other versions.
  for (const module of ['guava', 'failureaccess']) {
    const runtimeCoordinates = [...locks].filter(
      ([coordinate, configurations]) =>
        coordinate.startsWith(`com.google.guava:${module}:`) &&
        configurations.has('releaseRuntimeClasspath'),
    );
    assert.equal(
      runtimeCoordinates.length,
      1,
      `${module} runtime version must be unambiguous`,
    );
    compileCoordinates.push(runtimeCoordinates[0][0]);
  }
  for (const coordinate of compileCoordinates) {
    for (const variant of ['debug', 'debugOptimized', 'release']) {
      for (const suffix of [
        'CompileClasspath',
        'UnitTestCompileClasspath',
        'RuntimeClasspath',
      ]) {
        const configuration = `${variant}${suffix}`;
        assert.ok(
          locks.get(coordinate)?.has(configuration),
          `${coordinate} missing ${configuration}`,
        );
      }
    }
  }
});

test('Gradle provenance gate rejects missing hashes and dynamic lock versions', () => {
  const metadata = fs.readFileSync(
    path.join(ROOT, 'android', 'gradle', 'verification-metadata.xml'),
    'utf8',
  );
  assert.throws(
    () =>
      validateMetadata(
        metadata.replace(/<sha256 value="[0-9a-f]{64}"[^>]*\/>/, ''),
      ),
    /no reviewed SHA-256 checksum/,
  );
  assert.throws(
    () =>
      validateLock(
        '# This is a Gradle generated file for dependency locking.\nexample:unsafe:1.+=releaseRuntimeClasspath\n',
        'fixture.lockfile',
      ),
    /dynamic dependency version/,
  );
  assert.throws(
    () =>
      validateGradleDeclarations(
        "dependencies { implementation('example:unsafe:1.+') }",
        'fixture.gradle',
      ),
    /declares a dynamic dependency version/,
  );
  assert.throws(
    () =>
      validateAutolinkedPublications('', {
        modules: [
          {
            projects: [
              {
                publication: {
                  artifactId: 'expo.modules.fixture',
                  groupId: 'host.exp.exponent',
                  version: '55.0.99',
                },
              },
            ],
          },
        ],
      }),
    /lockfile is stale for autolinked Android publication/,
  );
});
