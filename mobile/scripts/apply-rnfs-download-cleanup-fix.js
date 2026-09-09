'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {transformNativeSources} = require('./rnfs-ios-download-lifecycle-fix');

const SUPPORTED_VERSION = '2.20.0';
const MOBILE_ROOT = path.resolve(__dirname, '..');
// Keep this version-checked fix until upstream handles terminal download cleanup
// and provides cancellation distinct from the iOS stop/resume contract.
const before = `promise: RNFSManager.downloadFile(bridgeOptions).then(res => {
        subscriptions.forEach(sub => sub.remove());
        return res;
      })
        .catch(e => {
          return Promise.reject(e);
        })`;
const after = before.replace(
  '          return Promise.reject(e);',
  '          subscriptions.forEach(sub => sub.remove());\n          return Promise.reject(e);',
);

function replaceAudited(contents, original, replacement) {
  const beforeCount = contents.split(original).length - 1;
  const afterCount = contents.split(replacement).length - 1;
  if (afterCount === 1 && beforeCount === 1) return contents;
  if (beforeCount !== 1 || afterCount !== 0) {
    throw new Error(
      'Unexpected RNFS download source; re-audit before changing it.',
    );
  }
  return contents.replace(original, replacement);
}

function fixedSource(contents) {
  const beforeCount = contents.split(before).length - 1;
  const afterCount = contents.split(after).length - 1;
  if (
    !(beforeCount === 0 && afterCount === 1) &&
    !(beforeCount === 1 && afterCount === 0)
  ) {
    throw new Error(
      'Unexpected RNFS download source; re-audit listener cleanup before changing it.',
    );
  }
  const stop = `  stopDownload(jobId: number): void {
    RNFSManager.stopDownload(jobId);
  },`;
  return replaceAudited(
    contents.replace(before, after),
    stop,
    `${stop}

  // iOS only: permanent abandonment, not a pause for resumeDownload.
  cancelDownload(jobId: number): void {
    RNFSManager.cancelDownload(jobId);
  },`,
  );
}

function applyFix({root = MOBILE_ROOT, check = false} = {}) {
  const packageRoot = path.join(root, 'node_modules', 'react-native-fs');
  const version = JSON.parse(
    fs.readFileSync(path.join(packageRoot, 'package.json'), 'utf8'),
  ).version;
  if (version !== SUPPORTED_VERSION) {
    throw new Error(
      `RNFS ${version} is not the audited ${SUPPORTED_VERSION}; re-audit or remove this fix.`,
    );
  }
  const originals = Object.fromEntries(
    [
      'FS.common.js',
      'index.d.ts',
      'Downloader.h',
      'Downloader.m',
      'RNFSManager.m',
    ].map(name => [
      name,
      fs.readFileSync(path.join(packageRoot, name), 'utf8'),
    ]),
  );
  const sources = Object.fromEntries(
    Object.entries(originals).map(([name, source]) => [
      name,
      source.replace(/\r\n/g, '\n'),
    ]),
  );
  const types = 'export function stopDownload(jobId: number): void';
  const fixed = {
    ...transformNativeSources(sources),
    'FS.common.js': fixedSource(sources['FS.common.js']),
    'index.d.ts': replaceAudited(
      sources['index.d.ts'],
      types,
      `${types}\n/** iOS only: permanently cancel a job rather than pause it. */\nexport function cancelDownload(jobId: number): void`,
    ),
  };
  // Validate every source before writing any part of this version-locked fix.
  for (const [name, source] of Object.entries(fixed)) {
    if (source === sources[name]) continue;
    if (check)
      throw new Error('RNFS download lifecycle fix has not been applied.');
    fs.writeFileSync(
      path.join(packageRoot, name),
      originals[name].includes('\r\n') ? source.replace(/\n/g, '\r\n') : source,
      'utf8',
    );
  }
  return {version};
}

if (require.main === module) {
  const {version} = applyFix({check: process.argv.includes('--check')});
  console.log(`RNFS download lifecycle fix verified (v${version}).`);
}

module.exports = {SUPPORTED_VERSION, applyFix, fixedSource};
