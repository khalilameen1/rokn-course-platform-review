'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('production build entry points use the pinned local and cloud toolchains', () => {
  const mobileRoot = path.resolve(__dirname, '../..');
  const expectedNode = fs
    .readFileSync(path.join(mobileRoot, '.node-version'), 'utf8')
    .trim();
  const eas = JSON.parse(
    fs.readFileSync(path.join(mobileRoot, 'eas.json'), 'utf8'),
  );
  for (const profile of [
    'production-play',
    'production-direct',
    'production-ios',
  ]) {
    assert.equal(eas.build[profile].node, expectedNode);
  }
  const script = fs.readFileSync(
    path.join(mobileRoot, 'scripts/build-android-release.ps1'),
    'utf8',
  );
  assert.match(script, /actualNodeVersion -ne \$expectedNodeVersion/);
  assert.match(script, /actualNpmVersion -ne \$expectedNpmVersion/);
  assert.ok(
    script.indexOf('actualNodeVersion -ne') <
      script.indexOf('run verify:release'),
  );
  assert.ok(
    script.indexOf('actualNpmVersion -ne') <
      script.indexOf('run verify:release'),
  );
});
const YAML = require('yaml');

const root = path.resolve(__dirname, '..', '..');
const plist = require('plist');
const provenance = require('../verify-artifact-provenance');
const smoke = require('../run-android-staging-smoke');
const {runPostInstall} = require('../eas-build-post-install');
const {runPreInstall, rubySource, versions} = require('../eas-build-pre-install');
const fixtureUrlName = ['ROKN_SMOKE_FORCED_UPDATE', 'FIXTURE_URL'].join('_');
const fixtureTokenName = ['ROKN_SMOKE', 'FIXTURE_TOKEN'].join('_');

const readPrivacyManifest = () => plist.parse(
  fs.readFileSync(path.join(root, 'ios/Rokn/PrivacyInfo.xcprivacy'), 'utf8'),
);
const privacyEntry = (manifest, type) => {
  const entries = manifest.NSPrivacyCollectedDataTypes.filter(
    entry => entry.NSPrivacyCollectedDataType === `NSPrivacyCollectedDataType${type}`,
  );
  assert.equal(entries.length, 1, `${type} must have exactly one declaration`);
  return entries[0];
};

test('iOS privacy declares account-linked crash and nonfatal diagnostics', () => {
  const manifest = readPrivacyManifest();
  // Sanitizing payloads does not anonymize events that retain an account ID.
  const sentry = fs.readFileSync(path.join(root, 'src/services/sentryTelemetry.ts'), 'utf8');
  const clientEvents = fs.readFileSync(
    path.join(root, '../backend/app/Http/Controllers/API/ClientEventController.php'), 'utf8',
  );
  assert.match(sentry, /event\.user\s*=\s*event\.user\?\.id/);
  assert.match(sentry, /Sentry\.setUser\(/);
  assert.match(clientEvents, /'user_id'\s*=>\s*\$userId/);
  for (const type of ['CrashData', 'OtherDiagnosticData']) {
    const entry = privacyEntry(manifest, type);
    assert.equal(entry.NSPrivacyCollectedDataTypeLinked, true, type);
    // Apple includes crash reduction and technical performance in App Functionality.
    assert.deepEqual(entry.NSPrivacyCollectedDataTypePurposes, [
      'NSPrivacyCollectedDataTypePurposeAppFunctionality',
    ]);
  }
});

test('iOS privacy separates retained product analytics and first-party campaign purposes', () => {
  const manifest = readPrivacyManifest();
  // ProductEventService retains user_id/actor_key; ProductAnalyticsService uses
  // interactions and purchase-completion events for audience and funnel reports.
  // Marketing campaigns select course enrollment audiences and deliver to the
  // selected accounts' device tokens (SendStudentNotification/SendUserPushNotification).
  const expected = {
    UserID: ['AppFunctionality', 'Analytics', 'DeveloperAdvertising'],
    ProductInteraction: ['AppFunctionality', 'Analytics'],
    DeviceID: ['AppFunctionality', 'DeveloperAdvertising'],
    PurchaseHistory: ['AppFunctionality', 'Analytics', 'DeveloperAdvertising'],
  };
  for (const [type, purposes] of Object.entries(expected)) {
    const entry = privacyEntry(manifest, type);
    assert.equal(entry.NSPrivacyCollectedDataTypeLinked, true, type);
    assert.deepEqual(
      [...entry.NSPrivacyCollectedDataTypePurposes].sort(),
      purposes.map(purpose => `NSPrivacyCollectedDataTypePurpose${purpose}`).sort(),
      type,
    );
  }
});

test('iOS privacy retains specific uploads and support disclosures without inventing tracking', () => {
  const manifest = readPrivacyManifest();
  const entries = manifest.NSPrivacyCollectedDataTypes;
  const types = entries.map(entry => entry.NSPrivacyCollectedDataType);
  assert.equal(new Set(types).size, types.length, 'collected data types must be unique');
  for (const type of [
    'Name', 'EmailAddress', 'PhoneNumber', 'PhotosorVideos',
    'CustomerSupport', 'OtherUserContent',
  ]) {
    const entry = privacyEntry(manifest, type);
    assert.equal(entry.NSPrivacyCollectedDataTypeLinked, true, type);
    assert.deepEqual(entry.NSPrivacyCollectedDataTypePurposes, [
      'NSPrivacyCollectedDataTypePurposeAppFunctionality',
    ]);
  }
  assert.equal(manifest.NSPrivacyTracking, false);
  assert.deepEqual(manifest.NSPrivacyTrackingDomains || [], []);
  for (const entry of entries) {
    assert.equal(entry.NSPrivacyCollectedDataTypeTracking, false, entry.NSPrivacyCollectedDataType);
  }
});

test('EAS iOS pins the documented SDK 55 image and existing toolchain', () => {
  const profile = JSON.parse(fs.readFileSync(path.join(root, 'eas.json'), 'utf8'))
    .build['production-ios'];
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
  assert.equal(profile.ios.image, 'macos-sequoia-15.6-xcode-26.2');
  assert.equal(profile.ios.resourceClass, 'medium');
  assert.equal(profile.node, fs.readFileSync(path.join(root, '.node-version'), 'utf8').trim());
  assert.equal(profile.ios.bundler, '4.0.20');
  assert.equal(profile.ios.cocoapods, '1.16.2');
  assert.equal(profile.ios.fastlane, versions.fastlane);
  assert.equal(profile.ios.bundler, versions.bundler);
  assert.equal(profile.ios.cocoapods, versions.cocoapods);
  assert.match(fs.readFileSync(path.join(root, 'Gemfile'), 'utf8'), /ruby "3\.3\.6"/);
  assert.equal(versions.ruby, '3.3.6');
  assert.equal(manifest.scripts['eas-build-pre-install'], 'node scripts/eas-build-pre-install.js');
  assert.equal(manifest.scripts['eas-build-post-install'], 'node scripts/eas-build-post-install.js');
});

const preInstallFixture = (override = {}) => {
  const calls = [];
  return {
    calls,
    options: {
      buildPlatform: 'ios', hostPlatform: 'darwin',
      env: {PATH: '/original/bin', GEM_HOME: '/old/gems', GEM_PATH: '/old/gems'},
      makeTemporaryDirectory: () => '/temporary/rokn-eas-ruby-fixture',
      run: (command, args, options) => {
        calls.push({command, args, options});
        if (args.includes('print RUBY_VERSION')) return '3.3.6';
        if (path.basename(command) === 'pod') return '1.16.2\n';
        return '';
      },
      ...override,
    },
  };
};

test('EAS pre-install isolates exact Ruby and publishes its locked CocoaPods environment', () => {
  const {calls, options} = preInstallFixture();
  runPreInstall(options);
  assert.equal(calls[0].command, 'brew');
  assert.deepEqual(calls[0].args, ['install', 'ruby-build']);
  assert.equal(calls[0].options.env.HOMEBREW_NO_AUTO_UPDATE, '1');
  assert.equal(calls[1].command, 'ruby-build');
  assert.equal(calls[1].args[0], versions.ruby);
  assert.equal(calls[1].options.env.RUBY_BUILD_TARBALL_OVERRIDE, rubySource);
  assert.equal(rubySource, 'https://cache.ruby-lang.org/pub/ruby/3.3/ruby-3.3.6.tar.gz' +
    '#8dc48fffaf270f86f1019053f28e51e4da4cce32a36760a0603a9aee67d7fd8d');
  const install = calls.find(call => path.basename(call.command) === 'bundle' && call.args[1] === 'install');
  assert.equal(install.args[0], '_4.0.20_');
  assert.equal(install.options.env.BUNDLE_FROZEN, 'true');
  assert.notEqual(install.options.env.GEM_HOME, '/old/gems');
  assert.equal(install.options.env.GEM_PATH, install.options.env.GEM_HOME);
  const binstub = calls.find(call => call.args[1] === 'binstubs');
  assert.deepEqual(binstub.args.slice(0, 4), ['_4.0.20_', 'binstubs', 'cocoapods', '--path']);
  assert.equal(install.options.env.PATH.split(':')[0], binstub.args[4]);
  assert.ok(calls.some(call => call.args.join(' ') === 'install fastlane --version 2.231.1 --no-document'));
  const persisted = calls.filter(call => call.command === 'set-env');
  assert.equal(persisted.length, 7);
  for (const {args: [key, value]} of persisted) {
    assert.equal(value, install.options.env[key]);
  }
  assert.equal(install.options.env.POD_INSTALL_DEPLOYMENT, '1');
  assert.deepEqual(calls[calls.length - persisted.length - 1].args,
    ['diff', '--exit-code', '--', 'Gemfile.lock']);
});

test('EAS pre-install never installs tools outside an iOS macOS worker', () => {
  for (const buildPlatform of ['android', '']) {
    runPreInstall({
      buildPlatform, hostPlatform: 'win32',
      makeTemporaryDirectory: () => assert.fail('No temporary runtime for Android/local npm.'),
      run: () => assert.fail('No native commands for Android/local npm.'),
    });
  }
  assert.throws(() => runPreInstall({
    buildPlatform: 'ios', hostPlatform: 'win32',
    run: () => assert.fail('Cannot install macOS tools on Windows.'),
  }), /requires macOS/);
});

test('EAS pre-install fails closed before publishing an incompatible or incomplete runtime', () => {
  for (const failure of ['ruby', 'bundle', 'pod', 'lock']) {
    const fixture = preInstallFixture();
    const defaultRun = fixture.options.run;
    fixture.options.run = (command, args, options) => {
      const result = defaultRun(command, args, options);
      if (failure === 'ruby' && args.includes('print RUBY_VERSION')) return '3.2.0';
      if (failure === 'bundle' && args[1] === 'install') throw new Error('Frozen lock rejected.');
      if (failure === 'pod' && path.basename(command) === 'pod') return '1.15.2';
      if (failure === 'lock' && command === 'git') throw new Error('Gemfile.lock drift.');
      return result;
    };
    assert.throws(() => runPreInstall(fixture.options), /expected|Frozen lock|lock drift/);
    assert.equal(fixture.calls.some(call => call.command === 'set-env'), false);
  }
});

test('EAS iOS verifies the installed lock before the common release gate', () => {
  const calls = [];
  runPostInstall({
    buildPlatform: 'ios', hostPlatform: 'darwin', npmCli: 'test-npm-cli.js',
    run: (command, args) => calls.push([command, args]),
  });
  assert.deepEqual(calls, [
    [process.execPath, ['test-npm-cli.js', 'run', 'verify:ios-lock']],
    ['git', ['diff', '--exit-code', '--', 'ios/Podfile.lock']],
    [process.execPath, ['test-npm-cli.js', 'run', 'verify:release']],
  ]);
});

test('Android and ordinary local hooks never invoke macOS-only verification', () => {
  for (const buildPlatform of ['android', '']) {
    const calls = [];
    runPostInstall({
      buildPlatform, hostPlatform: 'win32', npmCli: 'test-npm-cli.js',
      run: (command, args) => calls.push([command, args]),
    });
    assert.deepEqual(calls, [
      [process.execPath, ['test-npm-cli.js', 'run', 'verify:release']],
    ]);
  }
});

test('EAS hook fails closed on native gate failure or an invalid iOS host', () => {
  let calls = 0;
  assert.throws(() => runPostInstall({
    buildPlatform: 'ios', hostPlatform: 'darwin', npmCli: 'test-npm-cli.js',
    run: () => { calls += 1; throw new Error('Native lock drift.'); },
  }), /Native lock drift/);
  assert.equal(calls, 1);
  calls = 0;
  assert.throws(() => runPostInstall({
    buildPlatform: 'ios', hostPlatform: 'darwin', npmCli: 'test-npm-cli.js',
    run: command => {
      calls += 1;
      if (command === 'git') throw new Error('Podfile.lock changed.');
    },
  }), /Podfile.lock changed/);
  assert.equal(calls, 2);
  assert.throws(() => runPostInstall({
    buildPlatform: 'ios', hostPlatform: 'win32', npmCli: 'test-npm-cli.js',
    run: () => assert.fail('No native command should run on Windows.'),
  }), /requires macOS/);
});

const mobileWorkflow = () => YAML.parse(fs.readFileSync(
  path.join(root, '..', '.github', 'workflows', 'mobile-ci.yml'),
  'utf8',
));

// These job conditions use only booleans/comparisons shared by Actions and JS.
// Evaluate the parsed source, not a duplicate of its intended selection rule.
const selectedWorkflowJobs = (workflow, eventName, inputs = {}) =>
  Object.entries(workflow.jobs).filter(([, job]) => {
    if (job.if === undefined) return true;
    if (typeof job.if === 'boolean') return job.if;
    const expression = job.if.replace(/^\s*\$\{\{([\s\S]*)\}\}\s*$/, '$1');
    assert.match(expression, /^[\w\s.'"!=&|()]+$/);
    return Boolean(vm.runInNewContext(expression, {
      github: {event_name: eventName},
      inputs,
    }, {timeout: 1000}));
  }).map(([name]) => name);

test('manual iOS-only input is an explicit optional boolean defaulting to false', () => {
  const input = mobileWorkflow().on.workflow_dispatch.inputs.ios_only;
  assert.ok(input, 'workflow_dispatch must expose ios_only');
  assert.equal(input.type, 'boolean');
  assert.equal(input.default, false);
  assert.equal(input.required, false);
});

test('actual job conditions isolate iOS only on explicit manual selection', () => {
  const workflow = mobileWorkflow();
  const baseline = ['javascript', 'android-native', 'ios-native'];
  for (const [eventName, inputs, expected] of [
    ['push', {}, baseline],
    ['pull_request', {}, baseline],
    ['push', {ios_only: true, run_staging_smoke: true}, baseline],
    ['workflow_dispatch', {}, baseline],
    ['workflow_dispatch', {ios_only: false, run_staging_smoke: false}, baseline],
    ['workflow_dispatch', {run_staging_smoke: true}, [...baseline, 'android-staging-smoke']],
    ['workflow_dispatch', {ios_only: true}, ['ios-native']],
    ['workflow_dispatch', {ios_only: true, run_staging_smoke: true}, ['ios-native']],
  ]) {
    assert.deepEqual(selectedWorkflowJobs(workflow, eventName, inputs), expected,
      `${eventName} ${JSON.stringify(inputs)}`);
  }
  assert.equal(workflow.jobs['ios-native'].needs, undefined,
    'isolated iOS must not depend on skipped JavaScript or Android jobs');
});

test('isolated iOS gate remains unsigned CI compilation without app distribution', () => {
  const ios = mobileWorkflow().jobs['ios-native'];
  assert.equal(ios.env.EXPO_PUBLIC_API_URL, 'https://ci.invalid/api/v1/');
  assert.equal(ios.env.EXPO_PUBLIC_BUILD_PROFILE, 'production');
  const commands = ios.steps.map(step => step.run || '').join('\n');
  assert.match(commands, /xcodebuild[\s\S]*-sdk iphoneos[\s\S]*CODE_SIGNING_ALLOWED=NO CODE_SIGNING_REQUIRED=NO build/);
  assert.doesNotMatch(commands, /-exportArchive|\barchive\b|\baltool\b|\bfastlane\b|\bdeploy\b|\beas\s+(?:build|submit)\b/i);
  assert.doesNotMatch(JSON.stringify(ios), /secrets\./);
  for (const step of ios.steps.filter(candidate => candidate.uses)) {
    assert.match(step.uses, /^(?:actions\/(?:checkout|setup-node|upload-artifact)|ruby\/setup-ruby)@/,
      'the compile gate must not introduce an app-distribution action');
  }
  const uploads = ios.steps.filter(step => /upload/i.test(step.uses || ''));
  assert.equal(uploads.length, 1, 'only the existing dependency-lock diagnostic upload is permitted');
  assert.match(uploads[0].uses, /^actions\/upload-artifact@/);
  assert.equal(uploads[0].with.path, 'mobile/ios/Podfile.lock');
  assert.match(uploads[0].if, /failure\(\).*ios_lock\.outcome == 'failure'/);
});

test('selected manual modes isolate concurrency and preserve active staging leases', () => {
  const workflow = mobileWorkflow();
  const groupFor = (eventName, inputs, ref = 'refs/heads/main') =>
    workflow.concurrency.group.replace(/\$\{\{([\s\S]*?)\}\}/g, (_, expression) => {
      assert.match(expression, /^[\w\s.'"!=&|()\-]+$/);
      return String(vm.runInNewContext(expression, {
        github: {event_name: eventName, ref},
        inputs,
      }, {timeout: 1000}));
    });
  for (const [eventName, inputs] of [
    ['push', {}],
    ['pull_request', {}],
    ['push', {ios_only: true}],
    ['workflow_dispatch', {}],
    ['workflow_dispatch', {ios_only: false}],
  ]) {
    assert.equal(groupFor(eventName, inputs), 'mobile-refs/heads/main');
  }
  const iosGroup = groupFor('workflow_dispatch', {ios_only: true});
  assert.equal(iosGroup, 'mobile-refs/heads/main-ios-only');
  assert.notEqual(iosGroup, groupFor('push', {}));
  assert.equal(groupFor('workflow_dispatch', {ios_only: true, run_staging_smoke: true}), iosGroup);
  assert.notEqual(groupFor('workflow_dispatch', {ios_only: true}, 'refs/heads/other'), iosGroup);
  const stagingGroup = groupFor('workflow_dispatch', {run_staging_smoke: true});
  assert.equal(stagingGroup, 'mobile-refs/heads/main-staging-smoke');
  assert.notEqual(stagingGroup, iosGroup);
  assert.notEqual(stagingGroup, groupFor('push', {}));
  const cancellation = workflow.concurrency['cancel-in-progress']
    .replace(/^\s*\$\{\{([\s\S]*)\}\}\s*$/, '$1');
  assert.match(cancellation, /^[\w\s.'"!=&|()]+$/);
  for (const [eventName, inputs, expected] of [
    ['push', {}, true],
    ['pull_request', {}, true],
    ['workflow_dispatch', {}, true],
    ['workflow_dispatch', {ios_only: false, run_staging_smoke: false}, true],
    ['workflow_dispatch', {run_staging_smoke: true}, false],
    ['workflow_dispatch', {ios_only: true}, true],
    ['workflow_dispatch', {ios_only: true, run_staging_smoke: true}, true],
  ]) {
    assert.equal(vm.runInNewContext(cancellation, {
      github: {event_name: eventName}, inputs,
    }, {timeout: 1000}), expected, `${eventName} ${JSON.stringify(inputs)}`);
  }
});

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
    'https://rokn.app/api/v1/';
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
      'https://rokn.app/api/v1/';
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
        apiHost: 'rokn.app',
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
