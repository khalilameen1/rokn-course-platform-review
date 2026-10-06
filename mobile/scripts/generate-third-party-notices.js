'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = path.resolve(__dirname, '..');
const LOCK_PATH = path.join(ROOT, 'package-lock.json');
const SNAPSHOT_PATH = path.join(
  ROOT,
  'scripts',
  'licenses',
  'third-party-package-notices.generated.json',
);
const MARKDOWN_PATH = path.join(ROOT, 'THIRD_PARTY_NOTICES.md');
const APP_DATA_PATH = path.join(
  ROOT,
  'src',
  'data',
  'thirdPartyNotices.generated.json',
);
const ANDROID_NOTICE_PATH = path.join(
  ROOT,
  'android',
  'app',
  'src',
  'main',
  'assets',
  'THIRD_PARTY_NOTICES.md',
);
const IOS_NOTICE_PATH = path.join(
  ROOT,
  'ios',
  'Rokn',
  'THIRD_PARTY_NOTICES.md',
);

const ALLOWED_LICENSES = new Set([
  '0BSD',
  'Apache-2.0',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'BlueOak-1.0.0',
  'CC-BY-4.0',
  'CC0-1.0',
  'FSL-1.1-MIT',
  'ISC',
  'MIT',
  'MPL-2.0',
  'Python-2.0',
  'Unlicense',
]);

// These exact packages publish the standard terms used only when an upstream
// npm tarball publishes license metadata but no standalone legal document.
// Package-specific files always take precedence and are never replaced by
// these texts.
const CANONICAL_LICENSE_SOURCES = {
  '0BSD': ['node_modules/jsc-safe-url', 'LICENSE'],
  'Apache-2.0': ['node_modules/baseline-browser-mapping', 'LICENSE.txt'],
  'BSD-2-Clause': ['node_modules/css-select', 'LICENSE'],
  'BSD-3-Clause': ['node_modules/@sinonjs/commons', 'LICENSE'],
  'BlueOak-1.0.0': [
    'node_modules/@expo/config-plugins/node_modules/glob',
    'LICENSE.md',
  ],
  'CC-BY-4.0': ['node_modules/caniuse-lite', 'LICENSE'],
  'CC0-1.0': ['node_modules/mdn-data', 'LICENSE'],
  'FSL-1.1-MIT': ['node_modules/@sentry/cli', 'LICENSE'],
  ISC: ['node_modules/@isaacs/ttlcache', 'LICENSE'],
  MIT: ['node_modules/@babel/code-frame', 'LICENSE'],
  'MPL-2.0': ['node_modules/lightningcss', 'LICENSE'],
  'Python-2.0': ['node_modules/argparse', 'LICENSE'],
  Unlicense: ['node_modules/big-integer', 'LICENSE'],
};

// These exact tarballs omit their monorepo LICENSE. Retain Expo's actual
// attribution from their published gitHead instead of another MIT author's
// copyright. Package-root legal files still take precedence.
const UPSTREAM_LICENSE_SOURCES = new Map(
  [
    ['expo-image@55.0.11', 'bb1d4bd298e5bcaff86b04aabca7c56659e57138'],
    ['expo-splash-screen@55.0.25', '856b99321eeb04bd528b33f90c0e7fa2859a1fcb'],
  ].map(([coordinate, gitHead]) => [
    coordinate,
    {
      gitHead,
      path: 'scripts/licenses/upstream/expo-expo-LICENSE',
      sha256:
        '371567d5d8999eeffba61ddbcb60ffbe4f25c3f165f1772e2c66befd0251bffa',
      sourceUrl: `https://github.com/expo/expo/blob/${gitHead}/LICENSE`,
    },
  ]),
);

// Each coordinate below was inspected because its exact npm package root
// contains no LICENSE/LICENCE/COPYING/NOTICE/COPYRIGHT file. A new or changed
// coordinate fails closed and requires a fresh review instead of inheriting a
// broad family exception.
const LEGAL_FILE_ABSENCE_ALLOWLIST = new Set([
  '@firebase/ai@2.14.0',
  '@firebase/analytics-compat@0.2.29',
  '@firebase/analytics-types@0.8.4',
  '@firebase/analytics@0.10.23',
  '@firebase/app-check-compat@0.4.6',
  '@firebase/app-check-interop-types@0.3.4',
  '@firebase/app-check-types@0.5.4',
  '@firebase/app-check@0.13.0',
  '@firebase/app-compat@0.5.16',
  '@firebase/app-types@0.9.5',
  '@firebase/app@0.16.0',
  '@firebase/auth-compat@0.6.9',
  '@firebase/auth-interop-types@0.2.5',
  '@firebase/auth-types@0.13.1',
  '@firebase/auth@1.13.4',
  '@firebase/component@0.7.4',
  '@firebase/data-connect@0.7.3',
  '@firebase/database-compat@2.1.6',
  '@firebase/database-types@1.0.21',
  '@firebase/database@1.1.4',
  '@firebase/firestore-compat@0.4.12',
  '@firebase/firestore-types@3.0.4',
  '@firebase/firestore@4.17.0',
  '@firebase/functions-compat@0.4.6',
  '@firebase/functions-types@0.6.4',
  '@firebase/functions@0.13.6',
  '@firebase/installations-compat@0.2.23',
  '@firebase/installations-types@0.5.4',
  '@firebase/installations@0.6.23',
  '@firebase/logger@0.5.1',
  '@firebase/messaging-compat@0.2.28',
  '@firebase/messaging-interop-types@0.2.5',
  '@firebase/messaging@0.13.1',
  '@firebase/performance-compat@0.2.26',
  '@firebase/performance-types@0.2.4',
  '@firebase/performance@0.7.13',
  '@firebase/remote-config-compat@0.2.28',
  '@firebase/remote-config-types@0.5.1',
  '@firebase/remote-config@0.9.1',
  '@firebase/storage-compat@0.4.4',
  '@firebase/storage-types@0.8.4',
  '@firebase/storage@0.14.4',
  '@firebase/util@1.15.2',
  '@firebase/webchannel-wrapper@1.0.6',
  '@expo/cli@55.0.36',
  '@expo/devcert@1.2.1',
  '@expo/dom-webview@55.0.6',
  '@expo/log-box@55.0.13',
  '@expo/plist@0.5.4',
  '@expo/router-server@55.0.19',
  '@expo/sdk-runtime-versions@1.0.0',
  '@expo/ws-tunnel@1.0.6',
  '@expo/xcpretty@4.4.4',
  '@react-native/assets-registry@0.83.10',
  '@react-native/babel-plugin-codegen@0.83.10',
  '@react-native/babel-preset@0.83.10',
  '@react-native/codegen@0.83.10',
  '@react-native/community-cli-plugin@0.83.10',
  '@react-native/debugger-shell@0.83.10',
  '@react-native/dev-middleware@0.83.10',
  '@react-native/gradle-plugin@0.83.10',
  '@react-native/js-polyfills@0.83.10',
  '@react-native/normalize-colors@0.83.10',
  '@react-native/virtualized-lists@0.83.10',
  '@sentry/cli-darwin@2.58.4',
  '@sentry/cli-linux-arm@2.58.4',
  '@sentry/cli-linux-arm64@2.58.4',
  '@sentry/cli-linux-i686@2.58.4',
  '@sentry/cli-linux-x64@2.58.4',
  '@sentry/cli-win32-arm64@2.58.4',
  '@sentry/cli-win32-i686@2.58.4',
  '@sentry/cli-win32-x64@2.58.4',
  'agent-base@6.0.2',
  'babel-plugin-react-compiler@1.0.0',
  'babel-preset-expo@55.0.25',
  'badgin@1.2.3',
  'boolbase@1.0.0',
  'bplist-parser@0.3.1',
  'bser@2.1.1',
  'expo@55.0.31',
  'expo-apple-authentication@55.0.17',
  'expo-application@55.0.19',
  'expo-asset@55.0.20',
  'expo-constants@55.0.17',
  'expo-crypto@55.0.19',
  'expo-document-picker@55.0.17',
  'expo-file-system@55.0.26',
  'expo-font@55.0.8',
  'expo-image@55.0.11',
  'expo-keep-awake@55.0.8',
  'expo-modules-autolinking@55.0.27',
  'expo-modules-core@55.0.26',
  'expo-notifications@55.0.27',
  'expo-secure-store@55.0.18',
  'expo-server@55.0.12',
  'expo-splash-screen@55.0.25',
  'expo-web-browser@55.0.20',
  'fb-dotslash@0.5.8',
  'fb-watchman@2.0.2',
  'firebase@12.17.1',
  'hermes-compiler@0.14.1',
  'html-parse-stringify@3.0.1',
  'https-proxy-agent@5.0.1',
  'imurmurhash@0.1.4',
  'jimp-compact@0.16.1',
  'metro-babel-transformer@0.83.8',
  'metro-cache-key@0.83.8',
  'metro-cache@0.83.8',
  'metro-config@0.83.8',
  'metro-core@0.83.8',
  'metro-file-map@0.83.8',
  'metro-minify-terser@0.83.8',
  'metro-resolver@0.83.8',
  'metro-runtime@0.83.8',
  'metro-source-map@0.83.7',
  'metro-source-map@0.83.8',
  'metro-symbolicate@0.83.7',
  'metro-symbolicate@0.83.8',
  'metro-transform-plugins@0.83.8',
  'metro-transform-worker@0.83.8',
  'metro@0.83.8',
  'ob1@0.83.7',
  'ob1@0.83.8',
  'react-devtools-core@6.1.5',
  'stream-buffers@2.2.0',
  'structured-headers@0.4.1',
  'tr46@0.0.3',
]);

const compareText = (left, right) => (left < right ? -1 : left > right ? 1 : 0);
const normalizeText = value => String(value).replace(/\r\n?/g, '\n').trim();
const sha256 = value =>
  crypto.createHash('sha256').update(value, 'utf8').digest('hex');

const decodeUtf8 = (buffer, label) => {
  let text;
  try {
    text = new TextDecoder('utf-8', {fatal: true}).decode(buffer);
  } catch {
    throw new Error(`Legal file is not valid UTF-8: ${label}.`);
  }
  const normalized = normalizeText(text);
  if (!normalized || normalized.includes('\0')) {
    throw new Error(`Legal file is empty or invalid: ${label}.`);
  }
  return normalized;
};

const packageNameFromLockPath = lockPath => {
  const marker = 'node_modules/';
  const index = lockPath.lastIndexOf(marker);
  if (index < 0) throw new Error(`Invalid package-lock path: ${lockPath}`);
  return lockPath.slice(index + marker.length);
};

const resolveDependencyPath = (packages, fromPath, dependencyName) => {
  let cursor = fromPath;
  while (true) {
    const candidate = path.posix.join(cursor, 'node_modules', dependencyName);
    if (packages[candidate]) return candidate;
    if (!cursor) return null;
    const parent = path.posix.dirname(cursor);
    cursor = parent === '.' ? '' : parent;
  }
};

const collectProductionPackagePaths = lock => {
  if (lock.lockfileVersion !== 3 || !lock.packages || !lock.packages['']) {
    throw new Error('Expected an npm lockfileVersion 3 package-lock.json.');
  }
  const packages = lock.packages;
  const visited = new Set();

  const visit = (fromPath, dependencyName, optional = false) => {
    const dependencyPath = resolveDependencyPath(
      packages,
      fromPath,
      dependencyName,
    );
    if (!dependencyPath) {
      if (optional) return;
      throw new Error(
        `Production dependency ${dependencyName} from ${
          fromPath || '<root>'
        } is missing from package-lock.json.`,
      );
    }
    if (visited.has(dependencyPath)) return;
    visited.add(dependencyPath);
    const entry = packages[dependencyPath];
    for (const name of Object.keys(entry.dependencies || {}).sort(
      compareText,
    )) {
      visit(dependencyPath, name);
    }
    for (const name of Object.keys(entry.optionalDependencies || {}).sort(
      compareText,
    )) {
      visit(dependencyPath, name, true);
    }
    for (const name of Object.keys(entry.peerDependencies || {}).sort(
      compareText,
    )) {
      if (!entry.peerDependenciesMeta?.[name]?.optional) {
        visit(dependencyPath, name);
      }
    }
  };

  for (const name of Object.keys(lock.packages[''].dependencies || {}).sort(
    compareText,
  )) {
    visit('', name);
  }
  return [...visited].sort(compareText);
};

const selectLicense = ({name, version, declaredLicense}) => {
  if (name === 'base-64' && version === '0.1.0' && !declaredLicense) {
    return 'MIT';
  }
  if (
    name === 'node-forge' &&
    version === '1.4.0' &&
    declaredLicense === '(BSD-3-Clause OR GPL-2.0)'
  ) {
    return 'BSD-3-Clause';
  }
  if (
    declaredLicense === '(MIT OR CC0-1.0)' ||
    declaredLicense === '(MIT OR Apache-2.0)'
  ) {
    return 'MIT';
  }
  return declaredLicense || '';
};

const npmPackageUrl = ({name, version}) =>
  `https://www.npmjs.com/package/${encodeURIComponent(
    name,
  )}/v/${encodeURIComponent(version)}`;

const canonicalRegistryTarballUrl = ({name, version}) => {
  const packageFileName = name.startsWith('@') ? name.split('/')[1] : name;
  if (!packageFileName) {
    throw new Error(`Invalid npm package name: ${name}.`);
  }
  return `https://registry.npmjs.org/${name}/-/${packageFileName}-${version}.tgz`;
};

const validateLockResolvedTarballs = lock => {
  const rootWorkspaces = Array.isArray(lock.packages?.['']?.workspaces)
    ? lock.packages[''].workspaces
    : [];
  const exactWorkspaces = new Set(
    rootWorkspaces.map(workspace => {
      const normalized = path.posix.normalize(
        String(workspace).replace(/\\/g, '/'),
      );
      if (
        normalized !== workspace ||
        normalized.startsWith('../') ||
        path.posix.isAbsolute(normalized) ||
        /[*?[\]{}]/.test(normalized)
      ) {
        throw new Error(
          `Only exact, repository-relative npm workspace paths are reviewed: ${workspace}.`,
        );
      }
      return normalized;
    }),
  );
  const linkedWorkspaces = new Set();
  for (const [lockPath, lockEntry] of Object.entries(lock.packages || {})) {
    if (lockEntry.link !== true) continue;
    if (
      !lockPath.startsWith('node_modules/') ||
      typeof lockEntry.resolved !== 'string' ||
      !exactWorkspaces.has(lockEntry.resolved)
    ) {
      throw new Error(`Unreviewed npm workspace link: ${lockPath}.`);
    }
    linkedWorkspaces.add(lockEntry.resolved);
  }
  for (const [lockPath, lockEntry] of Object.entries(lock.packages || {})) {
    if (
      !lockPath ||
      lockEntry.link === true ||
      linkedWorkspaces.has(lockPath)
    ) {
      continue;
    }
    const name = packageNameFromLockPath(lockPath);
    if (
      !lockEntry.version ||
      typeof lockEntry.resolved !== 'string' ||
      typeof lockEntry.integrity !== 'string' ||
      !lockEntry.integrity
    ) {
      throw new Error(`Incomplete resolved npm lock entry: ${lockPath}.`);
    }
    const expectedResolved = canonicalRegistryTarballUrl({
      name,
      version: lockEntry.version,
    });
    if (lockEntry.resolved !== expectedResolved) {
      throw new Error(
        `Non-canonical npm tarball URL for ${name}@${lockEntry.version}; expected the exact registry.npmjs.org HTTPS URL.`,
      );
    }
  }
};

const buildInventory = lock => {
  validateLockResolvedTarballs(lock);
  const packagePaths = collectProductionPackagePaths(lock);
  const packagesByCoordinate = new Map();
  for (const lockPath of packagePaths) {
    const lockEntry = lock.packages[lockPath];
    const name = packageNameFromLockPath(lockPath);
    const version = lockEntry.version;
    if (!version || !lockEntry.integrity || !lockEntry.resolved) {
      throw new Error(`Incomplete production lock entry: ${lockPath}.`);
    }
    const expectedResolved = canonicalRegistryTarballUrl({name, version});
    if (lockEntry.resolved !== expectedResolved) {
      throw new Error(
        `Non-canonical npm tarball URL for ${name}@${version}; expected the exact registry.npmjs.org HTTPS URL.`,
      );
    }
    const declaredLicense = lockEntry.license || null;
    const license = selectLicense({name, version, declaredLicense});
    if (!ALLOWED_LICENSES.has(license)) {
      throw new Error(
        `Unreviewed production license ${
          declaredLicense || '<missing>'
        } for ${name}@${version}.`,
      );
    }
    const coordinate = `${name}@${version}`;
    const candidate = {
      coordinate,
      name,
      version,
      license,
      declaredLicense,
      integrity: lockEntry.integrity,
      resolved: lockEntry.resolved,
      sourceUrl: npmPackageUrl({name, version}),
      lockPaths: [lockPath],
    };
    const existing = packagesByCoordinate.get(coordinate);
    if (existing) {
      for (const field of [
        'license',
        'declaredLicense',
        'integrity',
        'resolved',
      ]) {
        if (existing[field] !== candidate[field]) {
          throw new Error(`Conflicting ${field} for ${coordinate}.`);
        }
      }
      existing.lockPaths.push(lockPath);
    } else {
      packagesByCoordinate.set(coordinate, candidate);
    }
  }
  return {
    packagePathCount: packagePaths.length,
    packages: [...packagesByCoordinate.values()].sort((left, right) =>
      compareText(left.coordinate, right.coordinate),
    ),
  };
};

const isLegalFileName = fileName =>
  /^(?:licen[cs]e|copying|notice|copyright)(?:[._-].*)?$/i.test(fileName);

const collectLegalFilesFromDirectory = (directory, relative = '') => {
  const result = [];
  const current = path.join(directory, ...relative.split('/').filter(Boolean));
  for (const entry of fs
    .readdirSync(current, {withFileTypes: true})
    .sort((left, right) => compareText(left.name, right.name))) {
    const childRelative = path.posix.join(relative, entry.name);
    if (entry.isDirectory()) {
      if (entry.name !== 'node_modules') {
        result.push(
          ...collectLegalFilesFromDirectory(directory, childRelative),
        );
      }
    } else if (entry.isFile() && isLegalFileName(entry.name)) {
      const filePath = path.join(directory, ...childRelative.split('/'));
      const text = decodeUtf8(fs.readFileSync(filePath), childRelative);
      result.push({path: childRelative, sha256: sha256(text), text});
    }
  }
  return result.sort((left, right) => compareText(left.path, right.path));
};

const readManifest = (buffer, label) => {
  let manifest;
  try {
    manifest = JSON.parse(decodeUtf8(buffer, label));
  } catch (error) {
    throw new Error(
      `Invalid package manifest ${label}: ${
        error instanceof Error ? error.message : String(error)
      }`,
    );
  }
  return manifest;
};

const installedPackageSource = item => {
  const candidates = [...item.lockPaths, `node_modules/${item.name}`];
  const seen = new Set();
  let selected = null;
  for (const candidate of candidates) {
    const directory = path.join(ROOT, ...candidate.split('/'));
    if (
      seen.has(directory) ||
      !fs.existsSync(path.join(directory, 'package.json'))
    ) {
      continue;
    }
    seen.add(directory);
    const manifest = readManifest(
      fs.readFileSync(path.join(directory, 'package.json')),
      `${candidate}/package.json`,
    );
    if (`${manifest.name}@${manifest.version}` !== item.coordinate) continue;
    const files = collectLegalFilesFromDirectory(directory);
    const signature = JSON.stringify(files);
    if (selected && selected.signature !== signature) {
      throw new Error(`Installed legal files differ for ${item.coordinate}.`);
    }
    selected = {manifest, files, signature, reference: candidate};
  }
  return selected;
};

const parseTarString = buffer => {
  const end = buffer.indexOf(0);
  return buffer.subarray(0, end < 0 ? buffer.length : end).toString('utf8');
};

const parsePax = buffer => {
  const values = {};
  let offset = 0;
  while (offset < buffer.length) {
    const space = buffer.indexOf(0x20, offset);
    if (space < 0) break;
    const length = Number(buffer.subarray(offset, space).toString('ascii'));
    if (!Number.isFinite(length) || length <= 0) break;
    const record = buffer
      .subarray(space + 1, offset + length - 1)
      .toString('utf8');
    const equals = record.indexOf('=');
    if (equals > 0) values[record.slice(0, equals)] = record.slice(equals + 1);
    offset += length;
  }
  return values;
};

const extractPackageTarball = (archive, coordinate) => {
  const tar = zlib.gunzipSync(archive);
  let offset = 0;
  let pendingPath = null;
  const entries = [];
  while (offset + 512 <= tar.length) {
    const header = tar.subarray(offset, offset + 512);
    if (header.every(byte => byte === 0)) break;
    const rawName = parseTarString(header.subarray(0, 100));
    const prefix = parseTarString(header.subarray(345, 500));
    const headerName = prefix ? `${prefix}/${rawName}` : rawName;
    const sizeText = parseTarString(header.subarray(124, 136)).trim();
    const size = Number.parseInt(sizeText || '0', 8);
    if (!Number.isFinite(size) || size < 0) {
      throw new Error(`Malformed npm tarball entry for ${coordinate}.`);
    }
    const type = String.fromCharCode(header[156] || 48);
    const dataStart = offset + 512;
    const data = tar.subarray(dataStart, dataStart + size);
    offset = dataStart + Math.ceil(size / 512) * 512;

    if (type === 'x' || type === 'g') {
      const pax = parsePax(data);
      if (pax.path) pendingPath = pax.path;
      continue;
    }
    if (type === 'L') {
      pendingPath = parseTarString(data);
      continue;
    }
    const entryPath = (pendingPath || headerName).replace(/\\/g, '/');
    pendingPath = null;
    if (type !== '0' && type !== '\0') continue;
    if (!entryPath || entryPath.includes('../')) {
      continue;
    }
    entries.push({path: entryPath, data: Buffer.from(data)});
  }

  const manifestCandidates = entries
    .filter(entry => path.posix.basename(entry.path) === 'package.json')
    .map(entry => {
      try {
        return {
          entry,
          manifest: readManifest(entry.data, `${coordinate}/${entry.path}`),
        };
      } catch {
        return null;
      }
    })
    .filter(Boolean)
    .filter(
      candidate =>
        `${candidate.manifest.name}@${candidate.manifest.version}` ===
        coordinate,
    )
    .sort(
      (left, right) =>
        left.entry.path.split('/').length -
          right.entry.path.split('/').length ||
        compareText(left.entry.path, right.entry.path),
    );
  if (!manifestCandidates.length) {
    throw new Error(`npm tarball lacks package.json: ${coordinate}.`);
  }
  const selectedManifest = manifestCandidates[0];
  const rootDirectory = path.posix.dirname(selectedManifest.entry.path);
  const rootPrefix = rootDirectory === '.' ? '' : `${rootDirectory}/`;
  const files = entries
    .filter(entry => entry.path.startsWith(rootPrefix))
    .map(entry => ({...entry, relative: entry.path.slice(rootPrefix.length)}))
    .filter(
      entry =>
        entry.relative &&
        !entry.relative.includes('/node_modules/') &&
        isLegalFileName(path.posix.basename(entry.relative)),
    )
    .map(entry => {
      const text = decodeUtf8(entry.data, `${coordinate}/${entry.relative}`);
      return {path: entry.relative, sha256: sha256(text), text};
    })
    .sort((left, right) => compareText(left.path, right.path));
  return {
    manifest: selectedManifest.manifest,
    files,
  };
};

const verifyIntegrity = (buffer, integrity, coordinate) => {
  const valid = integrity.split(/\s+/).some(token => {
    const separator = token.indexOf('-');
    if (separator < 1) return false;
    const algorithm = token.slice(0, separator);
    const expected = token.slice(separator + 1);
    if (!crypto.getHashes().includes(algorithm)) return false;
    const actual = crypto.createHash(algorithm).update(buffer).digest('base64');
    return actual === expected;
  });
  if (!valid) throw new Error(`Integrity mismatch for ${coordinate}.`);
};

const downloadPackageSource = async item => {
  let lastError;
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      const response = await fetch(item.resolved, {
        headers: {'user-agent': 'rokn-license-inventory/1.0'},
      });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      const archive = Buffer.from(await response.arrayBuffer());
      verifyIntegrity(archive, item.integrity, item.coordinate);
      const source = extractPackageTarball(archive, item.coordinate);
      if (
        `${source.manifest.name}@${source.manifest.version}` !== item.coordinate
      ) {
        throw new Error(`Tarball identity mismatch for ${item.coordinate}.`);
      }
      return {...source, reference: item.resolved};
    } catch (error) {
      lastError = error;
    }
  }
  throw new Error(
    `Could not fetch verified tarball for ${item.coordinate}: ${
      lastError instanceof Error ? lastError.message : String(lastError)
    }`,
  );
};

const manifestValue = value => {
  if (!value) return null;
  if (typeof value === 'string') return value;
  if (typeof value === 'object') {
    return value.url || value.name || JSON.stringify(value);
  }
  return String(value);
};

const loadCanonicalLicenseTexts = inventory => {
  const productionCoordinates = new Set(
    inventory.packages.map(item => item.coordinate),
  );
  const result = {};
  for (const license of [...ALLOWED_LICENSES].sort(compareText)) {
    const [packagePath, fileName] = CANONICAL_LICENSE_SOURCES[license] || [];
    const directory = path.join(ROOT, ...packagePath.split('/'));
    const manifest = readManifest(
      fs.readFileSync(path.join(directory, 'package.json')),
      `${packagePath}/package.json`,
    );
    const coordinate = `${manifest.name}@${manifest.version}`;
    if (!productionCoordinates.has(coordinate)) {
      throw new Error(
        `Canonical license source is not production: ${coordinate}.`,
      );
    }
    const text = decodeUtf8(
      fs.readFileSync(path.join(directory, fileName)),
      `${packagePath}/${fileName}`,
    );
    result[license] = {coordinate, fileName, text};
  }
  return result;
};

const reviewedUpstreamLicense = (item, manifest = null) => {
  const review = UPSTREAM_LICENSE_SOURCES.get(item.coordinate);
  if (!review) return null;
  if (
    item.license !== 'MIT' ||
    (manifest && manifest.gitHead !== review.gitHead)
  ) {
    throw new Error(`Stale upstream license review for ${item.coordinate}.`);
  }
  const text = decodeUtf8(
    fs.readFileSync(path.join(ROOT, review.path)),
    review.path,
  );
  if (sha256(normalizeText(text)) !== review.sha256) {
    throw new Error(`Pinned upstream license changed for ${item.coordinate}.`);
  }
  return {...review, text};
};

const buildMetadataFallback = (item, manifest, canonical) => {
  if (!LEGAL_FILE_ABSENCE_ALLOWLIST.has(item.coordinate)) {
    throw new Error(
      `${item.coordinate} publishes no legal file. Review it and update the exact absence allowlist.`,
    );
  }
  const upstream = reviewedUpstreamLicense(item, manifest);
  const terms = upstream || canonical;
  const termsSource = upstream
    ? [
        `Upstream terms source: ${upstream.sourceUrl}`,
        `Retained upstream file: ${upstream.path}`,
        `Upstream SHA-256: ${upstream.sha256}`,
      ]
    : [`Standard terms source: ${canonical.coordinate}/${canonical.fileName}`];
  const metadata = [
    'UPSTREAM PACKAGE LEGAL-FILE ABSENCE RECORD',
    '',
    `Package: ${item.coordinate}`,
    `Exact npm tarball: ${item.resolved}`,
    `Tarball integrity: ${item.integrity}`,
    `Declared license: ${item.declaredLicense || '<legacy manifest field>'}`,
    `Selected license: ${item.license}`,
    `Author metadata: ${manifestValue(manifest.author) || '<not published>'}`,
    `Repository metadata: ${
      manifestValue(manifest.repository) || '<not published>'
    }`,
    `Homepage metadata: ${
      manifestValue(manifest.homepage) || '<not published>'
    }`,
    '',
    'The exact npm package root was inspected and did not publish a standalone',
    'LICENSE, LICENCE, COPYING, NOTICE, or COPYRIGHT file. This coordinate is',
    'therefore covered by an exact, reviewed absence exception. The package',
    'metadata above and the selected standard license terms are retained here;',
    'the exception does not apply to any other name or version.',
    '',
    ...termsSource,
    '',
    terms.text,
  ].join('\n');
  const text = normalizeText(metadata);
  return [
    {
      path: 'ROKN-REVIEWED-LEGAL-FILE-ABSENCE.txt',
      sha256: sha256(text),
      text,
    },
  ];
};

const apacheNoticeStatus = (item, publishedFiles) => {
  if (item.license !== 'Apache-2.0') return null;
  return publishedFiles.some(file =>
    /^notice(?:[._-].*)?$/i.test(path.posix.basename(file.path)),
  )
    ? 'included'
    : 'not-published';
};

const createSnapshotEntry = (item, source, canonicalTexts) => {
  if (
    `${source.manifest.name}@${source.manifest.version}` !== item.coordinate
  ) {
    throw new Error(`Package source identity mismatch for ${item.coordinate}.`);
  }
  const publishedFiles = source.files;
  const fallback = publishedFiles.length === 0;
  const files = fallback
    ? buildMetadataFallback(item, source.manifest, canonicalTexts[item.license])
    : publishedFiles;
  return {
    coordinate: item.coordinate,
    name: item.name,
    version: item.version,
    selectedLicense: item.license,
    declaredLicense: item.declaredLicense,
    integrity: item.integrity,
    resolved: item.resolved,
    legalSource: fallback ? 'reviewed-metadata-fallback' : 'package-root',
    // Bind output to the exact lockfile tarball rather than whichever hoisted
    // node_modules path happened to be present on the generating machine.
    sourceReference: item.resolved,
    publishedLegalFileCount: publishedFiles.length,
    apacheNotice: apacheNoticeStatus(item, publishedFiles),
    files,
  };
};

const mapWithConcurrency = async (items, limit, mapper) => {
  const result = new Array(items.length);
  let nextIndex = 0;
  const workers = Array.from(
    {length: Math.min(limit, items.length)},
    async () => {
      while (nextIndex < items.length) {
        const index = nextIndex;
        nextIndex += 1;
        result[index] = await mapper(items[index], index);
      }
    },
  );
  await Promise.all(workers);
  return result;
};

const refreshSnapshot = async inventory => {
  const canonicalTexts = loadCanonicalLicenseTexts(inventory);
  // Refresh always reads the exact registry tarballs named by the lockfile and
  // verifies their Subresource Integrity. node_modules is used only by the
  // offline --check comparison, never as the source of a regenerated notice.
  const sources = await mapWithConcurrency(
    inventory.packages,
    10,
    downloadPackageSource,
  );
  const packages = inventory.packages.map((item, index) =>
    createSnapshotEntry(item, sources[index], canonicalTexts),
  );
  return {
    schemaVersion: 2,
    generatedFrom: 'verified package-lock.json production closure',
    packageCount: packages.length,
    packagePathCount: inventory.packagePathCount,
    packages,
  };
};

const validateSnapshot = (
  inventory,
  snapshot,
  {compareInstalled = true} = {},
) => {
  if (snapshot.schemaVersion !== 2 || !Array.isArray(snapshot.packages)) {
    throw new Error('Third-party legal snapshot has an unsupported schema.');
  }
  if (
    snapshot.packageCount !== inventory.packages.length ||
    snapshot.packagePathCount !== inventory.packagePathCount ||
    snapshot.packages.length !== inventory.packages.length
  ) {
    throw new Error(
      'Third-party legal snapshot does not match production closure counts.',
    );
  }
  const snapshotByCoordinate = new Map(
    snapshot.packages.map(entry => [entry.coordinate, entry]),
  );
  const canonicalTexts = compareInstalled
    ? loadCanonicalLicenseTexts(inventory)
    : null;

  for (const item of inventory.packages) {
    const entry = snapshotByCoordinate.get(item.coordinate);
    if (!entry) throw new Error(`Missing legal snapshot: ${item.coordinate}.`);
    for (const [snapshotField, inventoryField] of [
      ['name', 'name'],
      ['version', 'version'],
      ['selectedLicense', 'license'],
      ['declaredLicense', 'declaredLicense'],
      ['integrity', 'integrity'],
      ['resolved', 'resolved'],
    ]) {
      if (entry[snapshotField] !== item[inventoryField]) {
        throw new Error(`Stale ${snapshotField} for ${item.coordinate}.`);
      }
    }
    if (!Array.isArray(entry.files) || entry.files.length === 0) {
      throw new Error(`No retained legal text for ${item.coordinate}.`);
    }
    for (const file of entry.files) {
      if (
        !file.path ||
        !file.text ||
        file.sha256 !== sha256(normalizeText(file.text))
      ) {
        throw new Error(`Invalid retained legal file for ${item.coordinate}.`);
      }
    }
    if (entry.legalSource === 'reviewed-metadata-fallback') {
      if (
        !LEGAL_FILE_ABSENCE_ALLOWLIST.has(item.coordinate) ||
        entry.publishedLegalFileCount !== 0 ||
        entry.files.length !== 1 ||
        entry.files[0].path !== 'ROKN-REVIEWED-LEGAL-FILE-ABSENCE.txt'
      ) {
        throw new Error(
          `Invalid legal-file absence fallback: ${item.coordinate}.`,
        );
      }
      const upstream = reviewedUpstreamLicense(item);
      if (
        upstream &&
        (!entry.files[0].text.includes(
          `Upstream terms source: ${upstream.sourceUrl}`,
        ) ||
          !entry.files[0].text.includes(
            `Upstream SHA-256: ${upstream.sha256}`,
          ) ||
          !entry.files[0].text.endsWith(normalizeText(upstream.text)))
      ) {
        throw new Error(
          `Missing pinned upstream attribution for ${item.coordinate}.`,
        );
      }
    } else if (
      entry.legalSource !== 'package-root' ||
      entry.publishedLegalFileCount !== entry.files.length ||
      entry.files.some(file => !isLegalFileName(path.posix.basename(file.path)))
    ) {
      throw new Error(`Invalid package-root legal files: ${item.coordinate}.`);
    }
    const expectedApache =
      item.license === 'Apache-2.0'
        ? entry.legalSource === 'package-root' &&
          entry.files.some(file =>
            /^notice(?:[._-].*)?$/i.test(path.posix.basename(file.path)),
          )
          ? 'included'
          : 'not-published'
        : null;
    if (entry.apacheNotice !== expectedApache) {
      throw new Error(`Apache NOTICE status is stale for ${item.coordinate}.`);
    }

    if (compareInstalled) {
      const installed = installedPackageSource(item);
      if (installed) {
        const expected = createSnapshotEntry(item, installed, canonicalTexts);
        const comparable = value => ({
          legalSource: value.legalSource,
          publishedLegalFileCount: value.publishedLegalFileCount,
          apacheNotice: value.apacheNotice,
          files: value.files,
        });
        if (
          JSON.stringify(comparable(entry)) !==
          JSON.stringify(comparable(expected))
        ) {
          throw new Error(
            `Installed legal files changed for ${item.coordinate}.`,
          );
        }
      }
    }
  }
  for (const coordinate of LEGAL_FILE_ABSENCE_ALLOWLIST) {
    const entry = snapshotByCoordinate.get(coordinate);
    if (!entry || entry.legalSource !== 'reviewed-metadata-fallback') {
      throw new Error(
        `Stale legal-file absence allowlist entry: ${coordinate}.`,
      );
    }
  }
  return snapshot;
};

const escapeMarkdown = value => String(value).replace(/\|/g, '\\|');
const escapeCodeFence = text => text.replace(/```/g, '``\u200b`');

const renderMarkdown = (inventory, snapshot) => {
  const counts = new Map();
  for (const item of inventory.packages) {
    counts.set(item.license, (counts.get(item.license) || 0) + 1);
  }
  const fallbackCount = snapshot.packages.filter(
    item => item.legalSource === 'reviewed-metadata-fallback',
  ).length;
  const lines = [
    '# Third-Party Notices / إشعارات البرمجيات مفتوحة المصدر',
    '',
    '<!-- Generated by scripts/generate-third-party-notices.js. Do not edit manually. -->',
    '',
    'يحتفظ هذا الملف بنصوص الإشعارات القانونية المنشورة مع كل حزمة إنتاج، بما في ذلك ملفات NOTICE. الحزم التي لم تنشر ملفًا قانونيًا مستقلًا موثقة باستثناء دقيق مرتبط بالاسم والإصدار وسلامة ملف npm.',
    '',
    'This file retains the legal documents published with every production package, including NOTICE files. An exact name/version/integrity-bound review record is used only when an npm package publishes no standalone legal file.',
    '',
    `- Unique packages: ${inventory.packages.length}`,
    `- Resolved production package paths: ${inventory.packagePathCount}`,
    `- Exact package-root legal documents: ${
      inventory.packages.length - fallbackCount
    }`,
    `- Reviewed legal-file absence records: ${fallbackCount}`,
    '',
    '## Explicit license choices',
    '',
    '- `base-64@0.1.0`: MIT, based on its legacy manifest and `LICENSE-MIT.txt`.',
    '- `node-forge@1.4.0`: BSD-3-Clause is selected from its BSD-3-Clause/GPL-2.0 choice; the original package legal file is retained unmodified below.',
    '- Packages offering MIT or another listed license are used under MIT.',
    '',
    '## License summary',
    '',
    '| Selected license | Packages |',
    '| --- | ---: |',
    ...[...counts.entries()]
      .sort(([left], [right]) => compareText(left, right))
      .map(([license, count]) => `| ${escapeMarkdown(license)} | ${count} |`),
    '',
    '## Package notices',
    '',
  ];

  for (const entry of snapshot.packages) {
    lines.push(
      `### ${entry.coordinate}`,
      '',
      `- Selected license: \`${entry.selectedLicense}\``,
      `- Declared license: \`${
        entry.declaredLicense || 'legacy manifest field'
      }\``,
      `- Legal source: \`${entry.legalSource}\``,
      `- Exact source: [npm](${npmPackageUrl(entry)})`,
      `- Integrity: \`${entry.integrity}\``,
    );
    if (entry.apacheNotice) {
      lines.push(`- Apache NOTICE: \`${entry.apacheNotice}\``);
    }
    lines.push('');
    for (const file of entry.files) {
      lines.push(
        `#### ${file.path}`,
        '',
        `SHA-256: \`${file.sha256}\``,
        '',
        '```text',
        escapeCodeFence(file.text),
        '```',
        '',
      );
    }
  }
  return `${lines.join('\n').trimEnd()}\n`;
};

const renderAppData = (inventory, snapshot) => {
  const snapshotByCoordinate = new Map(
    snapshot.packages.map(entry => [entry.coordinate, entry]),
  );
  const packages = inventory.packages.map(item => {
    const legal = snapshotByCoordinate.get(item.coordinate);
    return {
      name: item.name,
      version: item.version,
      license: item.license,
      declaredLicense: item.declaredLicense,
      sourceUrl: item.sourceUrl,
      legalSource: legal.legalSource,
      legalFileCount: legal.files.length,
      apacheNotice: legal.apacheNotice,
    };
  });
  return `${JSON.stringify(
    {
      schemaVersion: 2,
      generatedFrom: 'package-lock.json production dependency closure',
      packageCount: packages.length,
      packagePathCount: inventory.packagePathCount,
      inventoryHash: sha256(JSON.stringify(packages)),
      packages,
    },
    null,
    2,
  )}\n`;
};

const buildArtifacts = (lock, suppliedSnapshot = null) => {
  const inventory = buildInventory(lock);
  const snapshot =
    suppliedSnapshot || JSON.parse(fs.readFileSync(SNAPSHOT_PATH, 'utf8'));
  validateSnapshot(inventory, snapshot);
  return {
    inventory,
    snapshot,
    markdown: renderMarkdown(inventory, snapshot),
    appData: renderAppData(inventory, snapshot),
  };
};

const writeOrCheck = (filePath, expected, check) => {
  if (check) {
    const actual = fs.existsSync(filePath)
      ? normalizeText(fs.readFileSync(filePath, 'utf8'))
      : null;
    if (actual !== normalizeText(expected)) {
      throw new Error(
        `${path.relative(
          ROOT,
          filePath,
        )} is stale. Run npm run notices:generate.`,
      );
    }
    return;
  }
  fs.mkdirSync(path.dirname(filePath), {recursive: true});
  fs.writeFileSync(filePath, expected, 'utf8');
};

const main = async () => {
  const check = process.argv.includes('--check');
  const lock = JSON.parse(fs.readFileSync(LOCK_PATH, 'utf8'));
  const inventory = buildInventory(lock);
  const snapshot = check
    ? JSON.parse(fs.readFileSync(SNAPSHOT_PATH, 'utf8'))
    : await refreshSnapshot(inventory);
  validateSnapshot(inventory, snapshot);
  const snapshotText = `${JSON.stringify(snapshot, null, 2)}\n`;
  const markdown = renderMarkdown(inventory, snapshot);
  const appData = renderAppData(inventory, snapshot);
  writeOrCheck(SNAPSHOT_PATH, snapshotText, check);
  writeOrCheck(MARKDOWN_PATH, markdown, check);
  writeOrCheck(APP_DATA_PATH, appData, check);
  writeOrCheck(ANDROID_NOTICE_PATH, markdown, check);
  writeOrCheck(IOS_NOTICE_PATH, markdown, check);
  const fallbackCount = snapshot.packages.filter(
    item => item.legalSource === 'reviewed-metadata-fallback',
  ).length;
  console.log(
    `Third-party legal gate passed for ${inventory.packages.length} packages: ${
      inventory.packages.length - fallbackCount
    } package-root notices and ${fallbackCount} reviewed absence records.`,
  );
};

if (require.main === module) {
  main().catch(error => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  });
}

module.exports = {
  ALLOWED_LICENSES,
  LEGAL_FILE_ABSENCE_ALLOWLIST,
  buildArtifacts,
  buildInventory,
  canonicalRegistryTarballUrl,
  collectLegalFilesFromDirectory,
  collectProductionPackagePaths,
  packageNameFromLockPath,
  refreshSnapshot,
  selectLicense,
  validateLockResolvedTarballs,
  validateSnapshot,
};
