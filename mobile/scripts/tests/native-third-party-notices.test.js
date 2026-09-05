'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const ROOT = path.resolve(__dirname, '..', '..');
const {
  ALLOWED_LICENSES,
  ANDROID_LEGAL_METADATA_ABSENCE_ALLOWLIST,
  POD_UPSTREAM_LEGAL_DOCUMENTS,
  POD_EXACT_LICENSE_SELECTIONS,
  buildAndroidSnapshot,
  buildExternalPodRecord,
  buildRemotePodRecord,
  collectLegalFilesFromArchive,
  inventorySourceTree,
  normalizeLicense,
  parsePodLock,
  parsePomLicenses,
  resolveInstalledPodBindings,
  validateAndroidSnapshot,
} = require('../generate-native-third-party-notices');

const sha256 = value =>
  crypto
    .createHash('sha256')
    .update(String(value).replace(/\r\n?/g, '\n').trim(), 'utf8')
    .digest('hex');

const crc32 = buffer => {
  let crc = 0xffffffff;
  for (const byte of buffer) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit += 1) {
      crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
    }
  }
  return (crc ^ 0xffffffff) >>> 0;
};

const zipEntry = (name, value) => {
  const nameBytes = Buffer.from(name, 'utf8');
  const data = Buffer.from(value, 'utf8');
  const local = Buffer.alloc(30);
  local.writeUInt32LE(0x04034b50, 0);
  local.writeUInt16LE(20, 4);
  local.writeUInt16LE(0x800, 6);
  local.writeUInt32LE(crc32(data), 14);
  local.writeUInt32LE(data.length, 18);
  local.writeUInt32LE(data.length, 22);
  local.writeUInt16LE(nameBytes.length, 26);
  const central = Buffer.alloc(46);
  central.writeUInt32LE(0x02014b50, 0);
  central.writeUInt16LE(20, 4);
  central.writeUInt16LE(20, 6);
  central.writeUInt16LE(0x800, 8);
  central.writeUInt32LE(crc32(data), 16);
  central.writeUInt32LE(data.length, 20);
  central.writeUInt32LE(data.length, 24);
  central.writeUInt16LE(nameBytes.length, 28);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0);
  eocd.writeUInt16LE(1, 8);
  eocd.writeUInt16LE(1, 10);
  eocd.writeUInt32LE(central.length + nameBytes.length, 12);
  eocd.writeUInt32LE(local.length + nameBytes.length + data.length, 16);
  return Buffer.concat([local, nameBytes, data, central, nameBytes, eocd]);
};

const androidResolution = coordinates => ({
  configuration: 'releaseRuntimeClasspath',
  coordinates,
  projectComponents: [],
  resolvedArtifactCount: coordinates.reduce(
    (count, coordinate) => count + (coordinate.artifacts || []).length,
    0,
  ),
  moduleArtifactCount: coordinates.reduce(
    (count, coordinate) => count + (coordinate.artifacts || []).length,
    0,
  ),
  projectArtifactCount: 0,
  unresolvedDependencies: [],
  unclassifiedLocalFiles: [],
  unclassifiedResolvedArtifacts: [],
});

test('normalizes reviewed Maven and Pod license metadata', () => {
  assert.equal(
    normalizeLicense('The Apache Software License, Version 2.0'),
    'Apache-2.0',
  );
  assert.equal(normalizeLicense('Boost Software License'), 'Boost-1.0');
  assert.equal(
    normalizeLicense('Android Software Development Kit License'),
    'LicenseRef-Android-SDK',
  );
  assert.equal(
    normalizeLicense('Facebook Platform License'),
    'LicenseRef-Facebook-Platform',
  );
  assert.equal(normalizeLicense('MIT AND GPL-3.0-only'), null);
  assert.equal(normalizeLicense('Apache 2.0 / proprietary'), null);
  const licenses = parsePomLicenses(`
    <project><licenses><license><name>Apache License 2.0</name>
    <url>https://www.apache.org/licenses/LICENSE-2.0.txt</url>
    </license></licenses></project>`);
  assert.deepEqual(licenses, [
    {
      name: 'Apache License 2.0',
      url: 'https://www.apache.org/licenses/LICENSE-2.0.txt',
      distribution: null,
      selectedLicense: 'Apache-2.0',
    },
  ]);
  assert.deepEqual(POD_EXACT_LICENSE_SELECTIONS.get('SocketRocket@0.7.1'), {
    license: 'BSD-3-Clause',
    reason:
      "The exact SocketRocket 0.7.1 podspec says 'BSD'; its installed LICENSE contains the reviewed BSD 3-Clause terms and Facebook attribution.",
  });
  assert.deepEqual(POD_EXACT_LICENSE_SELECTIONS.get('GTMAppAuth@5.0.0'), {
    license: 'Apache-2.0',
    reason:
      "The exact GTMAppAuth 5.0.0 podspec abbreviates its license as 'Apache'; the tagged LICENSE contains the reviewed Apache License 2.0 terms.",
  });
  assert.deepEqual(POD_EXACT_LICENSE_SELECTIONS.get('GoogleSignIn@9.2.0'), {
    license: 'Apache-2.0',
    reason:
      "The exact GoogleSignIn 9.2.0 podspec abbreviates its license as 'Apache'; the tagged LICENSE contains the reviewed Apache License 2.0 terms.",
  });
  assert.deepEqual(
    POD_EXACT_LICENSE_SELECTIONS.get('GTMSessionFetcher@3.5.0'),
    {
      license: 'Apache-2.0',
      reason:
        "The exact GTMSessionFetcher 3.5.0 podspec abbreviates its license as 'Apache'; the installed LICENSE contains the reviewed Apache License 2.0 terms.",
    },
  );
});

test('applies reviewed Apache selections only to exact pod coordinates', async () => {
  const sessionFetcherSpec = Buffer.from(
    JSON.stringify({
      homepage: 'https://github.com/google/gtm-session-fetcher',
      license: {type: 'Apache', file: 'LICENSE'},
      source: {
        git: 'https://github.com/google/gtm-session-fetcher.git',
        tag: 'v3.5.0',
      },
    }),
    'utf8',
  );
  const originalFetch = global.fetch;
  global.fetch = async () =>
    new Response(sessionFetcherSpec, {
      status: 200,
      headers: {'content-type': 'application/json'},
    });
  try {
    const record = await buildRemotePodRecord(
      {
        coordinate: 'GTMSessionFetcher@3.5.0',
        name: 'GTMSessionFetcher',
        specChecksum: crypto
          .createHash('sha1')
          .update(sessionFetcherSpec)
          .digest('hex'),
      },
      new Map(),
    );
    assert.deepEqual(record.selectedLicenses, ['Apache-2.0']);
    assert.deepEqual(
      record.exactLicenseSelection,
      POD_EXACT_LICENSE_SELECTIONS.get('GTMSessionFetcher@3.5.0'),
    );

    const gtmAppAuthSpec = Buffer.from(
      JSON.stringify({
        homepage: 'https://github.com/google/GTMAppAuth',
        license: {type: 'Apache', file: 'LICENSE'},
        source: {
          git: 'https://github.com/google/GTMAppAuth.git',
          tag: '5.0.0',
        },
      }),
      'utf8',
    );
    global.fetch = async () =>
      new Response(gtmAppAuthSpec, {
        status: 200,
        headers: {'content-type': 'application/json'},
      });
    const gtmAppAuthRecord = await buildRemotePodRecord(
      {
        coordinate: 'GTMAppAuth@5.0.0',
        name: 'GTMAppAuth',
        specChecksum: crypto
          .createHash('sha1')
          .update(gtmAppAuthSpec)
          .digest('hex'),
      },
      new Map(),
    );
    assert.deepEqual(gtmAppAuthRecord.selectedLicenses, ['Apache-2.0']);
    assert.deepEqual(
      gtmAppAuthRecord.exactLicenseSelection,
      POD_EXACT_LICENSE_SELECTIONS.get('GTMAppAuth@5.0.0'),
    );

    const googleSignInSpec = Buffer.from(
      JSON.stringify({
        homepage: 'https://developers.google.com/identity/sign-in/ios/',
        license: {type: 'Apache', file: 'LICENSE'},
        source: {
          git: 'https://github.com/google/GoogleSignIn-iOS.git',
          tag: '9.2.0',
        },
      }),
      'utf8',
    );
    global.fetch = async () =>
      new Response(googleSignInSpec, {
        status: 200,
        headers: {'content-type': 'application/json'},
      });
    const googleSignInRecord = await buildRemotePodRecord(
      {
        coordinate: 'GoogleSignIn@9.2.0',
        name: 'GoogleSignIn',
        specChecksum: crypto
          .createHash('sha1')
          .update(googleSignInSpec)
          .digest('hex'),
      },
      new Map(),
    );
    assert.deepEqual(googleSignInRecord.selectedLicenses, ['Apache-2.0']);
    assert.deepEqual(
      googleSignInRecord.exactLicenseSelection,
      POD_EXACT_LICENSE_SELECTIONS.get('GoogleSignIn@9.2.0'),
    );

    await assert.rejects(
      buildRemotePodRecord(
        {
          coordinate: 'UnreviewedApachePod@1.0.0',
          name: 'UnreviewedApachePod',
          specChecksum: crypto
            .createHash('sha1')
            .update(googleSignInSpec)
            .digest('hex'),
        },
        new Map(),
      ),
      /has no reviewed license classification for raw term "Apache"/,
    );
  } finally {
    global.fetch = originalFetch;
  }
});

test('applies exact reviewed license selections to remote CocoaPods specs', async () => {
  const spec = Buffer.from(
    JSON.stringify({
      homepage: 'https://github.com/facebookincubator/SocketRocket',
      license: {type: 'BSD', file: 'LICENSE'},
      source: {
        git: 'https://github.com/facebookincubator/SocketRocket.git',
        tag: '0.7.1',
      },
    }),
    'utf8',
  );
  const originalFetch = global.fetch;
  global.fetch = async () =>
    new Response(spec, {
      status: 200,
      headers: {'content-type': 'application/json'},
    });
  try {
    const record = await buildRemotePodRecord(
      {
        coordinate: 'SocketRocket@0.7.1',
        name: 'SocketRocket',
        specChecksum: crypto.createHash('sha1').update(spec).digest('hex'),
      },
      new Map(),
    );
    assert.deepEqual(record.selectedLicenses, ['BSD-3-Clause']);
    assert.deepEqual(
      record.exactLicenseSelection,
      POD_EXACT_LICENSE_SELECTIONS.get('SocketRocket@0.7.1'),
    );
    assert.equal(record.legalDocumentSha256s.length, 1);
  } finally {
    global.fetch = originalFetch;
  }
});

test('retains package-specific LICENSE and NOTICE entries from native archives', () => {
  const archive = zipEntry(
    'META-INF/NOTICE.txt',
    'Copyright Example. This package includes an attributed component.',
  );
  const files = collectLegalFilesFromArchive(archive, 'fixture.jar');
  assert.equal(files.length, 1);
  assert.equal(files[0].path, 'META-INF/NOTICE.txt');
  assert.equal(files[0].sha256, sha256(files[0].text));
});

test('installed Pod provenance hashes source bytes and scans every legal-file family', () => {
  const iosDirectory = fs.mkdtempSync(
    path.join(os.tmpdir(), 'rokn-pod-source-test-'),
  );
  try {
    const podsDirectory = path.join(iosDirectory, 'Pods');
    const sourceDirectory = path.join(podsDirectory, 'FixturePod');
    fs.mkdirSync(path.join(sourceDirectory, 'legal'), {recursive: true});
    const lockText =
      'PODS:\n\nPODFILE CHECKSUM: 0000000000000000000000000000000000000000\n';
    fs.writeFileSync(path.join(iosDirectory, 'Podfile.lock'), lockText, 'utf8');
    fs.writeFileSync(
      path.join(podsDirectory, 'Manifest.lock'),
      lockText,
      'utf8',
    );
    fs.writeFileSync(
      path.join(sourceDirectory, 'Source.m'),
      'int fixture(void) { return 1; }',
      'utf8',
    );
    fs.writeFileSync(
      path.join(sourceDirectory, 'LICENSE'),
      'Fixture MIT terms.',
      'utf8',
    );
    fs.writeFileSync(
      path.join(sourceDirectory, 'legal', 'NOTICE.txt'),
      'Fixture package-specific attribution.',
      'utf8',
    );
    fs.writeFileSync(
      path.join(sourceDirectory, 'legal', 'COPYRIGHT.extra'),
      'Copyright Fixture Authors.',
      'utf8',
    );
    const inventory = {
      pods: [
        {
          coordinate: 'FixturePod@1.0.0',
          externalSource: null,
          firstPartyGenerated: false,
          name: 'FixturePod',
          sourceKind: 'cocoapods-trunk',
          version: '1.0.0',
        },
      ],
    };
    const first = resolveInstalledPodBindings(inventory, {iosDirectory});
    const binding = first.bindings.get('FixturePod@1.0.0');
    assert.equal(binding.kind, 'pod-sandbox-source-tree');
    assert.match(binding.treeSha256, /^[0-9a-f]{64}$/);
    assert.deepEqual(
      binding.legalFiles.map(file => file.path),
      ['LICENSE', 'legal/COPYRIGHT.extra', 'legal/NOTICE.txt'],
    );
    assert.deepEqual(inventorySourceTree(sourceDirectory), {
      fileCount: binding.fileCount,
      legalFiles: binding.legalFiles,
      treeSha256: binding.treeSha256,
    });
    fs.writeFileSync(
      path.join(sourceDirectory, 'Source.m'),
      'int fixture(void) { return 2; }',
      'utf8',
    );
    const second = resolveInstalledPodBindings(inventory, {iosDirectory});
    assert.notEqual(
      second.bindings.get('FixturePod@1.0.0').treeSha256,
      binding.treeSha256,
    );
    fs.writeFileSync(
      path.join(podsDirectory, 'Manifest.lock'),
      `${lockText}# stale\n`,
      'utf8',
    );
    assert.throws(
      () => resolveInstalledPodBindings(inventory, {iosDirectory}),
      /Manifest\.lock does not exactly match/,
    );
  } finally {
    fs.rmSync(iosDirectory, {recursive: true, force: true});
  }
});

test('Gradle resolver forbids unresolved, lenient, and local-file omissions', () => {
  const initScript = fs.readFileSync(
    path.join(ROOT, 'scripts', 'android-license-inventory.init.gradle'),
    'utf8',
  );
  assert.doesNotMatch(initScript, /lenient\s*=\s*true/);
  assert.match(initScript, /attribute\(artifactType, 'android-classes-jar'\)/);
  assert.match(initScript, /lenient\s*=\s*false/);
  assert.match(initScript, /UnresolvedDependencyResult/);
  assert.match(initScript, /Unresolved release dependencies are forbidden/);
  assert.match(initScript, /FileCollectionDependency/);
  assert.match(
    initScript,
    /Unclassified local-file release dependencies are forbidden/,
  );
  assert.match(initScript, /ProjectComponentIdentifier/);
  assert.match(initScript, /projectComponents/);
  assert.match(
    initScript,
    /def allResolvedArtifacts = configuration\.incoming\.artifactView/,
  );
  const unfilteredView = initScript.slice(
    initScript.indexOf('def allResolvedArtifacts'),
    initScript.indexOf('def moduleArtifactCount'),
  );
  assert.doesNotMatch(unfilteredView, /componentFilter/);
  assert.match(
    initScript,
    /Unclassified resolved release artifacts are forbidden/,
  );
  assert.match(initScript, /unclassifiedResolvedArtifacts/);
});

test('Android gate rejects a missing retained NOTICE text', () => {
  const directory = fs.mkdtempSync(
    path.join(os.tmpdir(), 'rokn-native-gate-test-'),
  );
  try {
    const pom = path.join(directory, 'fixture.pom');
    const artifact = path.join(directory, 'fixture.jar');
    fs.writeFileSync(
      pom,
      '<project><licenses><license><name>Apache License 2.0</name></license></licenses></project>',
      'utf8',
    );
    fs.writeFileSync(
      artifact,
      zipEntry('META-INF/NOTICE', 'Copyright Fixture Project.'),
    );
    const input = androidResolution([
      {
        coordinate: 'example:fixture:1.0.0',
        pom,
        artifacts: [{file: artifact, type: 'jar', extension: 'jar'}],
      },
    ]);
    const snapshot = buildAndroidSnapshot(input);
    snapshot.documents = snapshot.documents.filter(
      document => !document.text.includes('Copyright Fixture Project.'),
    );
    assert.throws(
      () => validateAndroidSnapshot(input, snapshot),
      /Missing Android legal text/,
    );
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
});

test('native gates reject empty or unknown licenses even when LICENSE text exists', () => {
  const directory = fs.mkdtempSync(
    path.join(os.tmpdir(), 'rokn-native-license-test-'),
  );
  try {
    const artifact = path.join(directory, 'unknown.jar');
    fs.writeFileSync(
      artifact,
      zipEntry(
        'META-INF/LICENSE',
        'Unknown license terms that must not imply approval.',
      ),
    );
    const emptyPom = path.join(directory, 'empty.pom');
    fs.writeFileSync(emptyPom, '<project/>', 'utf8');
    assert.throws(
      () =>
        buildAndroidSnapshot(
          androidResolution([
            {
              coordinate: 'example:empty-license:1.0.0',
              pom: emptyPom,
              artifacts: [{file: artifact, type: 'jar', extension: 'jar'}],
            },
          ]),
        ),
      /has no reviewed license classification.*LICENSE\/NOTICE file alone/s,
    );

    const unknownPom = path.join(directory, 'unknown.pom');
    fs.writeFileSync(
      unknownPom,
      '<project><licenses><license><name>MIT AND GPL-3.0-only</name></license></licenses></project>',
      'utf8',
    );
    assert.throws(
      () =>
        buildAndroidSnapshot(
          androidResolution([
            {
              coordinate: 'example:unknown-license:1.0.0',
              pom: unknownPom,
              artifacts: [{file: artifact, type: 'jar', extension: 'jar'}],
            },
          ]),
        ),
      /Unreviewed Android license.*MIT AND GPL-3\.0-only/,
    );

    fs.writeFileSync(
      path.join(directory, 'package.json'),
      JSON.stringify({
        name: 'unknown-pod-owner',
        version: '1.0.0',
        license: 'MIT AND GPL-3.0-only',
      }),
      'utf8',
    );
    fs.writeFileSync(
      path.join(directory, 'UnknownPod.podspec'),
      "Pod::Spec.new do |spec|\n  spec.name = 'UnknownPod'\n  spec.license = { :type => 'MIT AND GPL-3.0-only', :file => 'LICENSE' }\nend\n",
      'utf8',
    );
    fs.writeFileSync(
      path.join(directory, 'LICENSE'),
      'Unknown copyleft terms must be classified and reviewed before release.',
      'utf8',
    );
    assert.throws(
      () =>
        buildExternalPodRecord(
          {
            coordinate: 'UnknownPod@1.0.0',
            externalSource: {path: directory},
            firstPartyGenerated: false,
            name: 'UnknownPod',
            sourceKind: 'external',
            specChecksum: '0000000000000000000000000000000000000000',
            version: '1.0.0',
          },
          new Map(),
          new Map(),
        ),
      /Pod dependency UnknownPod@1\.0\.0 has no reviewed license classification/,
    );
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
});

test('Android release snapshot covers the resolved closure and ships exact texts', () => {
  const snapshot = JSON.parse(
    fs.readFileSync(
      path.join(
        ROOT,
        'scripts',
        'licenses',
        'android-release-notices.generated.json',
      ),
      'utf8',
    ),
  );
  assert.equal(snapshot.schemaVersion, 1);
  assert.equal(snapshot.configuration, 'releaseRuntimeClasspath');
  assert.equal(snapshot.dependencyCount, 241);
  assert.equal(snapshot.dependencies.length, 241);
  assert.equal(
    snapshot.dependencies.filter(item => item.artifacts.length === 0).length,
    34,
  );
  assert.equal(snapshot.projectComponentCount, 23);
  assert.equal(snapshot.projectComponents.length, 23);
  assert.equal(snapshot.unresolvedDependencyCount, 0);
  assert.equal(snapshot.unclassifiedLocalFileCount, 0);
  assert.equal(snapshot.unclassifiedResolvedArtifactCount, 0);
  assert.equal(
    snapshot.resolvedArtifactCount,
    snapshot.moduleArtifactCount + snapshot.projectArtifactCount,
  );
  assert.ok(snapshot.moduleArtifactCount > 0);
  assert.ok(snapshot.projectArtifactCount > 0);
  const documents = new Map(
    snapshot.documents.map(item => [item.sha256, item]),
  );
  assert.ok(documents.size > 0);
  for (const document of documents.values()) {
    assert.equal(document.sha256, sha256(document.text));
    assert.ok(document.sources.length > 0);
  }
  for (const dependency of snapshot.dependencies) {
    assert.match(dependency.pomSha256, /^[0-9a-f]{64}$/);
    assert.ok(
      dependency.legalDocumentSha256s.length > 0 ||
        ANDROID_LEGAL_METADATA_ABSENCE_ALLOWLIST.has(dependency.coordinate),
      dependency.coordinate,
    );
    dependency.selectedLicenses.forEach(license =>
      assert.ok(
        ALLOWED_LICENSES.has(license),
        `${dependency.coordinate}: ${license}`,
      ),
    );
    dependency.legalDocumentSha256s.forEach(hash =>
      assert.ok(documents.has(hash), `${dependency.coordinate}: ${hash}`),
    );
  }
  for (const component of snapshot.projectComponents) {
    assert.equal(component.classification, 'npm-production-source-project');
    assert.match(component.npmIntegrity, /^sha512-/);
    assert.ok(component.selectedLicenses.length > 0);
    assert.ok(component.legalDocumentSha256s.length > 0);
    component.legalDocumentSha256s.forEach(hash =>
      assert.ok(documents.has(hash)),
    );
  }
  const markdown = fs.readFileSync(
    path.join(ROOT, 'ANDROID_THIRD_PARTY_NOTICES.md'),
    'utf8',
  );
  assert.equal(
    markdown,
    fs.readFileSync(
      path.join(
        ROOT,
        'android',
        'app',
        'src',
        'main',
        'assets',
        'NATIVE_THIRD_PARTY_NOTICES.md',
      ),
      'utf8',
    ),
  );
  snapshot.dependencies.forEach(item =>
    assert.ok(markdown.includes(item.coordinate)),
  );
  snapshot.documents.forEach(item => {
    assert.ok(markdown.includes(item.sha256));
    assert.ok(markdown.includes(item.text));
  });
  const appMetadataText = fs.readFileSync(
    path.join(ROOT, 'src', 'data', 'nativeThirdPartyNotices.generated.json'),
    'utf8',
  );
  const appMetadata = JSON.parse(appMetadataText);
  const podSnapshot = JSON.parse(
    fs.readFileSync(
      path.join(
        ROOT,
        'scripts',
        'licenses',
        'ios-pods-notices.generated.json',
      ),
      'utf8',
    ),
  );
  assert.equal(appMetadata.androidDependencyCount, 241);
  assert.equal(appMetadata.androidProjectComponentCount, 23);
  assert.equal(appMetadata.podDependencyCount, podSnapshot.dependencyCount);
  assert.equal(appMetadata.android.length, 241);
  assert.equal(appMetadata.androidProjects.length, 23);
  assert.equal(appMetadata.pods.length, podSnapshot.dependencies.length);
  assert.deepEqual(
    appMetadata.pods.map(item => item.coordinate),
    podSnapshot.dependencies.map(item => item.coordinate),
  );
  assert.ok(!appMetadataText.includes('"text"'));
});

test('Pod inventory binds every root to a checksum and exact source class', () => {
  const inventory = parsePodLock(
    fs.readFileSync(path.join(ROOT, 'ios', 'Podfile.lock'), 'utf8'),
  );
  assert.ok(inventory.pods.length > 100);
  assert.match(inventory.lockChecksum, /^[0-9a-f]{40}$/);
  for (const pod of inventory.pods) {
    assert.ok(pod.specChecksum || pod.firstPartyGenerated, pod.coordinate);
    assert.ok(['cocoapods-trunk', 'external'].includes(pod.sourceKind));
  }
});

test('Expo Pods without package-local notices retain commit-pinned upstream licenses', () => {
  const inventory = parsePodLock(
    fs.readFileSync(path.join(ROOT, 'ios', 'Podfile.lock'), 'utf8'),
  );
  const documents = new Map();
  const packageCache = new Map();
  const currentReviews = [...POD_UPSTREAM_LEGAL_DOCUMENTS];
  assert.equal(currentReviews.length, 17);
  assert.deepEqual(
    currentReviews
      .map(([coordinate]) => coordinate)
      .filter(
        coordinate =>
          !inventory.pods.some(item => item.coordinate === coordinate),
      ),
    [],
  );
  for (const [coordinate, review] of currentReviews) {
    const pod = inventory.pods.find(item => item.coordinate === coordinate);
    assert.ok(pod, coordinate);
    const record = buildExternalPodRecord(pod, documents, packageCache);
    assert.deepEqual(record.upstreamLegalDocument, {
      ...review,
      path: review.path.join('/'),
    });
    assert.ok(record.legalDocumentSha256s.includes(review.sha256), coordinate);
    assert.equal(record.owningNpmPackage.coordinate, review.npmCoordinate);
  }
  assert.match(
    documents.values().next().value.text,
    /650 Industries, Inc\. \(aka Expo\)/,
  );
});

test('iOS release keeps native notices bundled and verified at build time', () => {
  const project = fs.readFileSync(
    path.join(ROOT, 'ios', 'Rokn.xcodeproj', 'project.pbxproj'),
    'utf8',
  );
  assert.match(project, /NATIVE_THIRD_PARTY_NOTICES\.md in Resources/);
  assert.match(project, /Verify native third-party notices/);
  assert.match(
    project,
    /Generated by scripts\/generate-native-third-party-notices\.js/,
  );
  assert.match(project, /--ios-sources-check/);
  assert.match(project, /Pods\/Manifest\.lock/);
});
