'use strict';

const childProcess = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const {
  buildBundledFontInventory,
  renderBundledFontMarkdown,
} = require('./bundled-font-notices');
const {
  buildInventory: buildNpmInventory,
} = require('./generate-third-party-notices');

const ROOT = path.resolve(__dirname, '..');
const ANDROID_INIT_SCRIPT = path.join(
  ROOT,
  'scripts',
  'android-license-inventory.init.gradle',
);
const POD_LOCK_PATH = path.join(ROOT, 'ios', 'Podfile.lock');
const IOS_DIRECTORY = path.join(ROOT, 'ios');
const ANDROID_SNAPSHOT_PATH = path.join(
  ROOT,
  'scripts',
  'licenses',
  'android-release-notices.generated.json',
);
const PODS_SNAPSHOT_PATH = path.join(
  ROOT,
  'scripts',
  'licenses',
  'ios-pods-notices.generated.json',
);
const MARKDOWN_PATH = path.join(ROOT, 'NATIVE_THIRD_PARTY_NOTICES.md');
const ANDROID_MARKDOWN_PATH = path.join(ROOT, 'ANDROID_THIRD_PARTY_NOTICES.md');
const IOS_MARKDOWN_PATH = path.join(ROOT, 'IOS_THIRD_PARTY_NOTICES.md');
const APP_DATA_PATH = path.join(
  ROOT,
  'src',
  'data',
  'nativeThirdPartyNotices.generated.json',
);
const ANDROID_NOTICE_PATH = path.join(
  ROOT,
  'android',
  'app',
  'src',
  'main',
  'assets',
  'NATIVE_THIRD_PARTY_NOTICES.md',
);
const IOS_NOTICE_PATH = path.join(
  ROOT,
  'ios',
  'Rokn',
  'NATIVE_THIRD_PARTY_NOTICES.md',
);

const ALLOWED_LICENSES = new Set([
  '0BSD',
  'Apache-2.0',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'Boost-1.0',
  'CC0-1.0',
  'EPL-1.0',
  'EPL-2.0',
  'ISC',
  'LicenseRef-Android-SDK',
  'LicenseRef-Bouncy-Castle',
  'LicenseRef-Facebook-Platform',
  'LicenseRef-Public-Domain',
  'MIT',
  'MPL-2.0',
  'Unicode-3.0',
  'Zlib',
]);

// Standard terms are only a fallback for a dependency that declares a
// standard license but does not ship its own legal document. Any package-
// specific LICENSE/COPYING/NOTICE/COPYRIGHT file is retained separately.
const CANONICAL_LICENSE_SOURCES = {
  '0BSD': ['node_modules/jsc-safe-url', 'LICENSE'],
  'Apache-2.0': ['node_modules/baseline-browser-mapping', 'LICENSE.txt'],
  'BSD-2-Clause': ['node_modules/css-select', 'LICENSE'],
  'BSD-3-Clause': ['node_modules/@sinonjs/commons', 'LICENSE'],
  'Boost-1.0': ['scripts/licenses/canonical', 'Boost-1.0.txt'],
  'CC0-1.0': ['node_modules/mdn-data', 'LICENSE'],
  ISC: ['node_modules/@isaacs/ttlcache', 'LICENSE'],
  MIT: ['node_modules/@babel/code-frame', 'LICENSE'],
  'MPL-2.0': ['node_modules/lightningcss', 'LICENSE'],
};

// Generated CocoaPods targets are application build products, not external
// components. Keeping the exemption exact makes a newly generated target fail
// review instead of inheriting a pattern-based exception.
const FIRST_PARTY_GENERATED_PODS = new Set([
  'ReactAppDependencyProvider@0.83.10',
  'ReactCodegen@0.83.10',
]);

// Filled only after an exact coordinate has been inspected and neither its
// artifact, POM/podspec nor source package publishes usable license metadata.
const ANDROID_LEGAL_METADATA_ABSENCE_ALLOWLIST = new Map([
  [
    'com.android.installreferrer:installreferrer:2.2',
    'The POM declares the Android SDK License and links the authoritative Google terms; the AAR publishes no standalone legal file.',
  ],
  [
    'com.android.billingclient:billing:9.1.0',
    'The exact Billing Client POM declares the Android SDK License and links the authoritative Google terms; the resolved AAR publishes no standalone legal file.',
  ],
  ...[
    'com.google.android.gms:play-services-auth-api-phone:18.0.2',
    'com.google.android.gms:play-services-auth-base:18.0.10',
    'com.google.android.gms:play-services-auth:21.5.0',
    'com.google.android.gms:play-services-base:18.9.0',
    'com.google.android.gms:play-services-basement:18.9.0',
    'com.google.android.gms:play-services-cloud-messaging:17.4.0',
    'com.google.android.gms:play-services-fido:20.0.1',
    'com.google.android.gms:play-services-location:19.0.0',
    'com.google.android.gms:play-services-places-placereport:17.0.0',
    'com.google.android.gms:play-services-stats:17.0.2',
    'com.google.android.gms:play-services-tasks:18.4.0',
  ].map(coordinate => [
    coordinate,
    'Google Play services artifact reviewed under the Google APIs/Android SDK terms; its resolved artifact and POM publish no standalone legal file.',
  ]),
  ...[
    'com.google.firebase:firebase-iid-interop:17.1.0',
    'com.google.firebase:firebase-measurement-connector:19.0.0',
  ].map(coordinate => [
    coordinate,
    'The exact Firebase Maven POM declares the Android SDK License and links Google terms; the resolved AAR publishes no standalone legal file.',
  ]),
]);

const ANDROID_EXACT_LICENSE_SELECTIONS = new Map([
  [
    'com.google.guava:failureaccess:1.0.2',
    {
      license: 'Apache-2.0',
      reason:
        'The exact Guava failureaccess coordinate is Apache-2.0; its resolved child POM omits inherited license metadata.',
    },
  ],
  [
    'com.google.guava:guava:33.0.0-android',
    {
      license: 'Apache-2.0',
      reason:
        'The exact Guava Android coordinate is Apache-2.0; its resolved child POM omits inherited license metadata.',
    },
  ],
  [
    'com.google.guava:listenablefuture:9999.0-empty-to-avoid-conflict-with-guava',
    {
      license: 'Apache-2.0',
      reason:
        'This exact empty Guava compatibility marker is distributed under the Guava Apache-2.0 terms.',
    },
  ],
  [
    'com.google.protobuf:protobuf-javalite:3.25.8',
    {
      license: 'BSD-3-Clause',
      reason:
        'The exact protobuf-javalite coordinate inherits the protobuf BSD-3-Clause license; its published child POM and JAR omit the inherited legal file.',
    },
  ],
  [
    'commons-codec:commons-codec:1.10',
    {
      license: 'Apache-2.0',
      reason:
        'The exact Commons Codec JAR contains Apache-2.0 LICENSE and NOTICE files while its child POM omits inherited metadata.',
    },
  ],
  [
    'commons-io:commons-io:2.6',
    {
      license: 'Apache-2.0',
      reason:
        'The exact Commons IO JAR contains Apache-2.0 LICENSE and NOTICE files while its child POM omits inherited metadata.',
    },
  ],
]);

const POD_LEGAL_METADATA_ABSENCE_ALLOWLIST = new Map([
  // coordinate => review note
]);

// Some npm-published Expo packages intentionally omit the monorepo-root
// LICENSE from the package tarball. Keep an exact, commit-pinned copy of the
// upstream package license instead of treating generic MIT text as sufficient
// package attribution or granting a pattern-based exception.
const expoUpstreamLicense = (npmCoordinate, gitHead) => ({
  npmCoordinate,
  gitHead,
  path: ['scripts', 'licenses', 'upstream', 'expo-expo-LICENSE'],
  sha256: '371567d5d8999eeffba61ddbcb60ffbe4f25c3f165f1772e2c66befd0251bffa',
  sourceUrl: `https://github.com/expo/expo/blob/${gitHead}/LICENSE`,
});
const EXPO_GIT_HEAD_C8 = 'c8f16914a2713c37fe446c46d613004626b3e6b3';
const EXPO_GIT_HEAD_7C = '7c081282cf88968f81732feb67a71840e769a40f';
const EXPO_GIT_HEAD_FC = 'fcb091766242d53248cd3c5949965961dbc5ec1d';
const EXPO_GIT_HEAD_85 = '856b99321eeb04bd528b33f90c0e7fa2859a1fcb';
const EXPO_GIT_HEAD_30 = '30a1c5b4871a5a3f0f6545be0c2d1f67521a5e6f';
const POD_UPSTREAM_LEGAL_DOCUMENTS = new Map([
  [
    'EXApplication@55.0.17',
    expoUpstreamLicense('expo-application@55.0.17', EXPO_GIT_HEAD_C8),
  ],
  [
    'EXApplication@55.0.19',
    expoUpstreamLicense('expo-application@55.0.19', EXPO_GIT_HEAD_85),
  ],
  [
    'EXConstants@55.0.17',
    expoUpstreamLicense('expo-constants@55.0.17', EXPO_GIT_HEAD_C8),
  ],
  ['Expo@55.0.28', expoUpstreamLicense('expo@55.0.28', EXPO_GIT_HEAD_C8)],
  ['Expo@55.0.31', expoUpstreamLicense('expo@55.0.31', EXPO_GIT_HEAD_30)],
  [
    'ExpoAppleAuthentication@55.0.15',
    expoUpstreamLicense('expo-apple-authentication@55.0.15', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoAppleAuthentication@55.0.17',
    expoUpstreamLicense('expo-apple-authentication@55.0.17', EXPO_GIT_HEAD_85),
  ],
  [
    'ExpoAsset@55.0.18',
    expoUpstreamLicense('expo-asset@55.0.18', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoAsset@55.0.20',
    expoUpstreamLicense('expo-asset@55.0.20', EXPO_GIT_HEAD_85),
  ],
  [
    'ExpoCrypto@55.0.17',
    expoUpstreamLicense('expo-crypto@55.0.17', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoCrypto@55.0.19',
    expoUpstreamLicense('expo-crypto@55.0.19', EXPO_GIT_HEAD_85),
  ],
  [
    'ExpoDocumentPicker@55.0.17',
    expoUpstreamLicense('expo-document-picker@55.0.17', EXPO_GIT_HEAD_85),
  ],
  [
    'ExpoDomWebView@55.0.6',
    expoUpstreamLicense('@expo/dom-webview@55.0.6', EXPO_GIT_HEAD_7C),
  ],
  [
    'ExpoFileSystem@55.0.24',
    expoUpstreamLicense('expo-file-system@55.0.24', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoFileSystem@55.0.26',
    expoUpstreamLicense('expo-file-system@55.0.26', EXPO_GIT_HEAD_85),
  ],
  [
    'ExpoFont@55.0.8',
    expoUpstreamLicense('expo-font@55.0.8', EXPO_GIT_HEAD_FC),
  ],
  [
    'ExpoKeepAwake@55.0.8',
    expoUpstreamLicense('expo-keep-awake@55.0.8', EXPO_GIT_HEAD_7C),
  ],
  [
    'ExpoLogBox@55.0.13',
    expoUpstreamLicense('@expo/log-box@55.0.13', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoModulesCore@55.0.25',
    expoUpstreamLicense('expo-modules-core@55.0.25', EXPO_GIT_HEAD_7C),
  ],
  [
    'ExpoModulesCore@55.0.26',
    expoUpstreamLicense('expo-modules-core@55.0.26', EXPO_GIT_HEAD_30),
  ],
  [
    'ExpoModulesJSI@55.0.25',
    expoUpstreamLicense('expo-modules-core@55.0.25', EXPO_GIT_HEAD_7C),
  ],
  [
    'ExpoModulesJSI@55.0.26',
    expoUpstreamLicense('expo-modules-core@55.0.26', EXPO_GIT_HEAD_30),
  ],
  [
    'ExpoNotifications@55.0.25',
    expoUpstreamLicense('expo-notifications@55.0.25', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoNotifications@55.0.27',
    expoUpstreamLicense('expo-notifications@55.0.27', EXPO_GIT_HEAD_85),
  ],
  [
    'ExpoSecureStore@55.0.16',
    expoUpstreamLicense('expo-secure-store@55.0.16', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoSecureStore@55.0.18',
    expoUpstreamLicense('expo-secure-store@55.0.18', EXPO_GIT_HEAD_85),
  ],
  [
    'ExpoWebBrowser@55.0.18',
    expoUpstreamLicense('expo-web-browser@55.0.18', EXPO_GIT_HEAD_C8),
  ],
  [
    'ExpoWebBrowser@55.0.20',
    expoUpstreamLicense('expo-web-browser@55.0.20', EXPO_GIT_HEAD_85),
  ],
]);

const POD_EXACT_LICENSE_SELECTIONS = new Map([
  [
    'GTMAppAuth@5.0.0',
    {
      license: 'Apache-2.0',
      reason:
        "The exact GTMAppAuth 5.0.0 podspec abbreviates its license as 'Apache'; the tagged LICENSE contains the reviewed Apache License 2.0 terms.",
    },
  ],
  [
    'GTMSessionFetcher@3.5.0',
    {
      license: 'Apache-2.0',
      reason:
        "The exact GTMSessionFetcher 3.5.0 podspec abbreviates its license as 'Apache'; the installed LICENSE contains the reviewed Apache License 2.0 terms.",
    },
  ],
  [
    'glog@0.3.5',
    {
      license: 'BSD-3-Clause',
      reason:
        "The exact glog podspec labels the license 'Google'; its declared COPYING file contains the reviewed BSD 3-Clause terms.",
    },
  ],
  [
    'SocketRocket@0.7.1',
    {
      license: 'BSD-3-Clause',
      reason:
        "The exact SocketRocket 0.7.1 podspec says 'BSD'; its installed LICENSE contains the reviewed BSD 3-Clause terms and Facebook attribution.",
    },
  ],
]);

const compareText = (left, right) => (left < right ? -1 : left > right ? 1 : 0);
const normalizeText = value => String(value).replace(/\r\n?/g, '\n').trim();
const sha256Buffer = value =>
  crypto.createHash('sha256').update(value).digest('hex');
const sha256Text = value =>
  sha256Buffer(Buffer.from(normalizeText(value), 'utf8'));
const sha1Buffer = value =>
  crypto.createHash('sha1').update(value).digest('hex');

const decodeUtf8 = (buffer, label) => {
  let text;
  try {
    text = new TextDecoder('utf-8', {fatal: true}).decode(buffer);
  } catch {
    throw new Error(`Legal document is not valid UTF-8: ${label}.`);
  }
  const normalized = normalizeText(text);
  if (!normalized || normalized.includes('\0')) {
    throw new Error(`Legal document is empty or invalid: ${label}.`);
  }
  return normalized;
};

const decodeXml = value =>
  normalizeText(value)
    .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&');

const xmlValue = (xml, tag) => {
  const match = xml.match(
    new RegExp(`<${tag}(?:\\s[^>]*)?>([\\s\\S]*?)<\\/${tag}>`, 'i'),
  );
  return match ? decodeXml(match[1].replace(/<[^>]+>/g, ' ')) : null;
};

const normalizeLicense = value => {
  const text = normalizeText(value || '');
  const lower = text.toLowerCase();
  if (!lower) return null;
  if (
    /\b(?:agpl|gpl|lgpl|sspl)\b|proprietary|commercial|source[- ]available|business source|custom license/.test(
      lower,
    ) ||
    /\b(?:and|or|with)\b|\s\/\s|[+&]/.test(lower)
  ) {
    return null;
  }
  if (
    /^(?:the )?apache(?: software)? license(?:,? version)? 2(?:\.0)?$/.test(
      lower,
    ) ||
    /^apache 2(?:\.0)?$/.test(lower) ||
    /^apache-2(?:\.0)?$/.test(lower) ||
    /^asl 2(?:\.0)?$/.test(lower) ||
    /^https?:\/\/(?:www\.)?apache\.org\/licenses\/license-2\.0(?:\.txt)?$/.test(
      lower,
    )
  ) {
    return 'Apache-2.0';
  }
  if (/^(?:(?:the )?boost software license(?: 1\.0)?|boost-1\.0)$/.test(lower))
    return 'Boost-1.0';
  if (
    /^(?:eclipse public license(?:,? version)? 2(?:\.0)?|epl[- ]?2(?:\.0)?)$/.test(
      lower,
    )
  )
    return 'EPL-2.0';
  if (
    /^(?:eclipse public license(?:,? version)? 1(?:\.0)?|epl[- ]?1(?:\.0)?)$/.test(
      lower,
    )
  )
    return 'EPL-1.0';
  if (
    /^(?:eclipse distribution license(?:,? version)? 1(?:\.0)?|edl[- ]?1(?:\.0)?)$/.test(
      lower,
    )
  )
    return 'BSD-3-Clause';
  if (
    /^bsd-2-clause$|^(?:bsd )?2[- ]clause(?: bsd)?(?: license)?$|^simplified bsd(?: license)?$|^freebsd(?: license)?$/.test(
      lower,
    )
  ) {
    return 'BSD-2-Clause';
  }
  if (
    /^bsd-3-clause$|^(?:bsd )?3[- ]clause(?: bsd)?(?: license)?$|^(?:new|revised) bsd(?: license)?$/.test(
      lower,
    )
  ) {
    return 'BSD-3-Clause';
  }
  if (/^(?:the )?bouncy castle licen[cs]e$/.test(lower))
    return 'LicenseRef-Bouncy-Castle';
  if (/^facebook platform license$/.test(lower)) {
    return 'LicenseRef-Facebook-Platform';
  }
  if (/^android software development kit license$/.test(lower)) {
    return 'LicenseRef-Android-SDK';
  }
  if (
    /^unicode-3\.0$|^unicode(?: data files and software)? license(?: v?3(?:\.0)?)?$/.test(
      lower,
    )
  )
    return 'Unicode-3.0';
  if (/^(?:the )?public domain$/.test(lower)) return 'LicenseRef-Public-Domain';
  if (/^(?:creative commons zero(?: v?1\.0)?|cc0(?:-1\.0)?)$/.test(lower))
    return 'CC0-1.0';
  if (
    /^(?:mozilla public license(?:,? version)? 2(?:\.0)?|mpl[- ]?2(?:\.0)?)$/.test(
      lower,
    )
  )
    return 'MPL-2.0';
  if (/^(?:the )?isc(?: license)?$/.test(lower)) return 'ISC';
  if (/^(?:the )?zlib(?: license)?$/.test(lower)) return 'Zlib';
  if (/^0bsd(?: license)?$/.test(lower)) return '0BSD';
  if (/^(?:the )?(?:mit|expat)(?: license)?$/.test(lower)) return 'MIT';
  return null;
};

const parsePomLicenses = xml => {
  const block = xml.match(/<licenses(?:\s[^>]*)?>([\s\S]*?)<\/licenses>/i);
  if (!block) return [];
  const licenses = [];
  const pattern = /<license(?:\s[^>]*)?>([\s\S]*?)<\/license>/gi;
  let match;
  while ((match = pattern.exec(block[1]))) {
    const name = xmlValue(match[1], 'name');
    const url = xmlValue(match[1], 'url');
    const distribution = xmlValue(match[1], 'distribution');
    const selectedLicense = name
      ? normalizeLicense(name)
      : normalizeLicense(url);
    licenses.push({name, url, distribution, selectedLicense});
  }
  return licenses;
};

const isLegalFileName = fileName =>
  /^(?:licen[cs]e|copying|notice|copyright)(?:[._-].*)?$/i.test(fileName) &&
  !/\.(?:class|dex|so|a|o)$/i.test(fileName);

const collectLegalFilesFromDirectory = directory => {
  const result = [];
  const visit = (current, relative) => {
    for (const entry of fs
      .readdirSync(current, {withFileTypes: true})
      .sort((left, right) => compareText(left.name, right.name))) {
      if (entry.name === 'node_modules' || entry.name === '.git') continue;
      const absolute = path.join(current, entry.name);
      const childRelative = path.posix.join(relative, entry.name);
      if (entry.isDirectory()) {
        visit(absolute, childRelative);
      } else if (entry.isFile() && isLegalFileName(entry.name)) {
        const bytes = fs.readFileSync(absolute);
        if (bytes.length > 2 * 1024 * 1024) {
          throw new Error(`Legal document is unexpectedly large: ${absolute}.`);
        }
        const text = decodeUtf8(bytes, childRelative);
        result.push({path: childRelative, sha256: sha256Text(text), text});
      }
    }
  };
  visit(directory, '');
  return result.sort((left, right) => compareText(left.path, right.path));
};

const findEndOfCentralDirectory = buffer => {
  const minimum = Math.max(0, buffer.length - 65557);
  for (let offset = buffer.length - 22; offset >= minimum; offset -= 1) {
    if (buffer.readUInt32LE(offset) === 0x06054b50) return offset;
  }
  throw new Error('ZIP end-of-central-directory record is missing.');
};

const readZipEntries = (buffer, label) => {
  const eocd = findEndOfCentralDirectory(buffer);
  const entryCount = buffer.readUInt16LE(eocd + 10);
  const centralSize = buffer.readUInt32LE(eocd + 12);
  const centralOffset = buffer.readUInt32LE(eocd + 16);
  if (
    entryCount === 0xffff ||
    centralSize === 0xffffffff ||
    centralOffset === 0xffffffff
  ) {
    throw new Error(`ZIP64 is not supported for legal scan: ${label}.`);
  }
  const result = [];
  let cursor = centralOffset;
  for (let index = 0; index < entryCount; index += 1) {
    if (buffer.readUInt32LE(cursor) !== 0x02014b50) {
      throw new Error(`Malformed ZIP central directory: ${label}.`);
    }
    const flags = buffer.readUInt16LE(cursor + 8);
    const method = buffer.readUInt16LE(cursor + 10);
    const compressedSize = buffer.readUInt32LE(cursor + 20);
    const uncompressedSize = buffer.readUInt32LE(cursor + 24);
    const nameLength = buffer.readUInt16LE(cursor + 28);
    const extraLength = buffer.readUInt16LE(cursor + 30);
    const commentLength = buffer.readUInt16LE(cursor + 32);
    const localOffset = buffer.readUInt32LE(cursor + 42);
    if (
      compressedSize === 0xffffffff ||
      uncompressedSize === 0xffffffff ||
      localOffset === 0xffffffff
    ) {
      throw new Error(`ZIP64 entry is not supported for legal scan: ${label}.`);
    }
    const name = buffer
      .subarray(cursor + 46, cursor + 46 + nameLength)
      .toString(flags & 0x800 ? 'utf8' : 'utf8')
      .replace(/\\/g, '/');
    cursor += 46 + nameLength + extraLength + commentLength;
    if (name.endsWith('/')) continue;
    if (buffer.readUInt32LE(localOffset) !== 0x04034b50) {
      throw new Error(`Malformed ZIP local header: ${label}/${name}.`);
    }
    const localNameLength = buffer.readUInt16LE(localOffset + 26);
    const localExtraLength = buffer.readUInt16LE(localOffset + 28);
    const dataStart = localOffset + 30 + localNameLength + localExtraLength;
    const compressed = buffer.subarray(dataStart, dataStart + compressedSize);
    let data;
    if (method === 0) data = compressed;
    else if (method === 8) data = zlib.inflateRawSync(compressed);
    else continue;
    if (data.length !== uncompressedSize) {
      throw new Error(`ZIP size mismatch: ${label}/${name}.`);
    }
    result.push({name, data});
  }
  return result;
};

const collectLegalFilesFromArchive = (buffer, label, depth = 0) => {
  if (depth > 2) return [];
  const files = [];
  for (const entry of readZipEntries(buffer, label)) {
    const basename = path.posix.basename(entry.name);
    if (isLegalFileName(basename)) {
      if (entry.data.length > 2 * 1024 * 1024) {
        throw new Error(
          `Legal document is unexpectedly large: ${label}/${entry.name}.`,
        );
      }
      const text = decodeUtf8(entry.data, `${label}/${entry.name}`);
      files.push({path: entry.name, sha256: sha256Text(text), text});
    } else if (/\.(?:jar|zip)$/i.test(entry.name)) {
      try {
        for (const nested of collectLegalFilesFromArchive(
          entry.data,
          `${label}!/${entry.name}`,
          depth + 1,
        )) {
          files.push({...nested, path: `${entry.name}!/${nested.path}`});
        }
      } catch (error) {
        if (depth === 0) {
          throw error;
        }
      }
    }
  }
  const byHash = new Map();
  for (const file of files.sort((left, right) =>
    compareText(left.path, right.path),
  )) {
    if (!byHash.has(file.sha256)) byHash.set(file.sha256, file);
  }
  return [...byHash.values()];
};

const packageRootForPath = inputPath => {
  let cursor = fs.statSync(inputPath).isDirectory()
    ? path.resolve(inputPath)
    : path.dirname(path.resolve(inputPath));
  while (cursor !== path.dirname(cursor)) {
    const manifestPath = path.join(cursor, 'package.json');
    if (fs.existsSync(manifestPath)) {
      try {
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
        if (manifest.name && manifest.version) {
          return {directory: cursor, manifest};
        }
      } catch {
        // Keep searching for the owning package manifest.
      }
    }
    if (path.basename(cursor) === 'node_modules') break;
    cursor = path.dirname(cursor);
  }
  return null;
};

const isPathInside = (parent, candidate) => {
  const relative = path.relative(path.resolve(parent), path.resolve(candidate));
  return (
    relative === '' ||
    (!relative.startsWith('..') && !path.isAbsolute(relative))
  );
};

// Remote CocoaPods sources are compiled from the installed sandbox, not from a
// mutable Git tag or a later HTTP response. Hashing the selected source tree
// binds the legal inventory to those actual bytes. Symlinks are followed only
// when they remain inside the selected root, so an external target cannot evade
// the tree digest.
const inventorySourceTree = directory => {
  if (!fs.existsSync(directory) || !fs.statSync(directory).isDirectory()) {
    throw new Error(`Installed Pod source root is missing: ${directory}.`);
  }
  const root = fs.realpathSync(directory);
  const files = [];
  const legalFiles = [];
  const activeDirectories = new Set();
  const visit = (current, relative) => {
    const realCurrent = fs.realpathSync(current);
    if (!isPathInside(root, realCurrent)) {
      throw new Error(
        `Installed Pod source symlink escapes its root: ${current}.`,
      );
    }
    if (activeDirectories.has(realCurrent)) {
      throw new Error(
        `Installed Pod source contains a directory symlink cycle: ${current}.`,
      );
    }
    activeDirectories.add(realCurrent);
    for (const entry of fs
      .readdirSync(realCurrent, {withFileTypes: true})
      .sort((left, right) => compareText(left.name, right.name))) {
      if (entry.name === '.git' || entry.name === '.DS_Store') continue;
      const logicalPath = path.posix.join(relative, entry.name);
      const absolute = path.join(realCurrent, entry.name);
      const stats = fs.lstatSync(absolute);
      let resolved = absolute;
      let resolvedStats = stats;
      if (stats.isSymbolicLink()) {
        resolved = fs.realpathSync(absolute);
        if (!isPathInside(root, resolved)) {
          throw new Error(
            `Installed Pod source symlink escapes its root: ${logicalPath}.`,
          );
        }
        resolvedStats = fs.statSync(resolved);
      }
      if (resolvedStats.isDirectory()) {
        visit(resolved, logicalPath);
        continue;
      }
      if (!resolvedStats.isFile()) {
        throw new Error(
          `Unsupported installed Pod source entry: ${logicalPath}.`,
        );
      }
      const bytes = fs.readFileSync(resolved);
      const digest = sha256Buffer(bytes);
      files.push({path: logicalPath, sha256: digest, size: bytes.length});
      if (isLegalFileName(entry.name)) {
        if (bytes.length > 2 * 1024 * 1024) {
          throw new Error(`Legal document is unexpectedly large: ${absolute}.`);
        }
        const text = decodeUtf8(bytes, logicalPath);
        legalFiles.push({path: logicalPath, sha256: sha256Text(text), text});
      }
      if (files.length > 200000) {
        throw new Error(
          `Installed Pod source tree is unexpectedly large: ${directory}.`,
        );
      }
    }
    activeDirectories.delete(realCurrent);
  };
  visit(root, '');
  const tree = crypto.createHash('sha256');
  for (const file of files) {
    tree.update(`${file.path}\0${file.size}\0${file.sha256}\n`, 'utf8');
  }
  return {
    fileCount: files.length,
    legalFiles: legalFiles.sort((left, right) =>
      compareText(left.path, right.path),
    ),
    treeSha256: tree.digest('hex'),
  };
};

const canonicalLicenseDocument = license => {
  const source = CANONICAL_LICENSE_SOURCES[license];
  if (!source) return null;
  const absolute = path.join(ROOT, ...source);
  if (!fs.existsSync(absolute)) {
    throw new Error(
      `Canonical ${license} source is missing: ${source.join('/')}.`,
    );
  }
  const text = decodeUtf8(fs.readFileSync(absolute), source.join('/'));
  return {
    path: source.join('/'),
    sha256: sha256Text(text),
    text,
  };
};

const upstreamLegalDocumentForPod = (coordinate, owner) => {
  const review = POD_UPSTREAM_LEGAL_DOCUMENTS.get(coordinate);
  if (!review) return null;
  const npmCoordinate = owner
    ? `${owner.manifest.name}@${owner.manifest.version}`
    : null;
  if (
    npmCoordinate !== review.npmCoordinate ||
    owner.manifest.gitHead !== review.gitHead
  ) {
    throw new Error(
      `Stale upstream legal-document review for ${coordinate}: installed npm owner or gitHead changed.`,
    );
  }
  const absolute = path.join(ROOT, ...review.path);
  if (!fs.existsSync(absolute)) {
    throw new Error(
      `Pinned upstream legal document is missing for ${coordinate}.`,
    );
  }
  const text = decodeUtf8(fs.readFileSync(absolute), review.path.join('/'));
  const actualSha256 = sha256Text(text);
  if (actualSha256 !== review.sha256) {
    throw new Error(
      `Pinned upstream legal document changed for ${coordinate}.`,
    );
  }
  return {
    document: {path: review.path.join('/'), sha256: actualSha256, text},
    review: {...review, path: review.path.join('/')},
  };
};

const addDocument = (documents, file, source) => {
  const existing = documents.get(file.sha256);
  if (existing && existing.text !== file.text) {
    throw new Error(`SHA-256 collision while retaining ${source}.`);
  }
  if (!existing) {
    documents.set(file.sha256, {
      sha256: file.sha256,
      text: file.text,
      sources: [],
    });
  }
  const record = documents.get(file.sha256);
  const reference = {source, path: file.path};
  if (
    !record.sources.some(
      item => JSON.stringify(item) === JSON.stringify(reference),
    )
  ) {
    record.sources.push(reference);
    record.sources.sort((left, right) =>
      compareText(
        `${left.source}/${left.path}`,
        `${right.source}/${right.path}`,
      ),
    );
  }
  return file.sha256;
};

const requireReviewedLicenseClassification = ({
  coordinate,
  firstPartyGenerated = false,
  platform,
  selectedLicenses,
}) => {
  if (firstPartyGenerated) return;
  if (!Array.isArray(selectedLicenses) || selectedLicenses.length === 0) {
    throw new Error(
      `${platform} dependency ${coordinate} has no reviewed license classification. A LICENSE/NOTICE file alone is not an approval.`,
    );
  }
  for (const license of selectedLicenses) {
    if (!ALLOWED_LICENSES.has(license)) {
      throw new Error(
        `Unreviewed ${platform} license ${
          license || '<empty>'
        } for ${coordinate}.`,
      );
    }
  }
};

const androidEnvironment = () => {
  const environment = {...process.env};
  if (!environment.JAVA_HOME) {
    const bundled = path.join(ROOT, '.jdk17', 'jdk-17.0.20+8');
    if (fs.existsSync(bundled)) environment.JAVA_HOME = bundled;
  }
  if (!environment.ANDROID_HOME) {
    const localPropertiesPath = path.join(ROOT, 'android', 'local.properties');
    if (fs.existsSync(localPropertiesPath)) {
      const match = fs
        .readFileSync(localPropertiesPath, 'utf8')
        .match(/^sdk\.dir=(.+)$/m);
      if (match) {
        environment.ANDROID_HOME = match[1]
          .replace(/\\:/g, ':')
          .replace(/\\\\/g, '\\');
      }
    }
  }
  return environment;
};

const resolveAndroidInputs = () => {
  const temporaryDirectory = fs.mkdtempSync(
    path.join(os.tmpdir(), 'rokn-native-licenses-'),
  );
  const outputPath = path.join(temporaryDirectory, 'android.json');
  const executable = path.join(
    ROOT,
    'android',
    process.platform === 'win32' ? 'gradlew.bat' : 'gradlew',
  );
  const gradleArguments = [
    '-q',
    '-I',
    ANDROID_INIT_SCRIPT,
    ':app:roknResolvedReleaseLicenseInputs',
    `-ProknLicenseInventoryOutput=${outputPath}`,
    '-ProknBuildProfile=test',
    '-ProknDistributionChannel=direct',
  ];
  const result = childProcess.spawnSync(
    process.platform === 'win32'
      ? process.env.ComSpec || 'cmd.exe'
      : executable,
    process.platform === 'win32'
      ? ['/d', '/s', '/c', executable, ...gradleArguments]
      : gradleArguments,
    {
      cwd: path.join(ROOT, 'android'),
      encoding: 'utf8',
      env: androidEnvironment(),
      maxBuffer: 10 * 1024 * 1024,
      timeout: 10 * 60 * 1000,
    },
  );
  if (result.status !== 0 || !fs.existsSync(outputPath)) {
    throw new Error(
      `Unable to resolve Android release dependencies.\n${normalizeText(
        result.error?.message ||
          result.stderr ||
          result.stdout ||
          `Gradle exit ${result.status}`,
      )}`,
    );
  }
  const value = JSON.parse(fs.readFileSync(outputPath, 'utf8'));
  fs.rmSync(temporaryDirectory, {recursive: true, force: true});
  if (
    value.configuration !== 'releaseRuntimeClasspath' ||
    !Array.isArray(value.coordinates) ||
    !Array.isArray(value.projectComponents) ||
    !Array.isArray(value.unresolvedDependencies) ||
    !Array.isArray(value.unclassifiedLocalFiles) ||
    !Array.isArray(value.unclassifiedResolvedArtifacts) ||
    !Number.isInteger(value.resolvedArtifactCount) ||
    !Number.isInteger(value.moduleArtifactCount) ||
    !Number.isInteger(value.projectArtifactCount) ||
    value.resolvedArtifactCount !==
      value.moduleArtifactCount + value.projectArtifactCount ||
    value.unclassifiedResolvedArtifacts.length !== 0
  ) {
    throw new Error(
      'Android dependency resolver returned an invalid inventory.',
    );
  }
  return value;
};

const verifyIosLock = () => {
  const verifier = path.join(ROOT, 'scripts', 'verify-ios-pod-lock.js');
  const result = childProcess.spawnSync(process.execPath, [verifier], {
    cwd: ROOT,
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024,
  });
  if (result.status !== 0) {
    throw new Error(
      `CocoaPods notices cannot be generated from a stale lock.\n${normalizeText(
        result.stderr ||
          result.stdout ||
          `iOS lock verifier exit ${result.status}`,
      )}`,
    );
  }
};

const buildAndroidSnapshot = input => {
  const documents = new Map();
  const packageDocuments = new Map();
  const dependencies = [];
  const missingCoverage = [];
  for (const item of input.coordinates) {
    if (!item.coordinate || !item.pom || !fs.existsSync(item.pom)) {
      throw new Error(
        `Missing POM for Android dependency ${item.coordinate || '<unknown>'}.`,
      );
    }
    const pomBytes = fs.readFileSync(item.pom);
    const pomText = decodeUtf8(pomBytes, item.pom);
    const pomLicenses = parsePomLicenses(pomText);
    if (item.coordinate === 'com.parse.bolts:bolts-tasks:1.4.0') {
      for (const license of pomLicenses) {
        if (!license.selectedLicense && license.name === 'BSD License') {
          license.selectedLicense = 'BSD-3-Clause';
          license.selectionNote =
            'Exact Bolts 1.4.0 coordinate reviewed under its published BSD 3-Clause terms.';
        }
      }
    }
    for (const license of pomLicenses) {
      if (
        !license.selectedLicense ||
        !ALLOWED_LICENSES.has(license.selectedLicense)
      ) {
        throw new Error(
          `Unreviewed Android license for ${item.coordinate}: ${
            license.name || license.url || '<missing>'
          }.`,
        );
      }
    }
    const selectedLicenses = [
      ...new Set(
        pomLicenses.map(license => license.selectedLicense).filter(Boolean),
      ),
    ].sort(compareText);
    let exactLicenseSelection = null;
    const artifactRecords = [];
    const legalDocumentSha256s = new Set();
    let owningNpmPackage = null;
    const seenArtifacts = new Set();
    for (const artifact of item.artifacts || []) {
      if (!artifact.file || !fs.existsSync(artifact.file)) {
        throw new Error(
          `Missing resolved Android artifact for ${item.coordinate}.`,
        );
      }
      const bytes = fs.readFileSync(artifact.file);
      const digest = sha256Buffer(bytes);
      if (seenArtifacts.has(digest)) continue;
      seenArtifacts.add(digest);
      const legalFiles = /\.(?:aar|jar|zip)$/i.test(artifact.file)
        ? collectLegalFilesFromArchive(bytes, path.basename(artifact.file))
        : [];
      for (const file of legalFiles) {
        legalDocumentSha256s.add(
          addDocument(
            documents,
            file,
            `${item.coordinate}/${path.basename(artifact.file)}`,
          ),
        );
      }
      const owner = artifact.file.includes(`${path.sep}node_modules${path.sep}`)
        ? packageRootForPath(artifact.file)
        : null;
      if (owner) {
        const coordinate = `${owner.manifest.name}@${owner.manifest.version}`;
        if (owningNpmPackage && owningNpmPackage.coordinate !== coordinate) {
          throw new Error(`Conflicting npm owners for ${item.coordinate}.`);
        }
        owningNpmPackage = {
          coordinate,
          license: owner.manifest.license || null,
        };
        if (!packageDocuments.has(coordinate)) {
          packageDocuments.set(
            coordinate,
            collectLegalFilesFromDirectory(owner.directory),
          );
        }
        for (const file of packageDocuments.get(coordinate)) {
          legalDocumentSha256s.add(
            addDocument(documents, file, `npm:${coordinate}`),
          );
        }
        const npmLicense = normalizeLicense(owner.manifest.license);
        if (selectedLicenses.length === 0 && npmLicense)
          selectedLicenses.push(npmLicense);
      }
      artifactRecords.push({
        fileName: path.basename(artifact.file),
        type:
          artifact.type ||
          artifact.extension ||
          path.extname(artifact.file).slice(1),
        sha256: digest,
        size: bytes.length,
      });
    }
    selectedLicenses.sort(compareText);
    if (selectedLicenses.length === 0) {
      const exactSelection = ANDROID_EXACT_LICENSE_SELECTIONS.get(
        item.coordinate,
      );
      if (exactSelection) {
        exactLicenseSelection = exactSelection;
        selectedLicenses.push(exactSelection.license);
      }
    }
    requireReviewedLicenseClassification({
      coordinate: item.coordinate,
      platform: 'Android',
      selectedLicenses,
    });
    for (const license of selectedLicenses) {
      const canonical = canonicalLicenseDocument(license);
      if (canonical) {
        legalDocumentSha256s.add(
          addDocument(documents, canonical, `canonical:${license}`),
        );
      }
    }
    const absenceNote = ANDROID_LEGAL_METADATA_ABSENCE_ALLOWLIST.get(
      item.coordinate,
    );
    if (legalDocumentSha256s.size === 0 && !absenceNote) {
      missingCoverage.push(item.coordinate);
    }
    dependencies.push({
      coordinate: item.coordinate,
      pomSha256: sha256Buffer(pomBytes),
      pomLicenses,
      selectedLicenses,
      artifacts: artifactRecords.sort((left, right) =>
        compareText(
          `${left.fileName}/${left.sha256}`,
          `${right.fileName}/${right.sha256}`,
        ),
      ),
      owningNpmPackage,
      exactLicenseSelection,
      legalDocumentSha256s: [...legalDocumentSha256s].sort(compareText),
      reviewedAbsence: absenceNote || null,
    });
  }
  if (missingCoverage.length > 0) {
    throw new Error(
      `Android dependencies without retained legal documents or exact reviewed absences:\n${missingCoverage.join(
        '\n',
      )}`,
    );
  }
  dependencies.sort((left, right) =>
    compareText(left.coordinate, right.coordinate),
  );
  if (
    !Array.isArray(input.projectComponents) ||
    !Array.isArray(input.unresolvedDependencies) ||
    !Array.isArray(input.unclassifiedLocalFiles) ||
    !Array.isArray(input.unclassifiedResolvedArtifacts) ||
    !Number.isInteger(input.resolvedArtifactCount) ||
    !Number.isInteger(input.moduleArtifactCount) ||
    !Number.isInteger(input.projectArtifactCount) ||
    input.resolvedArtifactCount !==
      input.moduleArtifactCount + input.projectArtifactCount ||
    input.unresolvedDependencies.length !== 0 ||
    input.unclassifiedLocalFiles.length !== 0 ||
    input.unclassifiedResolvedArtifacts.length !== 0
  ) {
    throw new Error(
      'Android resolver did not prove a complete release artifact closure with zero unresolved or unclassified dependencies.',
    );
  }
  const npmInventory = buildNpmInventory(
    JSON.parse(fs.readFileSync(path.join(ROOT, 'package-lock.json'), 'utf8')),
  );
  const npmByCoordinate = new Map(
    npmInventory.packages.map(item => [item.coordinate, item]),
  );
  const projectComponents = input.projectComponents
    .map(component => {
      if (
        !component.sourceDirectory ||
        !fs.existsSync(component.sourceDirectory)
      ) {
        throw new Error(
          `Android project component ${component.displayName} has no resolvable source directory.`,
        );
      }
      const owner = packageRootForPath(component.sourceDirectory);
      if (!owner) {
        throw new Error(
          `Android project component ${component.displayName} is not explicitly classified as an npm source project.`,
        );
      }
      const npmCoordinate = `${owner.manifest.name}@${owner.manifest.version}`;
      const npmIdentity = npmByCoordinate.get(npmCoordinate);
      if (!npmIdentity) {
        throw new Error(
          `Android project component ${component.displayName} is not in the npm production closure: ${npmCoordinate}.`,
        );
      }
      const selectedLicenses = [npmIdentity.license];
      requireReviewedLicenseClassification({
        coordinate: `${component.buildPath}${component.projectPath}`,
        platform: 'Android project',
        selectedLicenses,
      });
      if (!packageDocuments.has(npmCoordinate)) {
        packageDocuments.set(
          npmCoordinate,
          collectLegalFilesFromDirectory(owner.directory),
        );
      }
      const legalDocumentSha256s = new Set();
      for (const file of packageDocuments.get(npmCoordinate)) {
        legalDocumentSha256s.add(
          addDocument(documents, file, `npm:${npmCoordinate}`),
        );
      }
      for (const license of selectedLicenses) {
        const canonical = canonicalLicenseDocument(license);
        if (canonical) {
          legalDocumentSha256s.add(
            addDocument(documents, canonical, `canonical:${license}`),
          );
        }
      }
      if (legalDocumentSha256s.size === 0) {
        throw new Error(
          `Android project component ${component.displayName} has no retained legal document.`,
        );
      }
      return {
        coordinate: `gradle-project:${component.buildPath}${component.projectPath}`,
        buildPath: component.buildPath,
        displayName: component.displayName,
        projectPath: component.projectPath,
        classification: 'npm-production-source-project',
        npmCoordinate,
        npmIntegrity: npmIdentity.integrity,
        selectedLicenses,
        legalDocumentSha256s: [...legalDocumentSha256s].sort(compareText),
      };
    })
    .sort((left, right) => compareText(left.coordinate, right.coordinate));
  return {
    schemaVersion: 1,
    generatedFrom: 'Gradle releaseRuntimeClasspath artifact resolution',
    configuration: input.configuration,
    dependencyCount: dependencies.length,
    dependencies,
    projectComponentCount: projectComponents.length,
    projectComponents,
    unresolvedDependencyCount: input.unresolvedDependencies.length,
    unclassifiedLocalFileCount: input.unclassifiedLocalFiles.length,
    unclassifiedResolvedArtifactCount:
      input.unclassifiedResolvedArtifacts.length,
    resolvedArtifactCount: input.resolvedArtifactCount,
    moduleArtifactCount: input.moduleArtifactCount,
    projectArtifactCount: input.projectArtifactCount,
    documents: [...documents.values()].sort((left, right) =>
      compareText(left.sha256, right.sha256),
    ),
  };
};

const sectionLines = (text, section) => {
  const lines = text.replace(/\r\n?/g, '\n').split('\n');
  const start = lines.indexOf(`${section}:`);
  if (start < 0) throw new Error(`Podfile.lock is missing ${section}.`);
  let end = start + 1;
  while (end < lines.length && (lines[end].startsWith(' ') || !lines[end]))
    end += 1;
  return lines.slice(start + 1, end);
};

const parsePodLock = text => {
  const pods = new Map();
  for (const line of sectionLines(text, 'PODS')) {
    const match = line.match(
      /^  - ['"]?([^'" (]+)['"]? \(([^ )]+)(?: [^)]+)?\):?/,
    );
    if (!match) continue;
    const name = match[1].split('/')[0];
    const version = match[2];
    const existing = pods.get(name);
    if (existing && existing !== version) {
      throw new Error(
        `Conflicting Pod versions for ${name}: ${existing}, ${version}.`,
      );
    }
    pods.set(name, version);
  }
  const checksums = new Map();
  for (const line of sectionLines(text, 'SPEC CHECKSUMS')) {
    const match = line.match(/^  ([^:]+): ([0-9a-f]{40})$/i);
    if (match) checksums.set(match[1], match[2].toLowerCase());
  }
  const repoPods = new Set();
  for (const line of sectionLines(text, 'SPEC REPOS')) {
    const match = line.match(/^    - (.+)$/);
    if (match) repoPods.add(match[1]);
  }
  const external = new Map();
  let current = null;
  for (const line of sectionLines(text, 'EXTERNAL SOURCES')) {
    const heading = line.match(/^  ([^:]+):$/);
    if (heading) {
      current = heading[1];
      external.set(current, {});
      continue;
    }
    const property = line.match(/^    :([^:]+): (.+)$/);
    if (current && property) {
      external.get(current)[property[1]] = property[2].replace(
        /^['"]|['"]$/g,
        '',
      );
    }
  }
  const lockChecksum =
    text.match(/^PODFILE CHECKSUM: ([0-9a-f]{40})$/m)?.[1] || null;
  const cocoapodsVersion = text.match(/^COCOAPODS: (.+)$/m)?.[1] || null;
  if (
    pods.size === 0 ||
    checksums.size === 0 ||
    !lockChecksum ||
    !cocoapodsVersion
  ) {
    throw new Error('Podfile.lock inventory is incomplete.');
  }
  for (const [name, version] of pods) {
    const coordinate = `${name}@${version}`;
    if (!checksums.has(name) && !FIRST_PARTY_GENERATED_PODS.has(coordinate)) {
      throw new Error(`Pod ${coordinate} has no SPEC CHECKSUM.`);
    }
    if (!repoPods.has(name) && !external.has(name)) {
      throw new Error(`Pod ${coordinate} has no exact source classification.`);
    }
  }
  return {
    pods: [...pods.entries()]
      .map(([name, version]) => ({
        name,
        version,
        coordinate: `${name}@${version}`,
        specChecksum: checksums.get(name) || null,
        firstPartyGenerated: FIRST_PARTY_GENERATED_PODS.has(
          `${name}@${version}`,
        ),
        sourceKind: repoPods.has(name) ? 'cocoapods-trunk' : 'external',
        externalSource: external.get(name) || null,
      }))
      .sort((left, right) => compareText(left.coordinate, right.coordinate)),
    lockChecksum,
    cocoapodsVersion,
  };
};

const resolveLocalPodSourceRoot = (pod, iosDirectory) => {
  const source = pod.externalSource || {};
  if (source.path) return path.resolve(iosDirectory, source.path);
  if (source.podspec && !/^[a-z][a-z0-9+.-]*:\/\//i.test(source.podspec)) {
    const candidate = path.resolve(iosDirectory, source.podspec);
    if (fs.existsSync(candidate)) {
      return fs.statSync(candidate).isDirectory()
        ? candidate
        : path.dirname(candidate);
    }
  }
  return null;
};

const resolveInstalledPodBindings = (
  inventory,
  {
    iosDirectory = IOS_DIRECTORY,
    lockPath = path.join(iosDirectory, 'Podfile.lock'),
    podsDirectory = path.join(iosDirectory, 'Pods'),
  } = {},
) => {
  const manifestPath = path.join(podsDirectory, 'Manifest.lock');
  if (!fs.existsSync(lockPath) || !fs.existsSync(manifestPath)) {
    throw new Error(
      'Installed CocoaPods sandbox provenance is missing. On macOS run bundle install and bundle exec pod install --deployment before generating native notices.',
    );
  }
  const lockText = normalizeText(fs.readFileSync(lockPath, 'utf8'));
  const manifestText = normalizeText(fs.readFileSync(manifestPath, 'utf8'));
  if (manifestText !== lockText) {
    throw new Error(
      'ios/Pods/Manifest.lock does not exactly match ios/Podfile.lock. Refusing to inventory a stale installed Pod sandbox.',
    );
  }
  const npmInventory = buildNpmInventory(
    JSON.parse(fs.readFileSync(path.join(ROOT, 'package-lock.json'), 'utf8')),
  );
  const npmByCoordinate = new Map(
    npmInventory.packages.map(item => [item.coordinate, item]),
  );
  const bindings = new Map();
  for (const pod of inventory.pods) {
    if (pod.firstPartyGenerated) {
      bindings.set(pod.coordinate, {
        kind: 'first-party-generated',
        legalFiles: [],
        sourceLocation: null,
      });
      continue;
    }
    const localRoot =
      pod.sourceKind === 'external'
        ? resolveLocalPodSourceRoot(pod, iosDirectory)
        : null;
    if (localRoot) {
      if (!fs.existsSync(localRoot) || !isPathInside(ROOT, localRoot)) {
        throw new Error(
          `Local Pod ${pod.coordinate} resolves outside the reproducible repository source tree.`,
        );
      }
      const owner = packageRootForPath(localRoot);
      if (!owner) {
        throw new Error(
          `Local Pod ${pod.coordinate} is not classified as an npm production package.`,
        );
      }
      const npmCoordinate = `${owner.manifest.name}@${owner.manifest.version}`;
      const npmIdentity = npmByCoordinate.get(npmCoordinate);
      if (!npmIdentity || !npmIdentity.integrity) {
        throw new Error(
          `Local Pod ${pod.coordinate} is not bound to the npm production lock: ${npmCoordinate}.`,
        );
      }
      bindings.set(pod.coordinate, {
        kind: 'npm-lock-sri',
        legalFiles: collectLegalFilesFromDirectory(owner.directory),
        npmCoordinate,
        npmIntegrity: npmIdentity.integrity,
        sourceLocation: `npm:${npmCoordinate}`,
      });
      continue;
    }
    const sandboxRoot = path.join(podsDirectory, pod.name);
    if (
      !fs.existsSync(sandboxRoot) ||
      !isPathInside(podsDirectory, sandboxRoot)
    ) {
      throw new Error(
        `Installed source root for remote Pod ${pod.coordinate} is missing: Pods/${pod.name}.`,
      );
    }
    const sourceTree = inventorySourceTree(sandboxRoot);
    bindings.set(pod.coordinate, {
      kind: 'pod-sandbox-source-tree',
      fileCount: sourceTree.fileCount,
      legalFiles: sourceTree.legalFiles,
      sourceLocation: `Pods/${pod.name}`,
      treeSha256: sourceTree.treeSha256,
    });
  }
  return {
    bindings,
    manifestSha256: sha256Text(manifestText),
  };
};

const md5 = value =>
  crypto.createHash('md5').update(value, 'utf8').digest('hex');
const podspecCdnUrl = ({name, version}) => {
  const digest = md5(name);
  return `https://cdn.cocoapods.org/Specs/${digest[0]}/${digest[1]}/${
    digest[2]
  }/${encodeURIComponent(name)}/${encodeURIComponent(
    version,
  )}/${encodeURIComponent(name)}.podspec.json`;
};

const fetchBytes = async (url, label) => {
  const response = await fetch(url, {
    headers: {'user-agent': 'Rokn-OSS-Gate/1.0'},
  });
  if (!response.ok) {
    throw new Error(
      `Unable to fetch ${label}: HTTP ${response.status} ${url}.`,
    );
  }
  return Buffer.from(await response.arrayBuffer());
};

const mapWithConcurrency = async (items, limit, mapper) => {
  const result = new Array(items.length);
  let next = 0;
  const workers = Array.from(
    {length: Math.min(limit, items.length)},
    async () => {
      while (next < items.length) {
        const index = next;
        next += 1;
        result[index] = await mapper(items[index], index);
      }
    },
  );
  await Promise.all(workers);
  return result;
};

const parseRubyPodspecMetadata = text => {
  const licenseBlock = text.match(/\.license\s*=\s*\{([\s\S]*?)\}/);
  const simpleLicense = text.match(/\.license\s*=\s*['"]([^'"]+)['"]/);
  const type = licenseBlock
    ? licenseBlock[1].match(/:type\s*=>\s*['"]([^'"]+)['"]/)?.[1] || null
    : simpleLicense?.[1] || null;
  const file = licenseBlock
    ? licenseBlock[1].match(/:file\s*=>\s*['"]([^'"]+)['"]/)?.[1] || null
    : null;
  const homepage = text.match(/\.homepage\s*=\s*['"]([^'"]+)['"]/)?.[1] || null;
  return {license: {type, file}, homepage};
};

const findPodspecForExternal = (pod, resolvedSource) => {
  if (resolvedSource.podspec) {
    const candidate = path.resolve(
      path.join(ROOT, 'ios'),
      resolvedSource.podspec,
    );
    return fs.existsSync(candidate) ? candidate : null;
  }
  if (!resolvedSource.path) return null;
  const directory = path.resolve(path.join(ROOT, 'ios'), resolvedSource.path);
  if (!fs.existsSync(directory)) return null;
  const direct = path.join(directory, `${pod.name}.podspec`);
  if (fs.existsSync(direct)) return direct;
  const candidates = fs
    .readdirSync(directory, {withFileTypes: true})
    .filter(entry => entry.isFile() && entry.name.endsWith('.podspec'))
    .map(entry => path.join(directory, entry.name));
  return candidates.length === 1 ? candidates[0] : null;
};

const buildExternalPodRecord = (pod, documents, packageCache) => {
  const generated = FIRST_PARTY_GENERATED_PODS.has(pod.coordinate);
  if (generated) {
    return {
      ...pod,
      sourceReference: pod.externalSource,
      specSha256: null,
      selectedLicenses: [],
      licenseMetadata: {type: 'first-party-generated', file: null},
      owningNpmPackage: null,
      upstreamLegalDocument: null,
      legalDocumentSha256s: [],
      firstPartyGenerated: true,
      exactLicenseSelection: null,
      reviewedAbsence: null,
    };
  }
  const podspecPath = findPodspecForExternal(pod, pod.externalSource || {});
  if (!podspecPath) {
    throw new Error(
      `External Pod ${pod.coordinate} has no resolvable podspec.`,
    );
  }
  const specBytes = fs.readFileSync(podspecPath);
  const specText = decodeUtf8(specBytes, podspecPath);
  const metadata = parseRubyPodspecMetadata(specText);
  const selected = normalizeLicense(metadata.license.type);
  const selectedLicenses = selected ? [selected] : [];
  if (selected && !ALLOWED_LICENSES.has(selected)) {
    throw new Error(
      `Unreviewed Pod license ${metadata.license.type} for ${pod.coordinate}.`,
    );
  }
  const legalDocumentSha256s = new Set();
  let exactLicenseSelection = null;
  if (selectedLicenses.length === 0) {
    const exactSelection = POD_EXACT_LICENSE_SELECTIONS.get(pod.coordinate);
    if (exactSelection) {
      exactLicenseSelection = exactSelection;
      selectedLicenses.push(exactSelection.license);
    }
  }
  if (metadata.license.type && !selected && !exactLicenseSelection) {
    throw new Error(
      `Pod dependency ${
        pod.coordinate
      } has no reviewed license classification for raw term ${JSON.stringify(
        metadata.license.type,
      )}.`,
    );
  }
  const owner = packageRootForPath(podspecPath);
  let owningNpmPackage = null;
  let upstreamLegalDocument = null;
  if (owner) {
    const coordinate = `${owner.manifest.name}@${owner.manifest.version}`;
    owningNpmPackage = {coordinate, license: owner.manifest.license || null};
    if (!packageCache.has(coordinate)) {
      packageCache.set(
        coordinate,
        collectLegalFilesFromDirectory(owner.directory),
      );
    }
    for (const file of packageCache.get(coordinate)) {
      legalDocumentSha256s.add(
        addDocument(documents, file, `npm:${coordinate}`),
      );
    }
    if (!selected) {
      const npmSelected = normalizeLicense(owner.manifest.license);
      if (npmSelected) selectedLicenses.push(npmSelected);
    }
    const upstream = upstreamLegalDocumentForPod(pod.coordinate, owner);
    if (upstream) {
      legalDocumentSha256s.add(
        addDocument(
          documents,
          upstream.document,
          `upstream:${upstream.review.sourceUrl}`,
        ),
      );
      upstreamLegalDocument = upstream.review;
    }
  }
  requireReviewedLicenseClassification({
    coordinate: pod.coordinate,
    platform: 'Pod',
    selectedLicenses,
  });
  for (const license of selectedLicenses) {
    const canonical = canonicalLicenseDocument(license);
    if (canonical) {
      legalDocumentSha256s.add(
        addDocument(documents, canonical, `canonical:${license}`),
      );
    }
  }
  const absenceNote = POD_LEGAL_METADATA_ABSENCE_ALLOWLIST.get(pod.coordinate);
  if (legalDocumentSha256s.size === 0 && !absenceNote) {
    throw new Error(
      `External Pod ${pod.coordinate} has no retained legal document or exact reviewed absence.`,
    );
  }
  return {
    ...pod,
    sourceReference: pod.externalSource,
    specSha256: sha256Buffer(specBytes),
    selectedLicenses: [...new Set(selectedLicenses)].sort(compareText),
    licenseMetadata: metadata.license,
    owningNpmPackage,
    upstreamLegalDocument,
    exactLicenseSelection,
    legalDocumentSha256s: [...legalDocumentSha256s].sort(compareText),
    firstPartyGenerated: false,
    reviewedAbsence: absenceNote || null,
  };
};

const buildRemotePodRecord = async (pod, documents) => {
  const url = podspecCdnUrl(pod);
  const specBytes = await fetchBytes(url, pod.coordinate);
  const actualChecksum = sha1Buffer(specBytes);
  if (actualChecksum !== pod.specChecksum) {
    throw new Error(
      `CocoaPods spec checksum mismatch for ${pod.coordinate}: expected ${pod.specChecksum}, received ${actualChecksum}.`,
    );
  }
  let spec;
  try {
    spec = JSON.parse(decodeUtf8(specBytes, url));
  } catch (error) {
    throw new Error(
      `Invalid CocoaPods spec for ${pod.coordinate}: ${error.message}`,
    );
  }
  const rawLicense =
    typeof spec.license === 'string'
      ? {type: spec.license}
      : spec.license || {};
  const selected = normalizeLicense(rawLicense.type || rawLicense.name);
  const selectedLicenses = selected ? [selected] : [];
  let exactLicenseSelection = null;
  if (selectedLicenses.length === 0) {
    const exactSelection = POD_EXACT_LICENSE_SELECTIONS.get(pod.coordinate);
    if (exactSelection) {
      exactLicenseSelection = exactSelection;
      selectedLicenses.push(exactSelection.license);
    }
  }
  if (selected && !ALLOWED_LICENSES.has(selected)) {
    throw new Error(
      `Unreviewed Pod license ${rawLicense.type || rawLicense.name} for ${
        pod.coordinate
      }.`,
    );
  }
  if (
    (rawLicense.type || rawLicense.name) &&
    !selected &&
    !exactLicenseSelection
  ) {
    throw new Error(
      `Pod dependency ${
        pod.coordinate
      } has no reviewed license classification for raw term ${JSON.stringify(
        rawLicense.type || rawLicense.name,
      )}.`,
    );
  }
  const legalDocumentSha256s = new Set();
  for (const license of selectedLicenses) {
    const canonical = canonicalLicenseDocument(license);
    if (canonical) {
      legalDocumentSha256s.add(
        addDocument(documents, canonical, `canonical:${license}`),
      );
    }
  }
  requireReviewedLicenseClassification({
    coordinate: pod.coordinate,
    platform: 'Pod',
    selectedLicenses,
  });
  const absenceNote = POD_LEGAL_METADATA_ABSENCE_ALLOWLIST.get(pod.coordinate);
  if (legalDocumentSha256s.size === 0 && !absenceNote) {
    throw new Error(
      `Remote Pod ${
        pod.coordinate
      } has no retained legal document or exact reviewed absence. License/source: ${JSON.stringify(
        {license: rawLicense, source: spec.source},
      )}.`,
    );
  }
  return {
    ...pod,
    sourceReference: url,
    specSha256: sha256Buffer(specBytes),
    selectedLicenses,
    licenseMetadata: {
      type: rawLicense.type || rawLicense.name || null,
      file: rawLicense.file || null,
    },
    homepage: spec.homepage || null,
    source: spec.source || null,
    owningNpmPackage: null,
    exactLicenseSelection,
    legalDocumentSha256s: [...legalDocumentSha256s].sort(compareText),
    firstPartyGenerated: false,
    reviewedAbsence: absenceNote || null,
  };
};

const attachInstalledPodBinding = (record, binding, documents) => {
  if (!binding) {
    throw new Error(
      `Installed source binding is missing for Pod ${record.coordinate}.`,
    );
  }
  if (record.firstPartyGenerated) {
    if (binding.kind !== 'first-party-generated') {
      throw new Error(
        `First-party generated Pod ${record.coordinate} has an invalid source binding.`,
      );
    }
    return {
      ...record,
      installedLegalDocumentSha256s: [],
      installedSource: {kind: binding.kind},
    };
  }
  if (binding.kind === 'npm-lock-sri') {
    if (
      record.owningNpmPackage?.coordinate !== binding.npmCoordinate ||
      !binding.npmIntegrity
    ) {
      throw new Error(
        `Local Pod ${record.coordinate} does not match its installed npm source binding.`,
      );
    }
  } else if (
    binding.kind !== 'pod-sandbox-source-tree' ||
    !Number.isInteger(binding.fileCount) ||
    !/^[0-9a-f]{64}$/.test(binding.treeSha256 || '')
  ) {
    throw new Error(
      `Remote Pod ${record.coordinate} has no immutable installed source-tree binding.`,
    );
  }
  const installedLegalDocumentSha256s = [];
  for (const file of binding.legalFiles || []) {
    installedLegalDocumentSha256s.push(
      addDocument(documents, file, `installed-pod:${record.coordinate}`),
    );
  }
  const actualHashes = [...new Set(installedLegalDocumentSha256s)].sort(
    compareText,
  );
  if (
    actualHashes.length === 0 &&
    !record.upstreamLegalDocument &&
    !POD_LEGAL_METADATA_ABSENCE_ALLOWLIST.has(record.coordinate)
  ) {
    throw new Error(
      `Pod ${record.coordinate} ships no LICENSE/LICENCE/COPYING/NOTICE/COPYRIGHT file in its installed source root. Canonical license text alone is not package notice coverage.`,
    );
  }
  return {
    ...record,
    legalDocumentSha256s: [
      ...new Set([...(record.legalDocumentSha256s || []), ...actualHashes]),
    ].sort(compareText),
    installedLegalDocumentSha256s: actualHashes,
    installedSource:
      binding.kind === 'npm-lock-sri'
        ? {
            kind: binding.kind,
            npmCoordinate: binding.npmCoordinate,
            npmIntegrity: binding.npmIntegrity,
            sourceLocation: binding.sourceLocation,
          }
        : {
            kind: binding.kind,
            fileCount: binding.fileCount,
            sourceLocation: binding.sourceLocation,
            treeSha256: binding.treeSha256,
          },
  };
};

const buildPodsSnapshot = async (inventory, options = {}) => {
  const documents = new Map();
  const packageCache = new Map();
  const installed = resolveInstalledPodBindings(inventory, options);
  const dependencies = await mapWithConcurrency(
    inventory.pods,
    8,
    async pod => {
      const record =
        pod.sourceKind === 'cocoapods-trunk'
          ? buildRemotePodRecord(pod, documents)
          : Promise.resolve(
              buildExternalPodRecord(pod, documents, packageCache),
            );
      return attachInstalledPodBinding(
        await record,
        installed.bindings.get(pod.coordinate),
        documents,
      );
    },
  );
  dependencies.sort((left, right) =>
    compareText(left.coordinate, right.coordinate),
  );
  return {
    schemaVersion: 2,
    generatedFrom:
      'Podfile.lock exact roots, CocoaPods SPEC CHECKSUMS, Pods/Manifest.lock, and installed source bytes',
    podfileChecksum: inventory.lockChecksum,
    cocoapodsVersion: inventory.cocoapodsVersion,
    manifestSha256: installed.manifestSha256,
    installedSourceCount: dependencies.filter(item => !item.firstPartyGenerated)
      .length,
    dependencyCount: dependencies.length,
    dependencies,
    documents: [...documents.values()].sort((left, right) =>
      compareText(left.sha256, right.sha256),
    ),
  };
};

const validateDocumentTable = (snapshot, label) => {
  if (!Array.isArray(snapshot.documents))
    throw new Error(`${label} documents are missing.`);
  const byHash = new Map();
  for (const document of snapshot.documents) {
    if (
      !document.text ||
      document.sha256 !== sha256Text(document.text) ||
      !Array.isArray(document.sources) ||
      document.sources.length === 0
    ) {
      throw new Error(`${label} contains an invalid retained legal document.`);
    }
    byHash.set(document.sha256, document);
  }
  return byHash;
};

const comparableAndroid = snapshot => ({
  ...snapshot,
  documents: snapshot.documents.map(document => ({
    ...document,
    sources: [...document.sources].sort((left, right) =>
      compareText(
        `${left.source}/${left.path}`,
        `${right.source}/${right.path}`,
      ),
    ),
  })),
});

const validateAndroidSnapshot = (input, snapshot) => {
  if (
    snapshot.schemaVersion !== 1 ||
    snapshot.configuration !== 'releaseRuntimeClasspath' ||
    snapshot.dependencyCount !== input.coordinates.length ||
    snapshot.projectComponentCount !== input.projectComponents.length ||
    snapshot.unresolvedDependencyCount !== 0 ||
    snapshot.unclassifiedLocalFileCount !== 0 ||
    snapshot.unclassifiedResolvedArtifactCount !== 0 ||
    snapshot.resolvedArtifactCount !== input.resolvedArtifactCount ||
    snapshot.moduleArtifactCount !== input.moduleArtifactCount ||
    snapshot.projectArtifactCount !== input.projectArtifactCount ||
    snapshot.resolvedArtifactCount !==
      snapshot.moduleArtifactCount + snapshot.projectArtifactCount ||
    !Array.isArray(snapshot.dependencies) ||
    !Array.isArray(snapshot.projectComponents)
  ) {
    throw new Error(
      'Android legal snapshot does not match the release closure.',
    );
  }
  const documents = validateDocumentTable(snapshot, 'Android snapshot');
  const dependenciesByCoordinate = new Map(
    snapshot.dependencies.map(item => [item.coordinate, item]),
  );
  for (const dependency of snapshot.dependencies) {
    requireReviewedLicenseClassification({
      coordinate: dependency.coordinate,
      platform: 'Android',
      selectedLicenses: dependency.selectedLicenses,
    });
    for (const hash of dependency.legalDocumentSha256s || []) {
      if (!documents.has(hash)) {
        throw new Error(
          `Missing Android legal text ${hash} for ${dependency.coordinate}.`,
        );
      }
    }
    if (
      (dependency.legalDocumentSha256s || []).length === 0 &&
      !ANDROID_LEGAL_METADATA_ABSENCE_ALLOWLIST.has(dependency.coordinate)
    ) {
      throw new Error(
        `Android dependency ${dependency.coordinate} is not legally covered.`,
      );
    }
  }
  for (const component of snapshot.projectComponents) {
    requireReviewedLicenseClassification({
      coordinate: component.coordinate,
      platform: 'Android project',
      selectedLicenses: component.selectedLicenses,
    });
    if (
      component.classification !== 'npm-production-source-project' ||
      !component.npmCoordinate ||
      !component.npmIntegrity ||
      !Array.isArray(component.legalDocumentSha256s) ||
      component.legalDocumentSha256s.length === 0
    ) {
      throw new Error(
        `Invalid Android project-component classification: ${component.coordinate}.`,
      );
    }
    for (const hash of component.legalDocumentSha256s) {
      if (!documents.has(hash)) {
        throw new Error(
          `Missing Android project legal text ${hash} for ${component.coordinate}.`,
        );
      }
    }
  }
  for (const [coordinate, note] of ANDROID_LEGAL_METADATA_ABSENCE_ALLOWLIST) {
    const dependency = dependenciesByCoordinate.get(coordinate);
    if (!dependency || dependency.reviewedAbsence !== note) {
      throw new Error(`Stale Android legal-absence review: ${coordinate}.`);
    }
  }
  for (const [coordinate, review] of ANDROID_EXACT_LICENSE_SELECTIONS) {
    const dependency = dependenciesByCoordinate.get(coordinate);
    if (
      !dependency ||
      !dependency.selectedLicenses.includes(review.license) ||
      JSON.stringify(dependency.exactLicenseSelection) !==
        JSON.stringify(review)
    ) {
      throw new Error(`Stale Android exact license selection: ${coordinate}.`);
    }
  }
  const rebuilt = buildAndroidSnapshot(input);
  if (
    JSON.stringify(comparableAndroid(snapshot)) !==
    JSON.stringify(comparableAndroid(rebuilt))
  ) {
    throw new Error(
      'Android release dependency or legal inputs changed. Regenerate native notices.',
    );
  }
  return snapshot;
};

const validateInstalledPodBindings = (
  inventory,
  snapshot,
  documents,
  options = {},
) => {
  const installed = resolveInstalledPodBindings(inventory, options);
  if (
    snapshot.manifestSha256 !== installed.manifestSha256 ||
    snapshot.installedSourceCount !==
      inventory.pods.filter(item => !item.firstPartyGenerated).length
  ) {
    throw new Error(
      'CocoaPods snapshot is not bound to the installed Pod sandbox.',
    );
  }
  const dependencies = new Map(
    snapshot.dependencies.map(item => [item.coordinate, item]),
  );
  for (const pod of inventory.pods) {
    const dependency = dependencies.get(pod.coordinate);
    const binding = installed.bindings.get(pod.coordinate);
    if (!dependency || !binding) {
      throw new Error(
        `Installed Pod source binding is missing for ${pod.coordinate}.`,
      );
    }
    const expectedSource =
      binding.kind === 'first-party-generated'
        ? {kind: binding.kind}
        : binding.kind === 'npm-lock-sri'
        ? {
            kind: binding.kind,
            npmCoordinate: binding.npmCoordinate,
            npmIntegrity: binding.npmIntegrity,
            sourceLocation: binding.sourceLocation,
          }
        : {
            kind: binding.kind,
            fileCount: binding.fileCount,
            sourceLocation: binding.sourceLocation,
            treeSha256: binding.treeSha256,
          };
    if (
      JSON.stringify(dependency.installedSource) !==
      JSON.stringify(expectedSource)
    ) {
      throw new Error(
        `Installed Pod source bytes changed for ${pod.coordinate}.`,
      );
    }
    const actualLegalHashes = [
      ...new Set((binding.legalFiles || []).map(file => file.sha256)),
    ].sort(compareText);
    if (
      JSON.stringify(dependency.installedLegalDocumentSha256s || []) !==
      JSON.stringify(actualLegalHashes)
    ) {
      throw new Error(
        `Installed Pod legal files changed for ${pod.coordinate}.`,
      );
    }
    if (
      !pod.firstPartyGenerated &&
      actualLegalHashes.length === 0 &&
      !dependency.upstreamLegalDocument &&
      !POD_LEGAL_METADATA_ABSENCE_ALLOWLIST.has(pod.coordinate)
    ) {
      throw new Error(
        `Installed Pod legal coverage is missing for ${pod.coordinate}.`,
      );
    }
    for (const file of binding.legalFiles || []) {
      if (documents.get(file.sha256)?.text !== file.text) {
        throw new Error(
          `Installed Pod legal text is not retained for ${pod.coordinate}.`,
        );
      }
    }
  }
  return installed;
};

const validatePodsSnapshot = (
  inventory,
  snapshot,
  {verifyInstalled = true, verifyLocal = true, ...installedOptions} = {},
) => {
  if (
    snapshot.schemaVersion !== 2 ||
    snapshot.podfileChecksum !== inventory.lockChecksum ||
    snapshot.cocoapodsVersion !== inventory.cocoapodsVersion ||
    snapshot.dependencyCount !== inventory.pods.length ||
    !Array.isArray(snapshot.dependencies)
  ) {
    throw new Error('CocoaPods legal snapshot does not match Podfile.lock.');
  }
  const documents = validateDocumentTable(snapshot, 'CocoaPods snapshot');
  if (verifyInstalled) {
    validateInstalledPodBindings(
      inventory,
      snapshot,
      documents,
      installedOptions,
    );
  }
  const expectedByCoordinate = new Map(
    inventory.pods.map(item => [item.coordinate, item]),
  );
  const dependenciesByCoordinate = new Map(
    snapshot.dependencies.map(item => [item.coordinate, item]),
  );
  for (const dependency of snapshot.dependencies) {
    const expected = expectedByCoordinate.get(dependency.coordinate);
    if (
      !expected ||
      dependency.specChecksum !== expected.specChecksum ||
      dependency.sourceKind !== expected.sourceKind ||
      JSON.stringify(dependency.externalSource) !==
        JSON.stringify(expected.externalSource)
    ) {
      throw new Error(`Stale CocoaPods record for ${dependency.coordinate}.`);
    }
    requireReviewedLicenseClassification({
      coordinate: dependency.coordinate,
      firstPartyGenerated: dependency.firstPartyGenerated,
      platform: 'Pod',
      selectedLicenses: dependency.selectedLicenses,
    });
    for (const hash of dependency.legalDocumentSha256s || []) {
      if (!documents.has(hash)) {
        throw new Error(
          `Missing Pod legal text ${hash} for ${dependency.coordinate}.`,
        );
      }
    }
    if (
      !dependency.firstPartyGenerated &&
      (dependency.legalDocumentSha256s || []).length === 0 &&
      !POD_LEGAL_METADATA_ABSENCE_ALLOWLIST.has(dependency.coordinate)
    ) {
      throw new Error(`Pod ${dependency.coordinate} is not legally covered.`);
    }
  }
  for (const [coordinate, note] of POD_LEGAL_METADATA_ABSENCE_ALLOWLIST) {
    const dependency = dependenciesByCoordinate.get(coordinate);
    if (!dependency || dependency.reviewedAbsence !== note) {
      throw new Error(`Stale Pod legal-absence review: ${coordinate}.`);
    }
  }
  for (const [coordinate, review] of POD_UPSTREAM_LEGAL_DOCUMENTS) {
    const dependency = dependenciesByCoordinate.get(coordinate);
    if (
      !dependency ||
      JSON.stringify(dependency.upstreamLegalDocument) !==
        JSON.stringify({...review, path: review.path.join('/')}) ||
      !dependency.legalDocumentSha256s.includes(review.sha256)
    ) {
      throw new Error(
        `Stale upstream Pod legal-document review: ${coordinate}.`,
      );
    }
  }
  for (const [coordinate, review] of POD_EXACT_LICENSE_SELECTIONS) {
    const dependency = dependenciesByCoordinate.get(coordinate);
    if (
      !dependency ||
      !dependency.selectedLicenses.includes(review.license) ||
      JSON.stringify(dependency.exactLicenseSelection) !==
        JSON.stringify(review)
    ) {
      throw new Error(`Stale Pod exact license selection: ${coordinate}.`);
    }
  }
  if (verifyLocal) {
    const localInventory = {
      ...inventory,
      pods: inventory.pods.filter(item => item.sourceKind === 'external'),
    };
    const localSnapshot = {
      dependencies: snapshot.dependencies.filter(
        item => item.sourceKind === 'external',
      ),
    };
    const freshDocuments = new Map();
    const packageCache = new Map();
    const rebuilt = localInventory.pods.map(item =>
      buildExternalPodRecord(item, freshDocuments, packageCache),
    );
    const comparable = value => ({
      coordinate: value.coordinate,
      specChecksum: value.specChecksum,
      sourceKind: value.sourceKind,
      externalSource: value.externalSource,
      sourceReference: value.sourceReference,
      specSha256: value.specSha256,
      selectedLicenses: value.selectedLicenses,
      licenseMetadata: value.licenseMetadata,
      owningNpmPackage: value.owningNpmPackage,
      upstreamLegalDocument: value.upstreamLegalDocument || null,
      exactLicenseSelection: value.exactLicenseSelection,
      legalDocumentSha256s: value.legalDocumentSha256s,
      firstPartyGenerated: value.firstPartyGenerated,
      reviewedAbsence: value.reviewedAbsence,
    });
    if (
      JSON.stringify(localSnapshot.dependencies.map(comparable)) !==
      JSON.stringify(rebuilt.map(comparable))
    ) {
      throw new Error(
        'Local Pod podspec or legal inputs changed. Regenerate native notices.',
      );
    }
    const freshByHash = new Map(
      [...freshDocuments].map(([hash, value]) => [hash, value.text]),
    );
    for (const dependency of rebuilt) {
      for (const hash of dependency.legalDocumentSha256s) {
        if (freshByHash.get(hash) !== documents.get(hash)?.text) {
          throw new Error(
            `Local Pod legal text changed for ${dependency.coordinate}.`,
          );
        }
      }
    }
  }
  return snapshot;
};

const escapeCodeFence = value => value.replace(/```/g, '``\u200b`');

const renderDependency = (
  lines,
  dependency,
  documents,
  platform,
  includeDocumentText = true,
) => {
  lines.push(
    `### ${dependency.coordinate}`,
    '',
    `- Platform: ${platform}`,
    `- Selected license(s): ${
      (dependency.selectedLicenses || []).join(', ') ||
      'first-party generated target'
    }`,
  );
  if (dependency.specChecksum)
    lines.push(`- Podspec checksum: \`${dependency.specChecksum}\``);
  if (dependency.pomSha256)
    lines.push(`- POM SHA-256: \`${dependency.pomSha256}\``);
  if (dependency.owningNpmPackage) {
    lines.push(
      `- Owning npm source: \`${dependency.owningNpmPackage.coordinate}\``,
    );
  }
  if (dependency.npmCoordinate) {
    lines.push(
      `- Classified npm production source: \`${dependency.npmCoordinate}\``,
    );
  }
  if (dependency.exactLicenseSelection) {
    lines.push(
      `- Exact license review: ${dependency.exactLicenseSelection.reason}`,
    );
  }
  if (dependency.reviewedAbsence) {
    lines.push(`- Exact reviewed absence: ${dependency.reviewedAbsence}`);
  }
  if (!includeDocumentText) {
    lines.push(
      `- Retained legal document(s): ${
        (dependency.legalDocumentSha256s || [])
          .map(hash => `\`${hash}\``)
          .join(', ') || 'none (exact reviewed absence above)'
      }`,
    );
  }
  lines.push('');
  if (!includeDocumentText) return;
  for (const hash of dependency.legalDocumentSha256s || []) {
    const document = documents.get(hash);
    lines.push(
      `#### Retained legal document ${hash}`,
      '',
      `Source(s): ${document.sources
        .map(item => `\`${item.source}/${item.path}\``)
        .join(', ')}`,
      '',
      '```text',
      escapeCodeFence(document.text),
      '```',
      '',
    );
  }
};

const renderMarkdown = (
  android,
  pods,
  bundledFont = buildBundledFontInventory(),
) => {
  const androidDocuments = new Map(
    android.documents.map(item => [item.sha256, item]),
  );
  const podDocuments = new Map(pods.documents.map(item => [item.sha256, item]));
  const lines = [
    '# Native Third-Party Notices / إشعارات مكتبات المنصات',
    '',
    '<!-- Generated by scripts/generate-native-third-party-notices.js. Do not edit manually. -->',
    '',
    'هذا الملف مرتبط فعليًا بإغلاق تبعيات Android releaseRuntimeClasspath وبـ Podfile.lock. يحتفظ بالنصوص القانونية الخاصة بالحزم وملفات NOTICE المنشورة، ويستخدم نصًا قياسيًا فقط عندما تعلن الحزمة رخصة قياسية ولا تنشر نصًا خاصًا بها.',
    '',
    'This artifact is bound to the resolved Android releaseRuntimeClasspath and the exact Podfile.lock roots/checksums. Package-specific legal files and NOTICE files take precedence; standard terms are used only for an explicitly declared standard license when no package-specific text is published.',
    '',
    `- Android Maven coordinates: ${android.dependencyCount}`,
    `- CocoaPods roots: ${pods.dependencyCount}`,
    `- Android retained legal texts: ${android.documents.length}`,
    `- CocoaPods retained legal texts: ${pods.documents.length}`,
    '',
    '## Android release dependencies',
    '',
  ];
  for (const dependency of android.dependencies) {
    renderDependency(lines, dependency, androidDocuments, 'Android');
  }
  lines.push('## Android npm source-project components', '');
  for (const component of android.projectComponents) {
    renderDependency(lines, component, androidDocuments, 'Android project');
  }
  lines.push('## CocoaPods dependencies', '');
  for (const dependency of pods.dependencies) {
    renderDependency(lines, dependency, podDocuments, 'iOS');
  }
  lines.push(renderBundledFontMarkdown(bundledFont));
  return `${lines.join('\n').trimEnd()}\n`;
};

const renderPlatformMarkdown = (
  snapshot,
  platform,
  bundledFont = buildBundledFontInventory(),
) => {
  const documents = new Map(
    snapshot.documents.map(item => [item.sha256, item]),
  );
  const android = platform === 'Android';
  const lines = [
    `# ${platform} Third-Party Notices / إشعارات مكتبات ${platform}`,
    '',
    '<!-- Generated by scripts/generate-native-third-party-notices.js. Do not edit manually. -->',
    '',
    android
      ? 'هذا الملف مرتبط بنتيجة Gradle releaseRuntimeClasspath الفعلية ويحتفظ بملفات LICENSE وNOTICE المنشورة مع الاعتمادات الموزعة.'
      : 'هذا الملف مرتبط بجذور Podfile.lock وSPEC CHECKSUMS الفعلية ويحتفظ بملفات LICENSE وNOTICE المنشورة مع الاعتمادات الموزعة.',
    '',
    android
      ? 'This file is bound to the resolved Gradle releaseRuntimeClasspath and retains package-specific LICENSE and NOTICE documents from the distributed artifacts.'
      : 'This file is bound to the exact Podfile.lock roots and SPEC CHECKSUMS and retains package-specific LICENSE and NOTICE documents from the distributed sources.',
    '',
    `- Dependencies: ${snapshot.dependencyCount}`,
    ...(android
      ? [`- npm source-project components: ${snapshot.projectComponentCount}`]
      : []),
    `- Retained unique legal texts: ${snapshot.documents.length}`,
    '',
  ];
  for (const dependency of snapshot.dependencies) {
    renderDependency(lines, dependency, documents, platform, false);
  }
  if (android) {
    for (const component of snapshot.projectComponents) {
      renderDependency(lines, component, documents, 'Android project', false);
    }
  }
  lines.push('## Retained legal document catalog', '');
  for (const document of snapshot.documents) {
    lines.push(
      `### ${document.sha256}`,
      '',
      `Source(s): ${document.sources
        .map(item => `\`${item.source}/${item.path}\``)
        .join(', ')}`,
      '',
      '```text',
      escapeCodeFence(document.text),
      '```',
      '',
    );
  }
  lines.push(renderBundledFontMarkdown(bundledFont));
  return `${lines.join('\n').trimEnd()}\n`;
};

const summarizeBundledFont = bundledFont => ({
  coordinate: bundledFont.coordinate,
  licenses: [bundledFont.license],
  legalDocumentCount: 1,
  fileCount: bundledFont.files.length,
  licenseSha256: bundledFont.licenseSha256,
  files: bundledFont.files,
});

const renderAppData = (
  android,
  pods,
  bundledFont = buildBundledFontInventory(),
) => {
  const summarize = dependency => ({
    coordinate: dependency.coordinate,
    licenses: dependency.selectedLicenses || [],
    legalDocumentCount: (dependency.legalDocumentSha256s || []).length,
    firstPartyGenerated: Boolean(dependency.firstPartyGenerated),
  });
  const value = {
    schemaVersion: 1,
    androidDependencyCount: android.dependencyCount,
    androidProjectComponentCount: android.projectComponentCount,
    podDependencyCount: pods.dependencyCount,
    android: android.dependencies.map(summarize),
    androidProjects: android.projectComponents.map(summarize),
    pods: pods.dependencies.map(summarize),
    bundledAssets: [summarizeBundledFont(bundledFont)],
  };
  value.inventoryHash = sha256Text(JSON.stringify(value));
  return `${JSON.stringify(value, null, 2)}\n`;
};

const renderAndroidAppData = (
  android,
  bundledFont = buildBundledFontInventory(),
  pods = {dependencyCount: null, dependencies: []},
) => {
  return renderAppData(android, pods, bundledFont);
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
        )} is stale. Run npm run notices:native:generate.`,
      );
    }
    return;
  }
  fs.mkdirSync(path.dirname(filePath), {recursive: true});
  fs.writeFileSync(filePath, expected, 'utf8');
};

const main = async () => {
  const check = process.argv.includes('--check');
  const androidOnly = process.argv.includes('--android-only');
  const iosSourcesCheck = process.argv.includes('--ios-sources-check');
  const portableCheck = process.argv.includes('--portable-check');
  if (portableCheck && !check) {
    throw new Error('--portable-check is valid only together with --check.');
  }
  const bundledFont = buildBundledFontInventory();
  if (iosSourcesCheck) {
    verifyIosLock();
    if (!fs.existsSync(PODS_SNAPSHOT_PATH)) {
      throw new Error(
        'Generated CocoaPods legal snapshot is missing. Run npm run notices:native:generate on macOS after pod install.',
      );
    }
    const podInventory = parsePodLock(fs.readFileSync(POD_LOCK_PATH, 'utf8'));
    const pods = JSON.parse(fs.readFileSync(PODS_SNAPSHOT_PATH, 'utf8'));
    validatePodsSnapshot(podInventory, pods, {verifyLocal: false});
    console.log(
      `Installed CocoaPods source provenance passed for ${pods.installedSourceCount} third-party roots.`,
    );
    return;
  }
  if (!androidOnly) verifyIosLock();
  const androidInput = resolveAndroidInputs();
  const android = check
    ? JSON.parse(fs.readFileSync(ANDROID_SNAPSHOT_PATH, 'utf8'))
    : buildAndroidSnapshot(androidInput);
  validateAndroidSnapshot(androidInput, android);
  const androidText = `${JSON.stringify(android, null, 2)}\n`;
  const androidMarkdown = renderPlatformMarkdown(
    android,
    'Android',
    bundledFont,
  );
  writeOrCheck(ANDROID_SNAPSHOT_PATH, androidText, check);
  writeOrCheck(ANDROID_MARKDOWN_PATH, androidMarkdown, check);
  writeOrCheck(ANDROID_NOTICE_PATH, androidMarkdown, check);
  if (androidOnly) {
    const retainedPods = fs.existsSync(PODS_SNAPSHOT_PATH)
      ? JSON.parse(fs.readFileSync(PODS_SNAPSHOT_PATH, 'utf8'))
      : {dependencyCount: null, dependencies: []};
    writeOrCheck(
      APP_DATA_PATH,
      renderAndroidAppData(android, bundledFont, retainedPods),
      check,
    );
    console.log(
      `Android legal gate passed for ${android.dependencyCount} release Maven coordinates and ${android.projectComponentCount} npm source-project components.`,
    );
    return;
  }

  const podInventory = parsePodLock(fs.readFileSync(POD_LOCK_PATH, 'utf8'));
  const pods = check
    ? JSON.parse(fs.readFileSync(PODS_SNAPSHOT_PATH, 'utf8'))
    : await buildPodsSnapshot(podInventory);
  validatePodsSnapshot(
    podInventory,
    pods,
    portableCheck ? {verifyInstalled: false} : {},
  );
  const podsText = `${JSON.stringify(pods, null, 2)}\n`;
  const markdown = renderMarkdown(android, pods, bundledFont);
  const iosMarkdown = renderPlatformMarkdown(pods, 'iOS', bundledFont);
  const appData = renderAppData(android, pods, bundledFont);
  writeOrCheck(PODS_SNAPSHOT_PATH, podsText, check);
  writeOrCheck(MARKDOWN_PATH, markdown, check);
  writeOrCheck(IOS_MARKDOWN_PATH, iosMarkdown, check);
  writeOrCheck(APP_DATA_PATH, appData, check);
  writeOrCheck(IOS_NOTICE_PATH, iosMarkdown, check);
  console.log(
    `Native legal gate passed for ${android.dependencyCount} Android Maven coordinates and ${pods.dependencyCount} CocoaPods roots.`,
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
  ANDROID_EXACT_LICENSE_SELECTIONS,
  ANDROID_LEGAL_METADATA_ABSENCE_ALLOWLIST,
  FIRST_PARTY_GENERATED_PODS,
  POD_LEGAL_METADATA_ABSENCE_ALLOWLIST,
  POD_UPSTREAM_LEGAL_DOCUMENTS,
  POD_EXACT_LICENSE_SELECTIONS,
  buildAndroidSnapshot,
  buildExternalPodRecord,
  buildPodsSnapshot,
  buildRemotePodRecord,
  collectLegalFilesFromArchive,
  inventorySourceTree,
  normalizeLicense,
  parsePodLock,
  parsePomLicenses,
  requireReviewedLicenseClassification,
  renderAndroidAppData,
  renderPlatformMarkdown,
  resolveInstalledPodBindings,
  validateAndroidSnapshot,
  validateInstalledPodBindings,
  validatePodsSnapshot,
  verifyIosLock,
};
