'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const YAML = require('yaml');
const {validateProfile} = require('../prepare-ios-appstore-signing');
const secretScanner = require('../verify-repository-secrets');
const root = path.resolve(__dirname, '../..');
const workflow = YAML.parse(fs.readFileSync(path.join(root, '../.github/workflows/mobile-ci.yml'), 'utf8'));
const selected = (eventName, inputs) => Object.entries(workflow.jobs).filter(([, job]) =>
  !job.if || vm.runInNewContext(job.if.replace(/^\$\{\{\s*|\s*\}\}$/g, ''),
    {github: {event_name: eventName}, inputs})).map(([name]) => name);

test('signing is explicit manual iOS-only mode and never changes ordinary CI selection', () => {
  const baseline = ['javascript', 'android-native', 'ios-native'];
  for (const eventName of ['push', 'pull_request']) {
    assert.deepEqual(selected(eventName, {ios_only: true, build_signed_ios: true}), baseline);
  }
  assert.deepEqual(selected('workflow_dispatch', {build_signed_ios: true}), baseline);
  assert.deepEqual(selected('workflow_dispatch', {ios_only: true}), ['ios-native']);
  assert.deepEqual(selected('workflow_dispatch', {ios_only: true, build_signed_ios: true}),
    ['ios-signing-source', 'ios-appstore']);
  assert.equal(workflow.on.workflow_dispatch.inputs.build_signed_ios.default, false);
});

test('signed macOS build cannot start until the exact-source full history audit succeeds on Ubuntu', () => {
  const audit = workflow.jobs['ios-signing-source'];
  const build = workflow.jobs['ios-appstore'];
  assert.equal(build.needs, 'ios-signing-source');
  assert.equal(audit['runs-on'], workflow.jobs.javascript['runs-on']);
  assert.equal(audit.steps[0].with['fetch-depth'], 0);
  assert.equal(audit.steps[1].with['node-version'], '24.19.0');
  const commands = audit.steps.map(step => step.run || '').join('\n');
  assert.match(commands, /git rev-parse HEAD\).*EXPECTED_COMMIT/);
  assert.match(commands, /git rev-parse HEAD\).*GITHUB_SHA/);
  assert.match(commands, /node scripts\/verify-repository-secrets\.js --history/);
  assert.doesNotMatch(JSON.stringify(audit), /\$\{\{\s*secrets\.|continue-on-error|always\(\)|verify:release|npm ci/);
  assert.doesNotMatch(build.if, /always\(\)/);
});

test('signed build uses existing exact-source gate, no Apple account key or distribution command', () => {
  const job = workflow.jobs['ios-appstore'];
  const commands = job.steps.map(step => step.run || '').join('\n');
  assert.equal(job.env.EXPO_PUBLIC_API_URL, 'https://rokn.app/api/v1/');
  assert.match(commands, /git rev-parse HEAD\).*EXPECTED_COMMIT/);
  assert.match(commands, /pod install --deployment/);
  assert.match(commands, /npm run verify:ios-lock/);
  assert.match(commands, /npm run licenses:native:check/);
  assert.match(commands, /-archivePath.*archive/);
  assert.match(commands, /-exportArchive/);
  assert.doesNotMatch(commands, /CODE_SIGNING_ALLOWED=NO|-allowProvisioningUpdates|altool|notarytool/);
  const secrets = [...JSON.stringify(job).matchAll(/secrets\.([A-Z0-9_]+)/g)].map(match => match[1]).sort();
  assert.deepEqual(secrets, ['ROKN_IOS_APPSTORE_PROFILE_BASE64',
    'ROKN_IOS_DISTRIBUTION_P12_BASE64', 'ROKN_IOS_DISTRIBUTION_P12_PASSWORD']);
  const upload = job.steps.find(step => /actions\/upload-artifact/.test(step.uses || ''));
  assert.equal(upload.with.path, '${{ runner.temp }}/rokn-ios-export/');
  const cleanup = job.steps.find(step => step.name === 'Remove signing material from the disposable runner');
  assert.equal(cleanup.if, '${{ always() }}');
});

test('new signing secret names remain classified and reject literal credentials', () => {
  const names = ['ROKN_IOS_DISTRIBUTION_P12_BASE64', 'ROKN_IOS_DISTRIBUTION_P12_PASSWORD',
    'ROKN_IOS_APPSTORE_PROFILE_BASE64', 'BUILD_CERTIFICATE_BASE64',
    'BUILD_PROVISION_PROFILE_BASE64', 'P12_PASSWORD', 'KEYCHAIN_PASSWORD'];
  for (const name of names) {
    assert.ok(secretScanner.secretNames.includes(name));
    assert.ok(secretScanner.scanContents('workflow.yml', `${name}=not-a-placeholder-credential`).includes(
      'non_placeholder_secret_assignment'));
  }
});

test('cleanup before dependency installation needs no plist module', () => {
  const source = fs.readFileSync(path.join(root, 'scripts/prepare-ios-appstore-signing.js'), 'utf8');
  let plistRequested = false;
  const context = {module: {exports: {}}, process, __dirname: path.join(root, 'scripts')};
  context.require = Object.assign(name => {
    if (name === 'plist') { plistRequested = true; throw new Error('not installed'); }
    return require(name);
  }, {main: {}});
  vm.runInNewContext(source, context);
  context.module.exports.run(root, 'cleanup', 'darwin');
  assert.equal(plistRequested, false);
});

test('certificate extraction binds the optional codesign prefix to its option', () => {
  const source = fs.readFileSync(path.join(root, 'scripts/prepare-ios-appstore-signing.js'), 'utf8');
  const invocation = source.match(/command\('\/usr\/bin\/codesign', (\['-d', `--extract-certificates=[^\n]+\])/);
  assert.ok(invocation, 'Extraction must not treat the prefix as another bundle');
  const certificatePrefix = '/runner temp/ipa verification/distribution-certificate';
  const appPath = '/runner temp/ipa verification/Payload/Rokn.app';
  const args = vm.runInNewContext(invocation[1], {certificatePrefix, appPath});
  assert.deepEqual(Array.from(args), ['-d', `--extract-certificates=${certificatePrefix}`, appPath]);
  assert.match(source, /sha256\(fs\.readFileSync\(`\$\{certificatePrefix\}0`\)\) === certificateSha256/);
});

const profile = () => ({
  UUID: '00000000-0000-0000-0000-000000000001',
  TeamIdentifier: ['VMHVLW746S'],
  ExpirationDate: new Date('2027-10-07T00:00:00Z'),
  Entitlements: {'application-identifier': 'VMHVLW746S.com.rokn', 'get-task-allow': false,
    'aps-environment': 'production', 'com.apple.developer.applesignin': ['Default'],
    'com.apple.developer.associated-domains': ['*']},
});
const now = new Date('2026-10-07T00:00:00Z');
test('profile validation rejects everyday wrong team, app, expiry, type and capabilities', () => {
  for (const [change, message] of [
    [item => {item.TeamIdentifier = ['OTHERTEAM'];}, /another team/],
    [item => {item.ExpirationDate = new Date('2025-01-01');}, /expired/],
    [item => {item.Entitlements['application-identifier'] = 'VMHVLW746S.com.other';}, /match Rokn/],
    [item => {item.ProvisionedDevices = ['test-device'];}, /App Store/],
    [item => {item.Entitlements['get-task-allow'] = true;}, /App Store/],
    [item => {item.Entitlements['aps-environment'] = 'development';}, /capabilities/],
    [item => {item.UUID = '../../other';}, /UUID/],
  ]) {
    const item = profile(); change(item);
    assert.throws(() => validateProfile(item, now), message);
  }
  assert.throws(() => validateProfile(profile(), now), /certificate is absent/);
});
