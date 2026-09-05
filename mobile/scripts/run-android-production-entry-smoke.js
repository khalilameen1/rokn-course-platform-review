'use strict';

/*
 * Exercises the public production entry journeys from the installed release
 * artifact. It intentionally needs no learner credentials: cold start, guest
 * catalogue, course details and the configured Google/TikTok browser hand-off
 * must work before a build is allowed to reach manual acceptance. App data is
 * cleared only when ROKN_SMOKE_DISPOSABLE_EMULATOR=1 and Android confirms QEMU.
 */
const {existsSync} = require('fs');
const {execFileSync, spawnSync} = require('child_process');
const {isCourseDuration} = require('./production-entry-contract');

const appId = process.env.ROKN_SMOKE_APP_ID || 'com.rokn';
const serial = process.env.ANDROID_SERIAL || 'emulator-5554';
const timeoutMs = Number(process.env.ROKN_SMOKE_TIMEOUT_MS || 25_000);
const apk = process.env.ROKN_SMOKE_APK;
const remoteDump = '/sdcard/rokn-production-entry.xml';

const fail = message => {
  const error = new Error(message);
  error.code = 1;
  throw error;
};

const findAdb = () => {
  const configured = String(process.env.ROKN_ADB || '').trim();
  if (configured) {
    if (!existsSync(configured)) fail(`ROKN_ADB does not exist: ${configured}`);
    return configured;
  }
  const resolver = process.platform === 'win32' ? 'where' : 'which';
  const result = spawnSync(resolver, ['adb'], {encoding: 'utf8'});
  const candidate = String(result.stdout || '').split(/\r?\n/).find(Boolean);
  if (!candidate) fail('adb was not found. Set ROKN_ADB to platform-tools/adb.');
  return candidate.trim();
};

let adbBinary;
const adb = (args, options = {}) =>
  execFileSync((adbBinary ??= findAdb()), ['-s', serial, ...args], {
    encoding: 'utf8',
    stdio: options.silent ? ['ignore', 'pipe', 'pipe'] : ['ignore', 'pipe', 'pipe'],
    timeout: options.timeout || 30_000,
  });

const delay = milliseconds =>
  new Promise(resolve => setTimeout(resolve, milliseconds));

const decodeXml = value =>
  value
    .replaceAll('&quot;', '"')
    .replaceAll('&apos;', "'")
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&amp;', '&')
    .replaceAll('&#10;', '\n');

const nodesFromXml = xml =>
  [...xml.matchAll(/<node\s+([^>]+?)(?:\s*\/?>)/g)].map(match => {
    const attributes = {};
    for (const attribute of match[1].matchAll(/([\w-]+)="([^"]*)"/g)) {
      attributes[attribute[1]] = decodeXml(attribute[2]);
    }
    return attributes;
  });

const dumpUi = () => {
  adb(['shell', 'uiautomator', 'dump', remoteDump], {silent: true});
  return adb(['exec-out', 'cat', remoteDump], {silent: true});
};

const waitForUi = async predicate => {
  const deadline = Date.now() + timeoutMs;
  let lastXml = '';
  while (Date.now() < deadline) {
    try {
      lastXml = dumpUi();
      const nodes = nodesFromXml(lastXml);
      const match = nodes.find(predicate);
      if (match) return {match, nodes, xml: lastXml};
    } catch {
      // React Native can be between frames while the hierarchy is requested.
    }
    await delay(700);
  }
  fail(`Timed out waiting for the expected production UI. Last UI: ${lastXml.slice(0, 800)}`);
};

const textOrDescription = (node, value) =>
  node.text === value || node['content-desc'] === value;

const isActionableLabel = (node, value) =>
  textOrDescription(node, value) &&
  node.enabled !== 'false' &&
  node.clickable !== 'false';

const shouldResetAppData = environment =>
  environment.ROKN_SMOKE_DISPOSABLE_EMULATOR === '1';

const isAndroidEmulator = qemuProperty =>
  String(qemuProperty || '').trim() === '1';

const productionEntryKind = nodes => {
  if (nodes.some(node => isActionableLabel(node, 'المتابعة كزائر'))) {
    return 'guest-prompt';
  }
  return nodes.some(node => textOrDescription(node, 'الرئيسية'))
    ? 'home'
    : null;
};

const boundsCenter = rawBounds => {
  const match = String(rawBounds || '').match(
    /^\[(\d+),(\d+)\]\[(\d+),(\d+)\]$/,
  );
  if (!match) fail(`Node has invalid bounds: ${rawBounds}`);
  const [, left, top, right, bottom] = match.map(Number);
  return [Math.round((left + right) / 2), Math.round((top + bottom) / 2)];
};

const tapNode = node => {
  const [x, y] = boundsCenter(node.bounds);
  adb(['shell', 'input', 'tap', String(x), String(y)]);
};

const tapLabel = async label => {
  const {match} = await waitForUi(
    node => isActionableLabel(node, label),
  );
  tapNode(match);
};

const tapMatchingLabel = async pattern => {
  const {match} = await waitForUi(
    node =>
      node.enabled !== 'false' &&
      node.clickable !== 'false' &&
      pattern.test(`${node.text || ''} ${node['content-desc'] || ''}`.trim()),
  );
  tapNode(match);
};

const waitForUnobscuredHomeAction = async predicate => {
  for (let attempt = 0; attempt < 3; attempt += 1) {
    const state = await waitForUi(
      node =>
        predicate(node) || isActionableLabel(node, 'المتابعة كزائر'),
    );
    const guestAction = state.nodes.find(node =>
      isActionableLabel(node, 'المتابعة كزائر'),
    );
    if (guestAction) {
      tapNode(guestAction);
      await delay(250);
      continue;
    }
    const action = state.nodes.find(predicate);
    if (action) return action;
  }
  fail('A guest prompt kept the requested Home action blocked.');
};

const tapUnobscuredHomeLabel = async label => {
  const action = await waitForUnobscuredHomeAction(node =>
    isActionableLabel(node, label),
  );
  tapNode(action);
};

const assertNoPublicBlocker = nodes => {
  const blocker = nodes.find(node =>
    /تعذّر تحميل|انتهت محاولة الدخول|حدث خطأ غير متوقع/.test(
      `${node.text || ''} ${node['content-desc'] || ''}`,
    ),
  );
  if (blocker) fail(`Production UI displayed a blocker: ${blocker.text || blocker['content-desc']}`);
};

const resetDisposableEmulator = () => {
  if (!shouldResetAppData(process.env)) return;
  const qemuProperty = adb(['shell', 'getprop', 'ro.kernel.qemu'], {
    silent: true,
  });
  if (!isAndroidEmulator(qemuProperty)) {
    fail(
      'ROKN_SMOKE_DISPOSABLE_EMULATOR=1 may only clear an Android emulator.',
    );
  }
  const result = adb(['shell', 'pm', 'clear', appId], {silent: true}).trim();
  if (result !== 'Success') fail(`Could not clear ${appId} on the disposable emulator.`);
};

const launchToHome = async () => {
  adb(['shell', 'am', 'force-stop', appId], {silent: true});
  adb([
    'shell',
    'monkey',
    '-p',
    appId,
    '-c',
    'android.intent.category.LAUNCHER',
    '1',
  ]);
  const entry = await waitForUi(
    node =>
      textOrDescription(node, 'الرئيسية') ||
      isActionableLabel(node, 'المتابعة كزائر'),
  );
  assertNoPublicBlocker(entry.nodes);
  if (productionEntryKind(entry.nodes) === 'guest-prompt') {
    const guestAction = entry.nodes.find(node =>
      isActionableLabel(node, 'المتابعة كزائر'),
    );
    tapNode(guestAction);
    await delay(250);
  }
  const home = await waitForUi(node => textOrDescription(node, 'الرئيسية'));
  assertNoPublicBlocker(home.nodes);
  return home;
};

const verifyGuestJourney = async () => {
  await launchToHome();
  await tapUnobscuredHomeLabel('أنا');
  const guestProfile = await waitForUi(node =>
    textOrDescription(node, 'تصفّح كضيف'),
  );
  assertNoPublicBlocker(guestProfile.nodes);
  await tapUnobscuredHomeLabel('الرئيسية');

  const courseAction = await waitForUnobscuredHomeAction(
    node =>
      node.class === 'android.widget.Button' &&
      /^عرض\s+\S/.test(node['content-desc'] || ''),
  );
  if (!courseAction) fail('Production home has no openable published course.');
  const courseTitle = courseAction['content-desc'].replace(/^عرض\s+/, '').trim();
  tapNode(courseAction);

  const details = await waitForUi(
    node =>
      node.text === courseTitle &&
      node.class === 'android.widget.TextView',
  );
  assertNoPublicBlocker(details.nodes);
  if (!details.nodes.some(node => isCourseDuration(node.text))) {
    fail('Guest course details did not expose the course duration.');
  }
  if (!details.nodes.some(node => node['content-desc'] === 'خريطة الكورس')) {
    fail('Guest course details did not expose the course map.');
  }
  process.stdout.write(`PASS guest > home > course details (${courseTitle})\n`);
};

const topActivity = () => adb(['shell', 'dumpsys', 'activity', 'activities']);

const verifyProviderHandoff = async ({label, expectedDomain, finalProvider = false}) => {
  // This proves the app opens the expected provider origin and returns safely
  // when the browser is dismissed. It intentionally never submits credentials
  // and therefore does not claim to prove callback exchange or session commit.
  await tapLabel(label);
  const deadline = Date.now() + timeoutMs;
  let xml = '';
  while (Date.now() < deadline) {
    const activities = topActivity();
    if (/topResumedActivity=.*com\.android\.chrome/.test(activities)) {
      xml = dumpUi();
      if (xml.includes(expectedDomain)) break;
    }
    await delay(700);
  }
  if (!xml.includes(expectedDomain)) {
    fail(`${label} did not reach ${expectedDomain} in the Android browser.`);
  }
  process.stdout.write(`PASS ${label} > ${expectedDomain}\n`);
  adb(['shell', 'input', 'keyevent', '4']);
  const returned = await waitForUi(node =>
    (textOrDescription(node, label) &&
      node.enabled !== 'false' &&
      node.clickable !== 'false') ||
    (finalProvider && node['content-desc'] === 'الرئيسية'),
  );
  assertNoPublicBlocker(returned.nodes);
};

const verifyAuthEntry = async () => {
  await launchToHome();
  await tapUnobscuredHomeLabel('أنا');
  await tapMatchingLabel(/^تسجيل الدخول(?:\s|$)/);
  const auth = await waitForUi(node => textOrDescription(node, 'المتابعة بحساب Google'));
  assertNoPublicBlocker(auth.nodes);
  if (!auth.nodes.some(node => textOrDescription(node, 'المتابعة بحساب TikTok'))) {
    fail('TikTok is advertised by production but missing from the login screen.');
  }
  await verifyProviderHandoff({
    label: 'المتابعة بحساب Google',
    expectedDomain: 'accounts.google.com',
  });
  await verifyProviderHandoff({
    label: 'المتابعة بحساب TikTok',
    expectedDomain: 'tiktok.com',
    finalProvider: true,
  });
};

const main = async () => {
  adb(['get-state']);
  if (apk) {
    if (!existsSync(apk)) fail(`ROKN_SMOKE_APK does not exist: ${apk}`);
    adb(['install', '-r', apk], {timeout: 120_000});
  }
  if (!adb(['shell', 'pm', 'path', appId]).includes('package:')) {
    fail(`${appId} is not installed. Set ROKN_SMOKE_APK or install it first.`);
  }
  resetDisposableEmulator();
  await verifyGuestJourney();
  await verifyAuthEntry();
  process.stdout.write('Production Android entry smoke passed.\n');
};

if (require.main === module) {
  main().catch(error => {
    console.error(error.message);
    console.error('Do not hand off this APK as production-entry tested.');
    process.exitCode = Number.isInteger(error.code) ? error.code : 1;
  });
}

module.exports = {
  boundsCenter,
  decodeXml,
  isAndroidEmulator,
  nodesFromXml,
  productionEntryKind,
  shouldResetAppData,
};
