'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');

const PRODUCTION_API_BASE =
  'https://rokn.app/api/v1/';
const PRODUCTION_API_HOST = new URL(PRODUCTION_API_BASE).host;
const PRODUCTION_APPLICATION_ID = 'com.rokn';
const SHA256_PATTERN = /^[0-9a-f]{64}$/;

const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');

const normalizeSha256 = value => {
  const normalized = String(value || '')
    .trim()
    .replace(/^sha-?256\s*[:=]?\s*/i, '')
    .replaceAll(':', '')
    .toLowerCase();
  return SHA256_PATTERN.test(normalized) ? normalized : '';
};

const signerDigestsFromOutput = output =>
  [
    ...String(output || '').matchAll(
      /certificate SHA-256 digest:\s*([0-9a-f:]+)/gi,
    ),
  ]
    .map(match => normalizeSha256(match[1]))
    .filter(Boolean);

const keytoolDigestFromOutput = output => {
  const match = String(output || '').match(/SHA256:\s*([0-9a-f:]+)/i);
  return normalizeSha256(match?.[1]);
};

const parseApkBadging = output => {
  const match = String(output || '').match(
    /package:\s+name='([^']+)'\s+versionCode='([^']+)'/,
  );
  return match ? {applicationId: match[1], versionCode: match[2]} : null;
};

const newestBuildTool = toolName => {
  const sdkRoot = process.env.ANDROID_SDK_ROOT || process.env.ANDROID_HOME;
  if (!sdkRoot) return toolName;
  const buildTools = path.join(sdkRoot, 'build-tools');
  if (!fs.existsSync(buildTools)) return toolName;
  const executable =
    process.platform === 'win32' ? `${toolName}.bat` : toolName;
  const fallbackExecutable =
    process.platform === 'win32' ? `${toolName}.exe` : toolName;
  const versions = fs
    .readdirSync(buildTools, {withFileTypes: true})
    .filter(entry => entry.isDirectory())
    .map(entry => entry.name)
    .sort((left, right) =>
      right.localeCompare(left, undefined, {numeric: true}),
    );
  for (const version of versions) {
    for (const candidate of [executable, fallbackExecutable]) {
      const resolved = path.join(buildTools, version, candidate);
      if (fs.existsSync(resolved)) return resolved;
    }
  }
  return toolName;
};

const run = (command, args) => {
  const result = spawnSync(command, args, {encoding: 'utf8'});
  return {
    status: result.status,
    error: result.error,
    output: `${result.stdout || ''}${result.stderr || ''}`,
  };
};

const runApkSigner = (command, args) => {
  if (process.platform !== 'win32' || !/\.bat$/i.test(command)) {
    return run(command, args);
  }
  const jar = path.join(path.dirname(command), 'lib', 'apksigner.jar');
  if (!fs.existsSync(jar)) {
    return {
      status: null,
      error: new Error(`apksigner.jar was not found beside ${command}.`),
      output: '',
    };
  }
  return run(process.env.ROKN_JAVA || 'java', ['-jar', jar, ...args]);
};

const inspectApk = artifact => {
  const apkSigner = process.env.ROKN_APKSIGNER || newestBuildTool('apksigner');
  const signature = runApkSigner(apkSigner, [
    'verify',
    '--verbose',
    '--print-certs',
    artifact,
  ]);
  if (signature.error || signature.status !== 0) {
    throw new Error(
      `apksigner could not verify the APK: ${
        signature.error?.message || signature.output.trim() || 'unknown error'
      }`,
    );
  }
  if (/certificate DN:.*CN=Android Debug/i.test(signature.output)) {
    throw new Error('Production APK is signed with the Android debug key.');
  }
  const signerSha256 = signerDigestsFromOutput(signature.output);
  if (!signerSha256.length) {
    throw new Error('apksigner did not report an APK signer SHA-256 digest.');
  }
  if (signerSha256.length !== 1) {
    throw new Error('Production APK must have exactly one current signer.');
  }

  const aapt = process.env.ROKN_AAPT || newestBuildTool('aapt');
  const badging = run(aapt, ['dump', 'badging', artifact]);
  if (badging.error || badging.status !== 0) {
    throw new Error(
      `aapt could not inspect the APK manifest: ${
        badging.error?.message || badging.output.trim() || 'unknown error'
      }`,
    );
  }
  const manifest = parseApkBadging(badging.output);
  if (!manifest) {
    throw new Error('aapt did not report the APK application id/versionCode.');
  }
  return {...manifest, signerSha256};
};

const inspectAabSigner = artifact => {
  const keytool = process.env.ROKN_KEYTOOL || 'keytool';
  const result = run(keytool, ['-printcert', '-jarfile', artifact]);
  if (result.error || result.status !== 0) {
    throw new Error(
      `keytool could not inspect the AAB signer: ${
        result.error?.message || result.output.trim() || 'unknown error'
      }`,
    );
  }
  if (/Owner:.*CN=Android Debug/i.test(result.output)) {
    throw new Error('Production AAB is signed with the Android debug key.');
  }
  const signerSha256 = keytoolDigestFromOutput(result.output);
  if (!signerSha256) {
    throw new Error('keytool did not report an AAB signer SHA-256 digest.');
  }
  return signerSha256;
};

const requiredPinnedValues = {
  sha256: 'ROKN_PROVENANCE_EXPECTED_SHA256',
  versionCode: 'ROKN_PROVENANCE_EXPECTED_VERSION_CODE',
  signerSha256: 'ROKN_PROVENANCE_EXPECTED_SIGNER_SHA256',
  gitCommit: 'ROKN_PROVENANCE_EXPECTED_GIT_COMMIT',
  profile: 'ROKN_PROVENANCE_EXPECTED_PROFILE',
  channel: 'ROKN_PROVENANCE_EXPECTED_CHANNEL',
  format: 'ROKN_PROVENANCE_EXPECTED_FORMAT',
  applicationId: 'ROKN_PROVENANCE_EXPECTED_APPLICATION_ID',
  apiBase: 'ROKN_PROVENANCE_EXPECTED_API_BASE',
};

const readPinnedValues = () =>
  Object.fromEntries(
    Object.entries(requiredPinnedValues).map(([key, envName]) => [
      key,
      String(process.env[envName] || '').trim(),
    ]),
  );

const apiEvidenceFailures = evidence => {
  if (evidence.apiBase !== PRODUCTION_API_BASE) {
    return [`Production API base must be ${PRODUCTION_API_BASE}.`];
  }
  const failures = [];
  const apiUrl = new URL(evidence.apiBase);
  if (normalizeSha256(evidence.apiBaseSha256) !== sha256(evidence.apiBase)) {
    failures.push('Production API base SHA-256 does not match apiBase.');
  }
  if (normalizeSha256(evidence.apiPathHash) !== sha256(apiUrl.pathname)) {
    failures.push('Production API path SHA-256 does not match apiBase.');
  }
  return failures;
};

const verify = (
  artifactArgument,
  metadataArgument,
  inspectors = {inspectAabSigner, inspectApk},
) => {
  const artifact = path.resolve(artifactArgument);
  const metadata = path.resolve(metadataArgument || `${artifact}.json`);
  const failures = [];
  if (!fs.existsSync(artifact))
    failures.push(`Artifact does not exist: ${artifact}`);
  if (!fs.existsSync(metadata)) {
    failures.push(`Provenance sidecar does not exist: ${metadata}`);
  }
  if (failures.length) return {failures};

  let evidence;
  try {
    evidence = JSON.parse(fs.readFileSync(metadata, 'utf8'));
  } catch (error) {
    return {failures: [`Invalid provenance JSON: ${error.message}`]};
  }
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) {
    return {failures: ['Provenance JSON must be an object.']};
  }

  const digest = sha256(fs.readFileSync(artifact));
  const size = fs.statSync(artifact).size;
  const basename = path.basename(artifact);
  const extension = path.extname(artifact).slice(1).toLowerCase();
  const pinned = readPinnedValues();
  const requirePinned = process.env.ROKN_PROVENANCE_REQUIRE_PINNED === '1';

  if (requirePinned) {
    Object.entries(requiredPinnedValues).forEach(([key, envName]) => {
      if (!pinned[key])
        failures.push(`Pinned candidate input is missing: ${envName}`);
    });
  }
  if (evidence.name !== basename) {
    failures.push('Sidecar name does not match artifact filename.');
  }
  if (normalizeSha256(evidence.sha256) !== digest) {
    failures.push('SHA-256 digest does not match artifact.');
  }
  if (Number(evidence.bytes) !== size) {
    failures.push('Sidecar byte count does not match artifact.');
  }
  if (!/^[0-9a-f]{40}$/i.test(String(evidence.gitCommit || ''))) {
    failures.push('Provenance lacks a full Git commit SHA.');
  }
  if (evidence.gitDirty !== false) {
    failures.push('Only artifacts built from a clean tree may be promoted.');
  }
  if (evidence.profile !== 'production') {
    failures.push('Only production-profile artifacts may be promoted.');
  }
  if (!['play', 'direct'].includes(evidence.channel)) {
    failures.push('Artifact channel must be play or direct.');
  }
  if (evidence.applicationId !== PRODUCTION_APPLICATION_ID) {
    failures.push(`Production application id must be ${PRODUCTION_APPLICATION_ID}.`);
  }
  if (evidence.publicDistributionEligible !== true) {
    failures.push('Artifact is not marked eligible for public distribution.');
  }
  const expectedSignerRole =
    evidence.channel === 'direct' ? 'release-app-signing' : 'play-upload';
  if (evidence.signerRole !== expectedSignerRole) {
    failures.push(
      `Artifact signer role must be ${expectedSignerRole} for ${evidence.channel}.`,
    );
  }
  const versionCode = Number(evidence.versionCode);
  if (
    !/^\d+$/.test(String(evidence.versionCode || '')) ||
    !Number.isSafeInteger(versionCode) ||
    versionCode < 1
  ) {
    failures.push('Android versionCode is missing or invalid.');
  }
  if (
    !['apk', 'aab'].includes(evidence.format) ||
    evidence.format !== extension
  ) {
    failures.push('Artifact format does not match the file extension.');
  }
  if (!normalizeSha256(evidence.signerSha256)) {
    failures.push('Provenance lacks a valid signer SHA-256 digest.');
  }
  if (evidence.apiHost !== PRODUCTION_API_HOST) {
    failures.push(`Production API host must be ${PRODUCTION_API_HOST}.`);
  }
  if (!Number.isFinite(Date.parse(evidence.builtAtUtc))) {
    failures.push('Provenance lacks a valid UTC build timestamp.');
  }
  failures.push(...apiEvidenceFailures(evidence));

  const pinnedComparisons = [
    ['sha256', digest, normalizeSha256(pinned.sha256)],
    ['versionCode', String(evidence.versionCode), pinned.versionCode],
    [
      'signer SHA-256',
      normalizeSha256(evidence.signerSha256),
      normalizeSha256(pinned.signerSha256),
    ],
    [
      'Git commit',
      String(evidence.gitCommit || '').toLowerCase(),
      pinned.gitCommit.toLowerCase(),
    ],
    ['profile', evidence.profile, pinned.profile],
    ['channel', evidence.channel, pinned.channel],
    ['format', evidence.format, pinned.format],
    ['API base', evidence.apiBase, pinned.apiBase],
  ];
  pinnedComparisons.forEach(([label, actual, expected]) => {
    if (expected && actual !== expected) {
      failures.push(`Pinned ${label} does not match the candidate.`);
    }
  });

  let inspected;
  try {
    if (extension === 'apk') {
      inspected = inspectors.inspectApk(artifact);
      if (inspected.versionCode !== String(evidence.versionCode)) {
        failures.push('APK manifest versionCode does not match provenance.');
      }
      if (
        inspected.applicationId !== PRODUCTION_APPLICATION_ID ||
        inspected.applicationId !== evidence.applicationId
      ) {
        failures.push('APK application id is not the production package.');
      }
      if (
        pinned.applicationId &&
        inspected.applicationId !== pinned.applicationId
      ) {
        failures.push(
          'APK application id does not match the pinned candidate.',
        );
      }
      const sidecarSigner = normalizeSha256(evidence.signerSha256);
      if (!inspected.signerSha256.includes(sidecarSigner)) {
        failures.push('APK signer does not match provenance.');
      }
      const expectedSigner = normalizeSha256(pinned.signerSha256);
      if (expectedSigner && !inspected.signerSha256.includes(expectedSigner)) {
        failures.push('APK signer does not match the pinned release signer.');
      }
    } else if (extension === 'aab') {
      const signerSha256 = inspectors.inspectAabSigner(artifact);
      inspected = {signerSha256: [signerSha256]};
      if (signerSha256 !== normalizeSha256(evidence.signerSha256)) {
        failures.push('AAB signer does not match provenance.');
      }
      const expectedSigner = normalizeSha256(pinned.signerSha256);
      if (expectedSigner && signerSha256 !== expectedSigner) {
        failures.push('AAB signer does not match the pinned release signer.');
      }
    }
  } catch (error) {
    failures.push(error.message);
  }

  return {
    failures,
    summary: {
      artifact: basename,
      sha256: digest,
      signerSha256: inspected?.signerSha256,
      version: evidence.version,
      versionCode: evidence.versionCode,
      applicationId: inspected?.applicationId,
      channel: evidence.channel,
      profile: evidence.profile,
      apiBase: evidence.apiBase,
      gitCommit: evidence.gitCommit,
    },
  };
};

const main = () => {
  const [artifactArgument, metadataArgument] = process.argv.slice(2);
  if (!artifactArgument) {
    console.error(
      'Usage: npm run verify:provenance -- <artifact.apk|artifact.aab> [artifact.json]',
    );
    process.exitCode = 2;
    return;
  }
  const result = verify(artifactArgument, metadataArgument);
  if (result.failures.length) {
    console.error(
      `Artifact provenance verification failed (${result.failures.length}):`,
    );
    result.failures.forEach(message => console.error(`- ${message}`));
    process.exitCode = 1;
    return;
  }
  console.log(JSON.stringify(result.summary, null, 2));
};

if (require.main === module) main();

module.exports = {
  apiEvidenceFailures,
  keytoolDigestFromOutput,
  normalizeSha256,
  parseApkBadging,
  sha256,
  signerDigestsFromOutput,
  verify,
};
