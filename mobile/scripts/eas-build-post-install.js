'use strict';

const path = require('node:path');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '..');

// EAS runs this hook after pod install on iOS, but before the signed archive:
// https://docs.expo.dev/build-reference/npm-hooks/
// Keep the exact Ruby/Bundler/Xcode checks in verify:ios-lock intact. Pinning
// an image is not evidence that its default Ruby satisfies our Gemfile.lock.
const runPostInstall = ({
  buildPlatform = process.env.EAS_BUILD_PLATFORM,
  hostPlatform = process.platform,
  npmCli = process.env.npm_execpath,
  run = (command, args) => execFileSync(command, args, {
    cwd: root,
    stdio: 'inherit',
    env: process.env,
  }),
} = {}) => {
  if (buildPlatform && !['android', 'ios'].includes(buildPlatform)) {
    throw new Error(`Unsupported EAS_BUILD_PLATFORM: ${buildPlatform}`);
  }
  if (!npmCli) {
    throw new Error('Invoke this lifecycle hook through npm run eas-build-post-install.');
  }
  const npmRun = script => run(process.execPath, [npmCli, 'run', script]);
  if (buildPlatform === 'ios') {
    if (hostPlatform !== 'darwin') {
      throw new Error('An iOS EAS build requires macOS to verify the native toolchain.');
    }
    npmRun('verify:ios-lock');
    // A successful pod install must not silently rewrite the committed lock.
    run('git', ['diff', '--exit-code', '--', 'ios/Podfile.lock']);
  }
  npmRun('verify:release');
};

if (require.main === module) {
  try {
    runPostInstall();
  } catch (error) {
    console.error(error.message);
    process.exitCode = Number.isInteger(error.status) && error.status > 0
      ? error.status
      : 1;
  }
}

module.exports = {runPostInstall};
