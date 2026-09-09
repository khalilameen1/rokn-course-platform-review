'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {transformNativeSources} = require('../rnfs-ios-download-lifecycle-fix');

const nativeRoot = path.dirname(require.resolve('react-native-fs'));
const names = [
  'Downloader.h',
  'Downloader.m',
  'RNFSManager.h',
  'RNFSManager.m',
];
const installed = Object.fromEntries(
  names.map(name => [
    name,
    fs.readFileSync(path.join(nativeRoot, name), 'utf8').replace(/\r\n/g, '\n'),
  ]),
);
const fixed = transformNativeSources(installed);

// These are source-transform contracts, not a simulator or NSURLSession test.
test('the exact native source transform is pure, idempotent and refuses drift', () => {
  assert.deepEqual(transformNativeSources(fixed), fixed);
  for (const name of names) {
    assert.equal(
      installed[name],
      fs
        .readFileSync(path.join(nativeRoot, name), 'utf8')
        .replace(/\r\n/g, '\n'),
    );
    assert.throws(
      () =>
        transformNativeSources({
          ...installed,
          [name]: installed[name] + '\n// changed\n',
        }),
      /Unexpected RNFS iOS source/,
    );
  }
  assert.throws(() => transformNativeSources({}), /Missing RNFS iOS source/);
});

test('terminal abandonment and the destination move share a native lock and old tasks cannot complete', () => {
  const source = fixed['Downloader.m'];
  const move = source.slice(
    source.indexOf('didFinishDownloadingToURL:'),
    source.indexOf('didCompleteWithError:'),
  );
  assert.match(
    move,
    /@synchronized \(self\) \{\s*if \(_terminal \|\| downloadTask != _task\) return;/,
  );
  assert.ok(move.indexOf('if (_terminal') < move.indexOf('moveItemAtURL:'));
  assert.match(move, /\[self finishWithError:error\]/);
  const finish = source.slice(source.lastIndexOf('- (void)finishWithError:'));
  assert.match(
    finish,
    /@synchronized \(self\) \{\s*if \(_terminal\) return;\s*_terminal = YES;/,
  );
  assert.ok(
    finish.indexOf('_terminal = YES') <
      finish.indexOf('[session invalidateAndCancel]'),
  );
  for (const field of [
    '_params',
    '_task',
    '_session',
    '_resumeData',
    '_fileHandle',
  ]) {
    assert.ok(finish.includes(`${field} = nil;`));
  }
  assert.match(finish, /params\.errorCallback\(error\)/);
  assert.match(
    finish,
    /params\.completeCallback\(_statusCode, _bytesWritten\)/,
  );
});

test('resumable stop still keeps the same job live while stale stop callbacks cannot revive an abandoned job', () => {
  const source = fixed['Downloader.m'];
  const stop = source.slice(
    source.indexOf('- (void)stopDownload'),
    source.indexOf('- (void)resumeDownload'),
  );
  assert.match(stop, /cancelByProducingResumeData/);
  assert.match(
    stop,
    /if \(self->_terminal \|\| self->_task != stoppedTask\) return;/,
  );
  assert.match(stop, /self->_resumeData = resumeData;/);
  assert.match(stop, /self->_params\.resumableCallback\(\)/);
  assert.doesNotMatch(stop, /\[self cancelDownload\]/);
  const resume = source.slice(
    source.indexOf('- (void)resumeDownload'),
    source.indexOf('- (BOOL)isResumable'),
  );
  assert.match(resume, /if \(_terminal\) return;/);
  assert.match(resume, /downloadTaskWithResumeData:_resumeData/);
});

test('the manager registers before starting and retires only its exact terminal downloader', () => {
  const source = fixed['RNFSManager.m'];
  assert.ok(
    source.indexOf('[self.downloaders setObject:downloader forKey:jobKey]') <
      source.indexOf('[downloader downloadFile:params]'),
  );
  assert.equal(
    source.split(
      'if ([self.downloaders objectForKey:jobKey] == ownedDownloader)',
    ).length - 1,
    2,
  );
  assert.equal(
    source.split('[self.downloaders removeObjectForKey:jobKey]').length - 1,
    2,
  );
  const cancel = source.slice(
    source.indexOf('RCT_EXPORT_METHOD(cancelDownload:'),
    source.indexOf('RCT_EXPORT_METHOD(resumeDownload:'),
  );
  assert.match(
    cancel,
    /@synchronized \(self\) \{\s*downloader = \[self\.downloaders objectForKey:\[jobId stringValue\]\];\s*\}\s*\[downloader cancelDownload\];/,
  );
});

test('background completion belongs to drained native events, never an early JS acknowledgement', () => {
  const source = fixed['RNFSManager.m'];
  const handler = source.slice(
    source.indexOf('RCT_EXPORT_METHOD(completeHandlerIOS:'),
    source.indexOf('RCT_EXPORT_METHOD(uploadFiles:'),
  );
  assert.match(handler, /resolve\(nil\)/);
  assert.doesNotMatch(
    handler,
    /dispatch_async|completionHandler\(|finishBackgroundEvents/,
  );
  assert.doesNotMatch(source, /self\.uuids|completionHandlers/);
  assert.match(
    source,
    /\[RNFSDownloader handleBackgroundEventsForIdentifier:identifier/,
  );
  assert.match(fixed['RNFSManager.h'], /setCompletionHandlerForIdentifier:/);
  assert.match(
    fixed['RNFSManager.h'],
    /NS_SWIFT_NAME\(handleBackgroundEvents\(identifier:completionHandler:\)\)/,
  );
});

test('a live weak identifier owner is registered before resume and released on session invalidation', () => {
  const source = fixed['Downloader.m'];
  assert.match(source, /strongToWeakObjectsMapTable/);
  assert.ok(
    source.indexOf('[backgroundDownloaders setObject:self forKey:uuid]') <
      source.indexOf('[_task resume]'),
  );
  assert.match(source, /URLSessionDidFinishEventsForBackgroundURLSession:/);
  const invalidation = source.slice(
    source.indexOf(
      '- (void)URLSession:(NSURLSession *)session didBecomeInvalidWithError:',
    ),
  );
  assert.match(invalidation, /\[self finishBackgroundEvents\]/);
  assert.match(
    invalidation,
    /if \(\[backgroundDownloaders objectForKey:identifier\] == self\)/,
  );
  assert.match(
    invalidation,
    /\[backgroundDownloaders removeObjectForKey:identifier\]/,
  );
  assert.match(invalidation, /_backgroundIdentifier = nil/);
  const route = source.slice(
    source.indexOf('+ (BOOL)handleBackgroundEventsForIdentifier:'),
    source.lastIndexOf('- (BOOL)registerBackgroundCompletionForIdentifier:'),
  );
  assert.match(
    route,
    /@synchronized \(\[RNFSDownloader class\]\) \{\s*downloader = \[backgroundDownloaders objectForKey:identifier\];\s*\}\s*return \[downloader/,
  );
});

test('native handoff consumes each event batch without a task-lifetime delivered flag', () => {
  const source = fixed['Downloader.m'];
  const registration = source.slice(
    source.lastIndexOf('- (BOOL)registerBackgroundCompletionForIdentifier:'),
    source.lastIndexOf('- (void)finishBackgroundEvents'),
  );
  assert.match(
    registration,
    /if \(!\[_backgroundIdentifier isEqualToString:identifier\]\) return NO/,
  );
  assert.match(
    registration,
    /if \(\[_backgroundSeenHandlers containsObject:ownedHandler\]\) return YES/,
  );
  assert.match(
    registration,
    /\[_backgroundCompletionHandlers addObject:ownedHandler\]/,
  );
  assert.match(registration, /\[self drainBackgroundCompletionHandlers\]/);
  const finish = source.slice(
    source.lastIndexOf('- (void)finishBackgroundEvents'),
    source.indexOf('- (void)URLSessionDidFinishEventsForBackgroundURLSession:'),
  );
  assert.match(finish, /_backgroundEventsFinished = YES/);
  assert.match(
    finish,
    /if \(!_backgroundEventsFinished \|\| !_backgroundCompletionHandlers.count\) return/,
  );
  assert.ok(
    finish.indexOf('_backgroundEventsFinished = NO') <
      finish.indexOf('dispatch_async(dispatch_get_main_queue()'),
  );
  assert.match(finish, /_backgroundCompletionHandlers = nil/);
  assert.match(
    finish,
    /for \(void \(\^completionHandler\)\(void\) in completionHandlers\) completionHandler\(\)/,
  );
  assert.match(
    source,
    /NSPointerFunctionsWeakMemory \| NSPointerFunctionsObjectPointerPersonality/,
  );
  assert.doesNotMatch(source, /backgroundHandlerDelivered/);
  const resume = source.slice(
    source.indexOf('- (void)resumeDownload'),
    source.indexOf('- (BOOL)isResumable'),
  );
  assert.doesNotMatch(resume, /_background/);
});

// JavaScript state simulation of the source contract above, not execution of
// Objective-C, UIKit, NSURLSession, or an iOS simulator.
function simulateBackgroundHandoff() {
  let ready = false;
  let live = true;
  let pending = [];
  const seen = new WeakSet();
  const mainQueue = [];
  const drain = () => {
    if (!ready || !pending.length) return;
    ready = false;
    const handlers = pending;
    pending = [];
    mainQueue.push(() => handlers.forEach(handler => handler()));
  };
  return {
    register(handler) {
      if (!live) return false;
      if (seen.has(handler)) return true;
      seen.add(handler);
      pending.push(handler);
      drain();
      return true;
    },
    events() {
      if (!live) return;
      ready = true;
      drain();
    },
    resume() {}, // A task restart does not own an earlier UIKit event batch.
    invalidate() {
      if (!live) return;
      ready = true;
      drain();
      live = false;
    },
    flushMain() {
      while (mainQueue.length) mainQueue.shift()();
    },
  };
}

test('JS handoff simulation completes two distinct batches without resume in either handler/event order', () => {
  for (const eventFirst of [false, true]) {
    const owner = simulateBackgroundHandoff();
    const calls = [];
    for (const batch of [1, 2]) {
      const handler = () => calls.push(batch);
      if (eventFirst) owner.events();
      owner.register(handler);
      if (!eventFirst) {
        owner.flushMain();
        assert.deepEqual(calls, batch === 1 ? [] : [1]);
        owner.events();
      }
      assert.equal(
        calls.length,
        batch - 1,
        'UIKit completion waits for main dispatch',
      );
      owner.flushMain();
      assert.equal(calls.length, batch);
    }
    assert.deepEqual(calls, [1, 2]);
  }
});

test('JS handoff simulation preserves distinct pending callbacks, suppresses duplicates without spending the next event, and survives resume', () => {
  const owner = simulateBackgroundHandoff();
  const calls = [];
  const first = () => calls.push(1);
  const second = () => calls.push(2);
  const third = () => calls.push(3);
  owner.register(first);
  owner.register(first);
  owner.register(second);
  owner.resume();
  owner.flushMain();
  assert.deepEqual(calls, []);
  owner.events();
  owner.flushMain();
  assert.deepEqual(calls, [1, 2]);
  owner.events();
  owner.register(first);
  owner.resume();
  owner.register(third);
  owner.flushMain();
  assert.deepEqual(calls, [1, 2, 3]);
  owner.register(third);
  owner.flushMain();
  assert.deepEqual(calls, [1, 2, 3]);
});

test('JS handoff simulation drains a pending handler on terminal invalidation and does not own later callbacks', () => {
  const owner = simulateBackgroundHandoff();
  let calls = 0;
  const handler = () => calls++;
  owner.register(handler);
  owner.invalidate();
  owner.invalidate();
  owner.events();
  assert.equal(owner.register(handler), false);
  assert.equal(calls, 0);
  owner.flushMain();
  assert.equal(calls, 1);
});

test('the Swift app delegates only recognized live RNFS sessions and leaves other Expo subscribers intact', () => {
  const app = fs.readFileSync(
    path.resolve(__dirname, '../../ios/Rokn/AppDelegate.swift'),
    'utf8',
  );
  assert.match(app, /import RNFS/);
  assert.equal(
    app.split('handleEventsForBackgroundURLSession identifier: String').length -
      1,
    1,
  );
  assert.match(
    app,
    /if RNFSManager\.handleBackgroundEvents\([\s\S]*completionHandler: completionHandler\s*\) \{\s*return\s*\}\s*super\.application\(/,
  );
  assert.match(
    fs.readFileSync(path.join(nativeRoot, 'RNFS.podspec'), 'utf8'),
    /s\.name\s*=\s*"RNFS"/,
  );
  assert.match(
    fs.readFileSync(path.resolve(__dirname, '../../ios/Podfile'), 'utf8'),
    /use_frameworks! :linkage => linkage.to_sym/,
  );
});
