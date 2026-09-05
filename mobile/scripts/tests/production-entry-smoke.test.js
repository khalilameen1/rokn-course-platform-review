'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {isCourseDuration} = require('../production-entry-contract');
const {
  isAndroidEmulator,
  productionEntryKind,
  shouldResetAppData,
} = require('../run-android-production-entry-smoke');

test('accepts every Arabic course-duration shape rendered by the app', () => {
  for (const value of ['دقيقة', 'دقيقتان', '٨ دقائق', '٦٢ دقيقة']) {
    assert.equal(isCourseDuration(value), true, value);
  }
  for (const value of ['', 'دقائق', 'لا توجد مدة', '٦٢ طالبًا']) {
    assert.equal(isCourseDuration(value), false, value);
  }
});

test('never authorizes app-data deletion without an explicit disposable emulator', () => {
  assert.equal(shouldResetAppData({}), false);
  assert.equal(
    shouldResetAppData({ROKN_SMOKE_DISPOSABLE_EMULATOR: 'true'}),
    false,
  );
  assert.equal(
    shouldResetAppData({ROKN_SMOKE_DISPOSABLE_EMULATOR: '1'}),
    true,
  );
  assert.equal(isAndroidEmulator('0'), false);
  assert.equal(isAndroidEmulator('1\n'), true);
});

test('accepts direct guest Home while retaining the old prompt as a fallback', () => {
  assert.equal(
    productionEntryKind([
      {text: '', 'content-desc': 'الرئيسية', clickable: 'true'},
    ]),
    'home',
  );
  assert.equal(
    productionEntryKind([
      {
        text: '',
        'content-desc': 'المتابعة كزائر',
        clickable: 'true',
        enabled: 'true',
      },
    ]),
    'guest-prompt',
  );
  assert.equal(productionEntryKind([{text: 'شاشة غير متوقعة'}]), null);
});
