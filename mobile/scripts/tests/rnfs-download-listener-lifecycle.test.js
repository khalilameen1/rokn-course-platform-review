'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const {createRequire} = require('node:module');
const test = require('node:test');
const vm = require('node:vm');
const {transformSync} = require('@babel/core');
const path = require('node:path');
const {
  applyFix,
  fixedSource,
  SUPPORTED_VERSION,
} = require('../apply-rnfs-download-cleanup-fix');

const sourcePath = require.resolve('react-native-fs');
const packageRequire = createRequire(sourcePath);
// Execute the installed library, stripping only its Flow annotations. The
// native bridge and event emitter are seams; RNFS subscription logic is real.
const compiled = transformSync(fs.readFileSync(sourcePath, 'utf8'), {
  filename: sourcePath,
  babelrc: false,
  configFile: false,
  plugins: [
    require.resolve('@babel/plugin-transform-flow-strip-types', {
      paths: [require.resolve('@react-native/babel-preset')],
    }),
  ],
}).code;

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
};

function installedDownloads() {
  const listeners = new Set();
  const transfers = [];
  const native = {
    downloadFile(options) {
      const transfer = {...deferred(), options};
      transfers.push(transfer);
      return transfer.promise;
    },
  };
  class NativeEventEmitter {
    addListener(event, callback) {
      const subscription = {
        event,
        callback,
        remove: () => listeners.delete(subscription),
      };
      listeners.add(subscription);
      return subscription;
    }
  }
  const exported = {exports: {}};
  vm.runInNewContext(
    compiled,
    {
      module: exported,
      exports: exported.exports,
      require: name =>
        name === 'react-native'
          ? {
              NativeModules: {RNFSManager: native},
              NativeEventEmitter,
              Platform: {OS: 'ios'},
            }
          : packageRequire(name),
    },
    {filename: sourcePath},
  );
  return {
    rnfs: exported.exports,
    transfers,
    listeners,
    emit(event, value) {
      for (const listener of listeners)
        if (listener.event === event) listener.callback(value);
    },
  };
}

const request = {
  fromUrl: 'https://files.example.test/notes.pdf',
  toFile: '/cache/notes.pdf',
  background: true,
  begin: () => {},
  resumable: () => {},
};

test('the version-checked cleanup remains installed and reapplication is idempotent', () => {
  const root = path.resolve(__dirname, '..', '..');
  const manifest = JSON.parse(
    fs.readFileSync(path.join(root, 'package.json'), 'utf8'),
  );
  assert.ok(
    manifest.scripts.postinstall
      .split(' && ')
      .includes('node scripts/apply-rnfs-download-cleanup-fix.js'),
  );
  assert.deepEqual(applyFix({root, check: true}), {version: SUPPORTED_VERSION});
  const source = fs.readFileSync(sourcePath, 'utf8').replace(/\r\n/g, '\n');
  assert.equal(fixedSource(source), source);
  assert.throws(
    () => fixedSource('unexpected upstream implementation'),
    /Unexpected RNFS/,
  );
});

test('a rejected native download releases its event subscriptions and preserves its error', async () => {
  const {rnfs, transfers, listeners} = installedDownloads();
  const task = rnfs.downloadFile(request);
  assert.equal(listeners.size, 2);
  const failure = new Error('Connection lost');
  transfers[0].reject(failure);
  await assert.rejects(task.promise, error => error === failure);
  assert.equal(listeners.size, 0);
});

test('retiring a failed file keeps the other active file callbacks alive', async () => {
  const {rnfs, transfers, listeners, emit} = installedDownloads();
  const seen = [];
  const first = rnfs.downloadFile({
    ...request,
    begin: () => seen.push('retired'),
  });
  const second = rnfs.downloadFile({
    ...request,
    begin: () => seen.push('current'),
  });
  transfers[0].reject(new Error('Download failed'));
  await assert.rejects(first.promise);
  emit('DownloadBegin', {jobId: first.jobId});
  emit('DownloadBegin', {jobId: second.jobId});
  assert.deepEqual(seen, ['current']);
  assert.equal(listeners.size, 2);
  const receipt = {jobId: second.jobId, statusCode: 200, bytesWritten: 10};
  transfers[1].resolve(receipt);
  assert.equal(await second.promise, receipt);
  assert.equal(listeners.size, 0);
});

test('successful and callback-free downloads retain their existing terminal contract', async () => {
  const {rnfs, transfers, listeners} = installedDownloads();
  const first = rnfs.downloadFile(request);
  const receipt = {jobId: first.jobId, statusCode: 200, bytesWritten: 10};
  transfers[0].resolve(receipt);
  assert.equal(await first.promise, receipt);
  assert.equal(listeners.size, 0);
  const second = rnfs.downloadFile({
    fromUrl: request.fromUrl,
    toFile: request.toFile,
  });
  transfers[1].reject(new Error('No connection'));
  await assert.rejects(second.promise);
  assert.equal(listeners.size, 0);
});
