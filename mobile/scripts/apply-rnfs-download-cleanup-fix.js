'use strict';

const fs = require('node:fs');
const path = require('node:path');

const SUPPORTED_VERSION = '2.20.0';
const MOBILE_ROOT = path.resolve(__dirname, '..');
// RNFS removes these job-owned listeners on success but not on rejection.
// Keep this version-checked fix until the installed upstream release does both.
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

function fixedSource(contents) {
  const beforeCount = contents.split(before).length - 1;
  const afterCount = contents.split(after).length - 1;
  if (beforeCount === 0 && afterCount === 1) return contents;
  if (beforeCount !== 1 || afterCount !== 0) {
    throw new Error(
      'Unexpected RNFS download source; re-audit listener cleanup before changing it.',
    );
  }
  return contents.replace(before, after);
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
  const sourcePath = path.join(packageRoot, 'FS.common.js');
  const original = fs.readFileSync(sourcePath, 'utf8');
  const normalized = original.replace(/\r\n/g, '\n');
  const fixed = fixedSource(normalized);
  if (fixed !== normalized) {
    if (check)
      throw new Error('RNFS download listener cleanup has not been applied.');
    fs.writeFileSync(
      sourcePath,
      original.includes('\r\n') ? fixed.replace(/\n/g, '\r\n') : fixed,
      'utf8',
    );
  }
  return {version};
}

if (require.main === module) {
  const {version} = applyFix({check: process.argv.includes('--check')});
  console.log(`RNFS download listener cleanup verified (v${version}).`);
}

module.exports = {SUPPORTED_VERSION, applyFix, fixedSource};
