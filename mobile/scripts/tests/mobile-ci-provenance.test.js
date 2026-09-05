'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..', '..');
const provenance = require('../verify-artifact-provenance');
const smoke = require('../run-android-staging-smoke');
const fixtureUrlName = ['ROKN_SMOKE_FORCED_UPDATE', 'FIXTURE_URL'].join('_');
const fixtureTokenName = ['ROKN_SMOKE', 'FIXTURE_TOKEN'].join('_');

test('normalizes release signer fingerprints and parses Android tools', () => {
  const digest = 'ab'.repeat(32);
  const colonDigest = digest.match(/../g).join(':');
  assert.equal(provenance.normalizeSha256(`SHA256: ${colonDigest}`), digest);
  assert.deepEqual(
    provenance.signerDigestsFromOutput(
      `Signer #1 certificate SHA-256 digest: ${colonDigest}`,
    ),
    [digest],
  );
  assert.equal(
    provenance.keytoolDigestFromOutput(`SHA256: ${colonDigest}`),
    digest,
  );
  assert.deepEqual(
    provenance.parseApkBadging(
      "package: name='com.rokn' versionCode='23' versionName='1.0.22'",
    ),
    {applicationId: 'com.rokn', versionCode: '23'},
  );
});

test('production API evidence binds both the full base and its path', () => {
  const apiBase =
    'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/';
  const evidence = {
    apiBase,
    apiBaseSha256: provenance.sha256(apiBase),
    apiPathHash: provenance.sha256('/api/v1/'),
  };
  assert.deepEqual(provenance.apiEvidenceFailures(evidence), []);
  assert.match(
    provenance.apiEvidenceFailures({
      ...evidence,
      apiPathHash: '0'.repeat(64),
    })[0],
    /path SHA-256/,
  );
  assert.match(
    provenance.apiEvidenceFailures({
      ...evidence,
      apiBase: 'https://example.invalid/api/',
    })[0],
    /Production API base/,
  );
});

test('strict candidate verification binds sidecar, binary inspection and protected pins', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rokn-provenance-'));
  const artifact = path.join(directory, 'Rokn-direct.apk');
  const metadata = `${artifact}.json`;
  const signerSha256 = 'ab'.repeat(32);
  const gitCommit = '1'.repeat(40);
  const pinNames = [
    'ROKN_PROVENANCE_REQUIRE_PINNED',
    'ROKN_PROVENANCE_EXPECTED_SHA256',
    'ROKN_PROVENANCE_EXPECTED_VERSION_CODE',
    'ROKN_PROVENANCE_EXPECTED_SIGNER_SHA256',
    'ROKN_PROVENANCE_EXPECTED_GIT_COMMIT',
    'ROKN_PROVENANCE_EXPECTED_PROFILE',
    'ROKN_PROVENANCE_EXPECTED_CHANNEL',
    'ROKN_PROVENANCE_EXPECTED_FORMAT',
    'ROKN_PROVENANCE_EXPECTED_APPLICATION_ID',
    'ROKN_PROVENANCE_EXPECTED_API_BASE',
  ];
  const previous = Object.fromEntries(
    pinNames.map(name => [name, process.env[name]]),
  );
  try {
    fs.writeFileSync(artifact, 'signed-apk-fixture');
    const digest = provenance.sha256(fs.readFileSync(artifact));
    const apiBase =
      'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/';
    fs.writeFileSync(
      metadata,
      JSON.stringify({
        name: path.basename(artifact),
        version: '1.0.22',
        versionCode: 23,
        channel: 'direct',
        profile: 'production',
        format: 'apk',
        applicationId: 'com.rokn',
        publicDistributionEligible: true,
        signerRole: 'release-app-signing',
        sha256: digest,
        bytes: fs.statSync(artifact).size,
        signerSha256,
        apiHost: 'rokn-course-platform-review-production-b7gpy1.laravel.cloud',
        apiBase,
        apiBaseSha256: provenance.sha256(apiBase),
        apiPathHash: provenance.sha256('/api/v1/'),
        apiSource: 'environment',
        gitCommit,
        gitDirty: false,
        builtAtUtc: '2026-08-16T12:00:00.0000000Z',
      }),
    );
    Object.assign(process.env, {
      ROKN_PROVENANCE_REQUIRE_PINNED: '1',
      ROKN_PROVENANCE_EXPECTED_SHA256: digest,
      ROKN_PROVENANCE_EXPECTED_VERSION_CODE: '23',
      ROKN_PROVENANCE_EXPECTED_SIGNER_SHA256: signerSha256,
      ROKN_PROVENANCE_EXPECTED_GIT_COMMIT: gitCommit,
      ROKN_PROVENANCE_EXPECTED_PROFILE: 'production',
      ROKN_PROVENANCE_EXPECTED_CHANNEL: 'direct',
      ROKN_PROVENANCE_EXPECTED_FORMAT: 'apk',
      ROKN_PROVENANCE_EXPECTED_APPLICATION_ID: 'com.rokn',
      ROKN_PROVENANCE_EXPECTED_API_BASE: apiBase,
    });
    const inspectors = {
      inspectApk: () => ({
        applicationId: 'com.rokn',
        versionCode: '23',
        signerSha256: [signerSha256],
      }),
    };
    assert.deepEqual(
      provenance.verify(artifact, metadata, inspectors).failures,
      [],
    );
    process.env.ROKN_PROVENANCE_EXPECTED_GIT_COMMIT = '2'.repeat(40);
    assert.ok(
      provenance
        .verify(artifact, metadata, inspectors)
        .failures.includes('Pinned Git commit does not match the candidate.'),
    );
  } finally {
    pinNames.forEach(name => {
      if (previous[name] === undefined) delete process.env[name];
      else process.env[name] = previous[name];
    });
    fs.rmSync(directory, {recursive: true, force: true});
  }
});

test('forced-update fixture is HTTPS and issued as a bounded run lease', async () => {
  const previous = {...process.env};
  const previousFetch = global.fetch;
  const requests = [];
  try {
    process.env[fixtureUrlName] =
      'https://fixtures.rokn.app/mobile/forced-update';
    process.env[fixtureTokenName] = 'protected-token';
    process.env.ROKN_SMOKE_APK_VERSION_CODE = '23';
    process.env.ROKN_SMOKE_RUN_ID = 'run-123456';
    global.fetch = async (url, options) => {
      const payload = JSON.parse(options.body);
      requests.push({url: String(url), options, payload});
      const body =
        payload.action === 'activate'
          ? {
              active: true,
              applicationId: 'com.rokn',
              versionCode: 23,
              runId: 'run-123456',
              leaseId: 'lease-123456',
              expiresAt: new Date(Date.now() + 10 * 60_000).toISOString(),
            }
          : {released: true, leaseId: 'lease-123456'};
      return {
        ok: true,
        status: 200,
        headers: {get: () => 'application/json; charset=utf-8'},
        json: async () => body,
      };
    };

    const lease = await smoke.activateForcedUpdate();
    await smoke.deactivateForcedUpdate(lease);

    assert.equal(requests.length, 2);
    assert.equal(requests[0].payload.ttlSeconds, 900);
    assert.equal(requests[0].payload.versionCode, 23);
    assert.equal(requests[1].payload.leaseId, 'lease-123456');
    assert.equal(
      requests[0].options.headers.Authorization,
      'Bearer protected-token',
    );
    assert.equal(requests[0].options.redirect, 'error');
  } finally {
    global.fetch = previousFetch;
    Object.keys(process.env).forEach(key => {
      if (!(key in previous)) delete process.env[key];
    });
    Object.assign(process.env, previous);
  }
});

test('forced-update fixture rejects non-HTTPS transport', () => {
  const previous = process.env[fixtureUrlName];
  try {
    process.env[fixtureUrlName] =
      'http://fixtures.rokn.app/mobile/forced-update';
    assert.throws(() => smoke.fixtureUrl(), /credential-free HTTPS/);
  } finally {
    if (previous === undefined) {
      delete process.env[fixtureUrlName];
    } else {
      process.env[fixtureUrlName] = previous;
    }
  }
});

test('workflow verifies the pinned candidate before install and smoke', () => {
  const workflow = fs.readFileSync(
    path.join(root, '..', '.github', 'workflows', 'mobile-ci.yml'),
    'utf8',
  );
  const runner = fs.readFileSync(
    path.join(root, 'scripts', 'run-android-staging-smoke.js'),
    'utf8',
  );
  const verifyAt = workflow.indexOf(
    'node scripts/verify-artifact-provenance.js',
  );
  const smokeAt = workflow.indexOf('npm run e2e:android:staging');
  assert.ok(verifyAt > 0 && verifyAt < smokeAt);
  [
    'ROKN_PROVENANCE_EXPECTED_SHA256',
    'ROKN_PROVENANCE_EXPECTED_VERSION_CODE',
    'ROKN_PROVENANCE_EXPECTED_SIGNER_SHA256',
    'ROKN_PROVENANCE_EXPECTED_GIT_COMMIT',
    'ROKN_PROVENANCE_EXPECTED_PROFILE',
    'ROKN_PROVENANCE_EXPECTED_API_BASE',
  ].forEach(name => assert.match(workflow, new RegExp(name)));
  assert.match(workflow, /--proto-redir '=https'/);
  assert.ok(
    runner.indexOf("run('07-account-deletion.yaml'") <
      runner.indexOf('await runForcedUpdateFlow()'),
  );
  assert.ok(
    runner.indexOf('const lease = await activateForcedUpdate()') <
      runner.indexOf("run('06-forced-update.yaml'"),
  );
  assert.ok(
    runner.indexOf("run('06-forced-update.yaml'") <
      runner.indexOf('await deactivateForcedUpdate(lease)'),
  );
});

test('workflow uses the package-manager and registry pinned by the source tree', () => {
  const workflow = fs.readFileSync(
    path.join(root, '..', '.github', 'workflows', 'mobile-ci.yml'),
    'utf8',
  );
  const packageJson = JSON.parse(
    fs.readFileSync(path.join(root, 'package.json'), 'utf8'),
  );

  assert.equal(packageJson.engines.node, '>=24.19.0 <25');
  assert.equal(
    fs.readFileSync(path.join(root, '.node-version'), 'utf8').trim(),
    '24.19.0',
  );
  assert.equal([...workflow.matchAll(/node-version: 24\.19\.0/g)].length, 4);
  assert.equal([...workflow.matchAll(/runs-on: ubuntu-24\.04/g)].length, 3);
  assert.equal(
    [...workflow.matchAll(/java-version: ["']17\.0\.20\+8["']/g)].length,
    2,
  );

  assert.equal(
    [...workflow.matchAll(/registry-url: https:\/\/registry\.npmjs\.org/g)]
      .length,
    4,
  );
  assert.equal(
    [...workflow.matchAll(/npm install --global npm@10\.9\.3/g)].length,
    4,
  );
  assert.equal(
    [...workflow.matchAll(/test "\$\(npm --version\)" = "10\.9\.3"/g)].length,
    4,
  );
  assert.equal(
    [...workflow.matchAll(/npm ci --include=dev/g)].length,
    4,
    'production-mode CI must retain the locked build and verification toolchain',
  );
  assert.ok(
    workflow.indexOf('node scripts/verify-repository-secrets.js --history') <
      workflow.indexOf('- run: npm ci'),
  );
  assert.ok(
    workflow.indexOf('fetch-depth: 0') <
      workflow.indexOf('node scripts/verify-repository-secrets.js --history'),
  );
});

test('release tests isolate Jest from the production bundle environment', () => {
  const packageJson = JSON.parse(
    fs.readFileSync(path.join(root, 'package.json'), 'utf8'),
  );
  const runner = fs.readFileSync(
    path.join(root, 'scripts', 'run-release-tests.js'),
    'utf8',
  );

  assert.equal(packageJson.scripts['test:release'], 'node scripts/run-release-tests.js');
  assert.ok(runner.indexOf("process.env.NODE_ENV = 'test'") < runner.indexOf("require('jest')"));
  assert.ok(runner.indexOf("process.env.BABEL_ENV = 'test'") < runner.indexOf("require('jest')"));
  assert.match(runner, /--runInBand/);
  assert.match(runner, /--ci/);
  assert.match(runner, /--detectOpenHandles/);
});

test('Windows release hashing does not depend on an optional PowerShell module', () => {
  const releaseScript = fs.readFileSync(
    path.join(root, 'scripts', 'build-android-release.ps1'),
    'utf8',
  );

  assert.match(releaseScript, /function Get-FileSha256/);
  assert.match(releaseScript, /\[System\.IO\.File\]::OpenRead\(\$Path\)/);
  assert.match(
    releaseScript,
    /\$artifactSha256 = Get-FileSha256 -Path \$artifactPath/,
  );
  assert.doesNotMatch(releaseScript, /\bGet-FileHash\b/);
});

test('native lock refresh captures the production Android metadata closure', () => {
  const workflow = fs.readFileSync(
    path.join(root, '..', '.github', 'workflows', 'refresh-ios-lock.yml'),
    'utf8',
  );
  assert.equal(
    [...workflow.matchAll(/npm ci --include=dev/g)].length,
    2,
    'native lock refresh must retain the locked build toolchain in production mode',
  );
  for (const task of [
    ':app:lintRelease',
    ':app:testReleaseUnitTest',
    ':app:bundleRelease',
  ]) {
    assert.equal([...workflow.matchAll(new RegExp(task, 'g'))].length, 2);
  }
  assert.match(workflow, /--refresh-dependencies/);
  assert.match(workflow, /-ProknDistributionChannel=play/);
  assert.match(workflow, /-ProknBuildProfile=production/);
  assert.match(workflow, /-ProknRequireReleaseSigning=true/);
  assert.match(workflow, /-ProknEnableMinify=true/);
  assert.match(workflow, /-ProknEnableResourceShrink=true/);
  assert.match(workflow, /--write-verification-metadata sha256/);
  assert.equal(
    [...workflow.matchAll(/--write-locks/g)].length,
    2,
    'native lock refresh must update dependency locks before strict release resolution',
  );
  for (const lockfile of [
    'mobile/android/app/gradle.lockfile',
    'mobile/android/buildscript-gradle.lockfile',
    'mobile/android/settings-gradle.lockfile',
  ]) {
    assert.equal([...workflow.matchAll(new RegExp(lockfile, 'g'))].length, 2);
  }
  assert.equal([...workflow.matchAll(/NODE_ENV: production/g)].length, 2);
  assert.equal([...workflow.matchAll(/git rebase origin\/main/g)].length, 3);
  assert.equal([...workflow.matchAll(/git push origin HEAD:main/g)].length, 3);
  assert.match(workflow, /skip_linux_android:/);
  assert.match(
    workflow,
    /reuse_native_locks:\s+description:[^\n]+\s+required: false\s+type: boolean\s+default: false/,
  );
  assert.match(
    workflow,
    /refresh-android:\s+if: \$\{\{ !inputs\.skip_linux_android && !inputs\.reuse_native_locks \}\}/,
  );
  assert.equal(
    [...workflow.matchAll(/if: \$\{\{ !inputs\.reuse_native_locks \}\}/g)]
      .length,
    2,
  );
  assert.match(
    workflow,
    /name: Restore the installed CocoaPods sandbox from committed locks\s+if: \$\{\{ inputs\.reuse_native_locks \}\}\s+working-directory: mobile\s+run: \|\s+cd ios\s+bundle _4\.0\.20_ exec pod install --deployment\s+cd \.\.\s+npm run verify:ios-lock\s+git diff --exit-code -- ios\/Podfile\.lock/,
  );
  assert.match(workflow, /needs\.refresh-android\.result == 'skipped'/);
  assert.match(workflow, /git stash push --include-untracked/);
  assert.match(workflow, /git stash pop/);
});

test('workflow is discoverable from the monorepo root and preserves native checks', () => {
  const workflowPath = path.join(
    root,
    '..',
    '.github',
    'workflows',
    'mobile-ci.yml',
  );
  const workflow = fs.readFileSync(workflowPath, 'utf8');
  const packageJson = JSON.parse(
    fs.readFileSync(path.join(root, 'package.json'), 'utf8'),
  );

  assert.equal(
    fs.existsSync(path.join(root, '.github', 'workflows', 'mobile-ci.yml')),
    false,
  );
  assert.match(workflow, /working-directory: mobile/);
  assert.match(workflow, /working-directory: mobile\/android/);
  assert.match(workflow, /working-directory: mobile\/ios/);
  assert.match(workflow, /cd mobile\r?\n/);
  assert.match(workflow, /runs-on: macos-26/);
  assert.match(workflow, /ruby-version: 3\.3\.6/);
  const gemLock = fs.readFileSync(path.join(root, 'Gemfile.lock'), 'utf8');
  const bundlerVersion = gemLock.match(
    /^BUNDLED WITH\r?\n\s+([0-9.]+)$/m,
  )?.[1];
  assert.equal(bundlerVersion, '4.0.20');
  assert.match(
    workflow,
    new RegExp(
      `gem install bundler --version ${bundlerVersion.replaceAll('.', '\\.')}`,
    ),
  );
  assert.match(
    workflow,
    new RegExp(
      `bundle _${bundlerVersion.replaceAll('.', '\\.')}_ exec pod install`,
    ),
  );
  assert.match(workflow, /git diff --exit-code -- ios\/Podfile\.lock/);
  assert.match(workflow, /name: generated-ios-podfile-lock/);
  assert.equal([...workflow.matchAll(/NODE_ENV: production/g)].length, 3);
  assert.match(workflow, /npm run licenses:native:check/);
  assert.match(
    packageJson.scripts['verify:release'],
    /licenses:native:portable-check/,
  );
});
