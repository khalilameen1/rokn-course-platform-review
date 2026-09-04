'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {
  ALLOWED_LICENSES,
  LEGAL_FILE_ABSENCE_ALLOWLIST,
  buildArtifacts,
  buildInventory,
  collectProductionPackagePaths,
  selectLicense,
  validateSnapshot,
} = require('../generate-third-party-notices');

const root = path.resolve(__dirname, '../..');
const lock = JSON.parse(
  fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'),
);

test('production closure follows runtime, optional and required peer edges only', () => {
  const fixture = {
    lockfileVersion: 3,
    packages: {
      '': {dependencies: {app: '1.0.0'}, devDependencies: {dev: '1.0.0'}},
      'node_modules/app': {
        version: '1.0.0',
        license: 'MIT',
        dependencies: {runtime: '1.0.0'},
        optionalDependencies: {optional: '1.0.0'},
        peerDependencies: {peer: '1.0.0', skipped: '1.0.0'},
        peerDependenciesMeta: {skipped: {optional: true}},
      },
      'node_modules/runtime': {version: '1.0.0', license: 'MIT'},
      'node_modules/optional': {version: '1.0.0', license: 'MIT'},
      'node_modules/peer': {version: '1.0.0', license: 'MIT'},
      'node_modules/skipped': {version: '1.0.0', license: 'MIT'},
      'node_modules/dev': {version: '1.0.0', license: 'MIT', dev: true},
    },
  };

  assert.deepEqual(collectProductionPackagePaths(fixture), [
    'node_modules/app',
    'node_modules/optional',
    'node_modules/peer',
    'node_modules/runtime',
  ]);
});

test('documented exceptional package licenses fail closed', () => {
  assert.equal(
    selectLicense({name: 'base-64', version: '0.1.0', declaredLicense: null}),
    'MIT',
  );
  assert.equal(
    selectLicense({
      name: 'node-forge',
      version: '1.4.0',
      declaredLicense: '(BSD-3-Clause OR GPL-2.0)',
    }),
    'BSD-3-Clause',
  );
  assert.equal(
    selectLicense({name: 'unknown', version: '1.0.0', declaredLicense: null}),
    '',
  );
});

test('inventory rejects non-canonical or credentialed npm tarball URLs', () => {
  const canonical = 'https://registry.npmjs.org/example/-/example-1.0.0.tgz';
  const fixture = resolved => ({
    lockfileVersion: 3,
    packages: {
      '': {dependencies: {example: '1.0.0'}},
      'node_modules/example': {
        version: '1.0.0',
        license: 'MIT',
        integrity: 'sha512-fixture',
        resolved,
      },
    },
  });

  assert.equal(
    buildInventory(fixture(canonical)).packages[0].resolved,
    canonical,
  );
  for (const tampered of [
    'http://registry.npmjs.org/example/-/example-1.0.0.tgz',
    'https://registry.example.com/example/-/example-1.0.0.tgz',
    'https://user:token@registry.npmjs.org/example/-/example-1.0.0.tgz',
    'https://registry.npmjs.org/example/-/renamed-1.0.0.tgz',
    'https://registry.npmjs.org/example/-/example-1.0.0.tgz?token=value',
  ]) {
    assert.throws(
      () => buildInventory(fixture(tampered)),
      /Non-canonical npm tarball URL/,
      tampered,
    );
  }

  const tamperedDevOnly = fixture(canonical);
  tamperedDevOnly.packages['node_modules/dev-only'] = {
    version: '2.0.0',
    dev: true,
    license: 'MIT',
    integrity: 'sha512-fixture',
    resolved: 'https://example.invalid/dev-only-2.0.0.tgz',
  };
  assert.throws(
    () => buildInventory(tamperedDevOnly),
    /Non-canonical npm tarball URL for dev-only@2\.0\.0/,
  );

  const missingResolvedDevOnly = fixture(canonical);
  missingResolvedDevOnly.packages['node_modules/dev-only'] = {
    version: '2.0.0',
    dev: true,
    license: 'MIT',
    integrity: 'sha512-fixture',
  };
  assert.throws(
    () => buildInventory(missingResolvedDevOnly),
    /Incomplete resolved npm lock entry: node_modules\/dev-only/,
  );
});

test('retains every package legal file and every reviewed absence record', () => {
  const artifacts = buildArtifacts(lock);
  assert.ok(artifacts.inventory.packages.length > 500);
  assert.ok(
    artifacts.inventory.packagePathCount >= artifacts.inventory.packages.length,
  );
  assert.ok(
    artifacts.inventory.packages.every(item =>
      ALLOWED_LICENSES.has(item.license),
    ),
  );
  assert.equal(artifacts.snapshot.packages.length, 734);
  assert.equal(
    artifacts.snapshot.packages.filter(
      item => item.legalSource === 'package-root',
    ).length,
    609,
  );
  const fallbacks = artifacts.snapshot.packages.filter(
    item => item.legalSource === 'reviewed-metadata-fallback',
  );
  assert.equal(fallbacks.length, 125);
  assert.deepEqual(
    new Set(fallbacks.map(item => item.coordinate)),
    LEGAL_FILE_ABSENCE_ALLOWLIST,
  );
  for (const item of artifacts.snapshot.packages) {
    assert.ok(item.files.length > 0, item.coordinate);
    for (const file of item.files) {
      assert.match(file.sha256, /^[a-f0-9]{64}$/);
      assert.ok(file.text.length > 0, `${item.coordinate}/${file.path}`);
    }
  }

  const byCoordinate = new Map(
    artifacts.snapshot.packages.map(item => [item.coordinate, item]),
  );
  const base64 = byCoordinate.get('base-64@0.1.0');
  assert.equal(base64.selectedLicense, 'MIT');
  assert.ok(base64.files.some(file => file.path === 'LICENSE-MIT.txt'));

  const nodeForge = byCoordinate.get('node-forge@1.4.0');
  assert.equal(nodeForge.selectedLicense, 'BSD-3-Clause');
  assert.match(nodeForge.files[0].text, /New BSD License \(3-clause\)/);
  assert.match(nodeForge.files[0].text, /Digital Bazaar/);

  const apachePackages = artifacts.snapshot.packages.filter(
    item => item.selectedLicense === 'Apache-2.0',
  );
  assert.ok(apachePackages.length > 0);
  assert.ok(
    apachePackages.every(item =>
      ['included', 'not-published'].includes(item.apacheNotice),
    ),
  );
  const xcode = byCoordinate.get('xcode@3.0.1');
  assert.equal(xcode.apacheNotice, 'included');
  assert.ok(xcode.files.some(file => file.path === 'NOTICE'));

  assert.match(artifacts.markdown, /### base-64@0\.1\.0/);
  assert.match(artifacts.markdown, /#### LICENSE-MIT\.txt/);
  assert.match(artifacts.markdown, /### xcode@3\.0\.1/);
  assert.match(artifacts.markdown, /Apache NOTICE: `included`/);

  const appData = JSON.parse(artifacts.appData);
  assert.equal(appData.schemaVersion, 2);
  assert.equal(appData.packages.length, 734);
  assert.equal(appData.licenseTexts, undefined);
  assert.ok(Buffer.byteLength(artifacts.appData) < 250000);

  assert.equal(
    fs.readFileSync(path.join(root, 'THIRD_PARTY_NOTICES.md'), 'utf8'),
    artifacts.markdown,
  );
  assert.equal(
    fs.readFileSync(
      path.join(root, 'src/data/thirdPartyNotices.generated.json'),
      'utf8',
    ),
    artifacts.appData,
  );
  assert.equal(
    fs.readFileSync(
      path.join(root, 'android/app/src/main/assets/THIRD_PARTY_NOTICES.md'),
      'utf8',
    ),
    artifacts.markdown,
  );
  assert.equal(
    fs.readFileSync(path.join(root, 'ios/Rokn/THIRD_PARTY_NOTICES.md'), 'utf8'),
    artifacts.markdown,
  );
  assert.match(
    fs.readFileSync(
      path.join(root, 'ios/Rokn.xcodeproj/project.pbxproj'),
      'utf8',
    ),
    /THIRD_PARTY_NOTICES\.md in Resources/,
  );
});

test('gate rejects removal of a published Apache NOTICE', () => {
  const artifacts = buildArtifacts(lock);
  const tampered = {
    ...artifacts.snapshot,
    packages: artifacts.snapshot.packages.map(item =>
      item.coordinate === 'xcode@3.0.1'
        ? {
            ...item,
            files: item.files.filter(file => file.path !== 'NOTICE'),
            publishedLegalFileCount: item.publishedLegalFileCount - 1,
            apacheNotice: 'not-published',
          }
        : item,
    ),
  };
  assert.throws(
    () => validateSnapshot(artifacts.inventory, tampered),
    /Installed legal files changed for xcode@3\.0\.1/,
  );
});
