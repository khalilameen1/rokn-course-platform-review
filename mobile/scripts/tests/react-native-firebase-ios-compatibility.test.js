'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const ROOT = path.resolve(__dirname, '..', '..');
const manifest = JSON.parse(
  fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'),
);
const {
  SUPPORTED_VERSION,
  UPSTREAM_FIX,
  applyFixes,
  fixes,
} = require('../apply-rnfirebase-ios-constants-fix');

test('the audited React Native Firebase iOS fix is deterministic and installed', () => {
  assert.ok(
    manifest.scripts?.postinstall
      .split(' && ')
      .includes('node scripts/apply-rnfirebase-ios-constants-fix.js'),
  );
  assert.equal(
    manifest.dependencies?.['@react-native-firebase/app'],
    SUPPORTED_VERSION,
  );
  assert.equal(
    manifest.dependencies?.['@react-native-firebase/messaging'],
    SUPPORTED_VERSION,
  );
  assert.equal(
    UPSTREAM_FIX,
    'https://github.com/invertase/react-native-firebase/pull/9225',
  );
  assert.deepEqual(applyFixes({root: ROOT, check: true}), {
    files: fixes.length,
    version: SUPPORTED_VERSION,
    upstream: UPSTREAM_FIX,
  });
});
