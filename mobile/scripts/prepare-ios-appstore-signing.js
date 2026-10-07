'use strict';

// Standard Apple tools perform signing and verification. The existing plist
// library binds their output to Rokn's approved certificate, team and app.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');

const team = 'VMHVLW746S';
const bundleId = 'com.rokn';
const certificateSha256 = '129c79961b7a2e2d3370965b17e5253cdb5fd2c1f5893e931317afc5a5c702ae';
const apiBase = 'https://rokn.app/api/v1/';
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const requireCondition = (condition, message) => {
  if (!condition) throw new Error(message);
};
const readPlist = file => require('plist').parse(fs.readFileSync(file, 'utf8'));
const validateProfile = (profile, now = new Date()) => {
  const entitlements = profile.Entitlements || {};
  requireCondition(/^[0-9a-f-]{36}$/i.test(profile.UUID || ''), 'Invalid profile UUID');
  requireCondition(profile.TeamIdentifier?.length === 1 && profile.TeamIdentifier[0] === team,
    'Profile belongs to another team');
  requireCondition(new Date(profile.ExpirationDate) > now, 'Profile expired');
  requireCondition(entitlements['application-identifier'] === `${team}.${bundleId}`,
    'Profile does not match Rokn');
  requireCondition(entitlements['get-task-allow'] === false && !profile.ProvisionedDevices &&
    !profile.ProvisionsAllDevices, 'An App Store distribution profile is required');
  requireCondition(entitlements['aps-environment'] === 'production' &&
    entitlements['com.apple.developer.applesignin']?.includes('Default') &&
    entitlements['com.apple.developer.associated-domains']?.includes('*'),
  'Required native capabilities are absent');
  const certificates = (profile.DeveloperCertificates || []).map(data =>
    new crypto.X509Certificate(Buffer.isBuffer(data) ? data : Buffer.from(data, 'base64')));
  const certificate = certificates.find(candidate =>
    candidate.fingerprint256.replaceAll(':', '').toLowerCase() === certificateSha256);
  requireCondition(certificate && new Date(certificate.validFrom) <= now &&
    new Date(certificate.validTo) > now, 'Approved distribution certificate is absent or expired');
  return {team, bundleId, uuid: profile.UUID, profileName: profile.Name,
    certificateSha256, certificateSha1: certificate.fingerprint.replaceAll(':', '')};
};

const run = (directory, mode = 'prepare', platform = process.platform) => {
  requireCondition(platform === 'darwin', 'Signed iOS builds require macOS');
  directory = fs.realpathSync(directory);
  const metadataPath = path.join(directory, 'rokn-signing-public.json');
  const profileDirectories = [
    path.join(os.homedir(), 'Library/Developer/Xcode/UserData/Provisioning Profiles'),
    path.join(os.homedir(), 'Library/MobileDevice/Provisioning Profiles'),
  ];
  if (mode === 'cleanup') {
    if (!fs.existsSync(metadataPath)) return;
    const metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
    requireCondition(/^[0-9a-f-]{36}$/i.test(metadata.uuid), 'Invalid cleanup profile UUID');
    for (const target of profileDirectories) {
      fs.rmSync(path.join(target, `${metadata.uuid}.mobileprovision`), {force: true});
    }
    return;
  }
  const plist = require('plist');
  const profile = readPlist(path.join(directory, 'rokn-profile.plist'));
  const metadata = validateProfile(profile);
  requireCondition(process.env.EXPO_PUBLIC_API_URL === apiBase &&
    process.env.EXPO_PUBLIC_DISTRIBUTION_CHANNEL === 'appstore' &&
    process.env.EXPO_PUBLIC_BUILD_PROFILE === 'production' &&
    process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS === '1', 'Production build environment mismatch');
  const app = JSON.parse(fs.readFileSync(path.join(__dirname, '../app.json'), 'utf8')).expo;
  const command = (program, args) => execFileSync(program, args, {encoding: 'utf8'});
  if (mode === 'prepare') {
    const identities = command('/usr/bin/security', ['find-identity', '-v', '-p', 'codesigning',
      path.join(directory, 'rokn-signing.keychain-db')]);
    requireCondition(identities.includes(metadata.certificateSha1), 'No trusted matching signing identity');
    fs.writeFileSync(metadataPath, `${JSON.stringify(metadata, null, 2)}\n`);
    for (const target of profileDirectories) {
      fs.mkdirSync(target, {recursive: true});
      fs.copyFileSync(path.join(directory, 'rokn.mobileprovision'),
        path.join(target, `${metadata.uuid}.mobileprovision`));
    }
    fs.writeFileSync(path.join(directory, 'rokn-export-options.plist'), plist.build({
      method: 'app-store-connect', destination: 'export', signingStyle: 'manual',
      teamID: team, signingCertificate: metadata.certificateSha1,
      provisioningProfiles: {[bundleId]: metadata.uuid}, manageAppVersionAndBuildNumber: false,
      stripSwiftSymbols: true, uploadSymbols: true,
    }));
    return;
  }
  requireCondition(mode === 'verify', 'Unknown signing action');
  const exportDirectory = path.join(directory, 'rokn-ios-export');
  const ipaNames = fs.readdirSync(exportDirectory).filter(name => name.endsWith('.ipa'));
  requireCondition(ipaNames.length === 1, 'Expected exactly one exported IPA');
  const extracted = fs.mkdtempSync(path.join(directory, 'rokn-ipa-verify-'));
  command('/usr/bin/unzip', ['-q', path.join(exportDirectory, ipaNames[0]), '-d', extracted]);
  const apps = fs.readdirSync(path.join(extracted, 'Payload')).filter(name => name.endsWith('.app'));
  requireCondition(apps.length === 1, 'Expected exactly one app in IPA');
  const appPath = path.join(extracted, 'Payload', apps[0]);
  command('/usr/bin/codesign', ['--verify', '--deep', '--strict', appPath]);
  const info = plist.parse(command('/usr/bin/plutil', ['-convert', 'xml1', '-o', '-',
    path.join(appPath, 'Info.plist')]));
  requireCondition(info.CFBundleIdentifier === bundleId && info.CFBundleShortVersionString === app.version &&
    info.CFBundleVersion === String(app.ios.buildNumber), 'Exported app version or identity mismatch');
  requireCondition(/^iphoneos(?:2[6-9]|[3-9][0-9])\./.test(info.DTSDKName || ''), 'iOS26 SDK or newer required');
  const embeddedProfile = plist.parse(command('/usr/bin/security', ['cms', '-D', '-i',
    path.join(appPath, 'embedded.mobileprovision')]));
  requireCondition(validateProfile(embeddedProfile).uuid === metadata.uuid, 'Exported profile mismatch');
  const entitlements = plist.parse(command('/usr/bin/codesign', ['-d', '--entitlements', ':-', appPath]));
  requireCondition(entitlements['application-identifier'] === `${team}.${bundleId}` &&
    entitlements['get-task-allow'] !== true && entitlements['aps-environment'] === 'production' &&
    entitlements['com.apple.developer.applesignin']?.includes('Default') &&
    entitlements['com.apple.developer.associated-domains']?.includes('applinks:rokn.app'),
  'Exported app entitlements mismatch');
  const certificatePrefix = path.join(extracted, 'distribution-certificate');
  command('/usr/bin/codesign', ['-d', '--extract-certificates', certificatePrefix, appPath]);
  requireCondition(sha256(fs.readFileSync(`${certificatePrefix}0`)) === certificateSha256,
    'Exported app has a different signing certificate');
  requireCondition(fs.readFileSync(path.join(appPath, 'main.jsbundle')).includes(Buffer.from(apiBase)),
    'Exported bundle does not contain the production API');
  const ipa = fs.readFileSync(path.join(exportDirectory, ipaNames[0]));
  fs.writeFileSync(path.join(exportDirectory, 'release-evidence.json'), JSON.stringify({
    ...metadata, version: app.version, buildNumber: app.ios.buildNumber,
    gitCommit: process.env.GITHUB_SHA, configuredApiBase: apiBase, format: 'ipa', profile: 'production',
    apiConfigurationEvidence: 'Pinned workflow environment, source configuration gate and bundle URL presence; effective native runtime endpoint not yet verified',
    nativeRuntimeAccepted: false,
    sdk: info.DTSDKName, artifact: ipaNames[0], sha256: sha256(ipa), bytes: ipa.length,
    builtAtUtc: new Date().toISOString(), uploadedToApple: false,
  }, null, 2) + '\n');
};

if (require.main === module) run(process.argv[2], process.argv[3]);
module.exports = {validateProfile, run};
