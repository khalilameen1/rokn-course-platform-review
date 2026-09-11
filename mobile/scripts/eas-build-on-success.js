'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');

if (process.env.EAS_BUILD_PLATFORM !== 'android') {
  console.log('No Android release evidence is required for this EAS build.');
  process.exit(0);
}
const buildProfile = String(process.env.EAS_BUILD_PROFILE || '');
const releaseProfile = {
  'production-play': {extension: '.aab', channel: 'play', format: 'aab'},
  'production-direct': {extension: '.apk', channel: 'direct', format: 'apk'},
}[buildProfile];
if (!releaseProfile) {
  console.log('Skipping production evidence for a non-production Android profile.');
  process.exit(0);
}

const root = path.resolve(__dirname, '..');
const app = JSON.parse(
  fs.readFileSync(path.join(root, 'app.json'), 'utf8'),
).expo;
const outputRoot = path.join(root, 'android', 'app', 'build', 'outputs');
const collect = (directory, extension, found = []) => {
  if (!fs.existsSync(directory)) return found;
  for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) collect(absolute, extension, found);
    else if (entry.name.endsWith(extension)) found.push(absolute);
  }
  return found;
};
const bundles = collect(outputRoot, releaseProfile.extension).sort(
  (left, right) => fs.statSync(right).mtimeMs - fs.statSync(left).mtimeMs,
);
if (bundles.length !== 1) {
  throw new Error(
    `Expected exactly one EAS Android ${releaseProfile.format.toUpperCase()}, found ${bundles.length}.`,
  );
}

const artifact = bundles[0];
const sourceMap = path.join(
  root,
  'android',
  'app',
  'build',
  'generated',
  'sourcemaps',
  'react',
  'release',
  'index.android.bundle.map',
);
const r8Mapping = path.join(
  root,
  'android',
  'app',
  'build',
  'outputs',
  'mapping',
  'release',
  'mapping.txt',
);
for (const required of [sourceMap, r8Mapping]) {
  if (!fs.existsSync(required) || fs.statSync(required).size === 0) {
    throw new Error(`EAS production build is missing release evidence: ${required}`);
  }
}

const normalizeSha256 = value =>
  String(value || '')
    .replace(/[^0-9a-f]/gi, '')
    .toLowerCase();
const newestBuildTool = toolName => {
  const sdkRoot = process.env.ANDROID_SDK_ROOT || process.env.ANDROID_HOME;
  const buildTools = sdkRoot ? path.join(sdkRoot, 'build-tools') : '';
  if (!buildTools || !fs.existsSync(buildTools)) return toolName;
  const version = fs
    .readdirSync(buildTools, {withFileTypes: true})
    .filter(entry => entry.isDirectory())
    .map(entry => entry.name)
    .sort((left, right) =>
      right.localeCompare(left, undefined, {numeric: true}),
    )
    .find(entry => fs.existsSync(path.join(buildTools, entry, toolName)));
  return version ? path.join(buildTools, version, toolName) : toolName;
};
const certificate =
  releaseProfile.format === 'apk'
    ? spawnSync(
        newestBuildTool('apksigner'),
        ['verify', '--print-certs', artifact],
        {encoding: 'utf8'},
      )
    : spawnSync('keytool', ['-printcert', '-jarfile', artifact], {
        encoding: 'utf8',
      });
if (certificate.status !== 0) {
  throw new Error(
    `Unable to inspect EAS ${releaseProfile.format.toUpperCase()} signer: ${
      certificate.stderr || certificate.stdout
    }`,
  );
}
const certificateOutput = `${certificate.stdout || ''}${certificate.stderr || ''}`;
const signerMatch = certificateOutput.match(
  releaseProfile.format === 'apk'
    ? /certificate SHA-256 digest:\s*([0-9a-f:]+)/i
    : /^\s*SHA256:\s*([0-9a-f:]+)\s*$/im,
);
if (!signerMatch) {
  throw new Error(`EAS ${releaseProfile.format.toUpperCase()} signer SHA-256 is missing.`);
}
const signerSha256 = normalizeSha256(signerMatch[1]);
if (signerSha256.length !== 64 || /CN=Android Debug/i.test(certificateOutput)) {
  throw new Error('Production Android artifact has an invalid or debug signer.');
}
if (releaseProfile.channel === 'direct') {
  const expectedSigner = normalizeSha256(
    process.env.ROKN_ANDROID_APP_SIGNING_SHA256,
  );
  if (expectedSigner.length !== 64) {
    throw new Error(
      'production-direct requires ROKN_ANDROID_APP_SIGNING_SHA256.',
    );
  }
  if (expectedSigner !== signerSha256) {
    throw new Error(
      'Direct APK signer differs from the pinned Play App Signing certificate.',
    );
  }
}

const gitCommit = String(process.env.EAS_BUILD_GIT_COMMIT_HASH || '');
if (!/^[0-9a-f]{40}$/i.test(gitCommit)) {
  throw new Error('EAS_BUILD_GIT_COMMIT_HASH is missing or invalid.');
}
const apiBase = String(process.env.EXPO_PUBLIC_API_URL || '');
if (
  apiBase !==
  'https://rokn.app/api/v1/'
) {
  throw new Error('EAS production build has the wrong API base.');
}
const sha256 = value =>
  crypto.createHash('sha256').update(value).digest('hex');
const digest = sha256(fs.readFileSync(artifact));
const evidenceRoot = path.join(root, 'artifacts', 'eas');
const symbols = path.join(evidenceRoot, 'symbols');
fs.mkdirSync(symbols, {recursive: true});
fs.copyFileSync(sourceMap, path.join(symbols, 'index.android.bundle.map'));
fs.copyFileSync(r8Mapping, path.join(symbols, 'mapping.txt'));

const evidence = {
  name: path.basename(artifact),
  version: String(app.version),
  versionCode: Number(app.android.versionCode),
  applicationId: String(app.android.package),
  channel: releaseProfile.channel,
  profile: 'production',
  format: releaseProfile.format,
  sha256: digest,
  bytes: fs.statSync(artifact).size,
  signerSha256,
  signerRole:
    releaseProfile.format === 'apk' ? 'release-app-signing' : 'play-upload',
  publicDistributionEligible: true,
  apiHost: new URL(apiBase).host,
  apiBase,
  apiBaseSha256: sha256(apiBase),
  apiPathHash: sha256(new URL(apiBase).pathname),
  apiSource: `eas-production-${releaseProfile.channel}`,
  gitCommit: gitCommit.toLowerCase(),
  gitDirty: false,
  easBuildId: String(process.env.EAS_BUILD_ID || ''),
  builtAtUtc: new Date().toISOString(),
};
fs.writeFileSync(
  path.join(evidenceRoot, `${path.basename(artifact)}.json`),
  `${JSON.stringify(evidence, null, 2)}\n`,
  'utf8',
);
console.log(
  JSON.stringify(
    {artifact: evidence.name, sha256: evidence.sha256, gitCommit},
    null,
    2,
  ),
);
