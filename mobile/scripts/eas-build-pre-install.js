'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '..');
const versions = {ruby: '3.3.6', bundler: '4.0.20', cocoapods: '1.16.2', fastlane: '2.231.1'};
// Official Ruby release checksum; ruby-build verifies it before compiling.
// https://www.ruby-lang.org/en/news/2024/11/05/ruby-3-3-6-released/
const rubySource = 'https://cache.ruby-lang.org/pub/ruby/3.3/ruby-3.3.6.tar.gz' +
  '#8dc48fffaf270f86f1019053f28e51e4da4cce32a36760a0603a9aee67d7fd8d';

// Expo supports Homebrew installation in pre-install, and set-env persists
// variables to later phases (changing this process alone is insufficient).
// https://docs.expo.dev/build-reference/npm-hooks/
// https://docs.expo.dev/eas/environment-variables/usage/
// ruby-build supports standalone exact-version installs without rbenv:
// https://github.com/rbenv/ruby-build#basic-usage
const runPreInstall = ({
  buildPlatform = process.env.EAS_BUILD_PLATFORM,
  hostPlatform = process.platform,
  env = process.env,
  makeTemporaryDirectory = () => fs.mkdtempSync(path.join(os.tmpdir(), 'rokn-eas-ruby-')),
  run = (command, args, options) => execFileSync(command, args, options),
} = {}) => {
  if (buildPlatform && !['android', 'ios'].includes(buildPlatform)) {
    throw new Error(`Unsupported EAS_BUILD_PLATFORM: ${buildPlatform}`);
  }
  if (buildPlatform !== 'ios') return;
  if (hostPlatform !== 'darwin') {
    throw new Error('An iOS EAS build requires macOS to install the native toolchain.');
  }

  const directory = makeTemporaryDirectory();
  const rubyDirectory = path.join(directory, 'ruby');
  const gemDirectory = path.join(directory, 'gems');
  const binDirectory = path.join(directory, 'locked-bin');
  const runtime = {
    PATH: [binDirectory, path.join(gemDirectory, 'bin'), path.join(rubyDirectory, 'bin'), env.PATH]
      .filter(Boolean).join(':'),
    GEM_HOME: gemDirectory,
    GEM_PATH: gemDirectory,
    BUNDLE_PATH: path.join(directory, 'bundle'),
    BUNDLE_GEMFILE: path.join(root, 'Gemfile'),
    BUNDLE_FROZEN: 'true',
    POD_INSTALL_DEPLOYMENT: '1',
  };
  const execute = (command, args, currentEnv, capture = false) => run(command, args, {
    cwd: root,
    env: currentEnv,
    stdio: capture ? ['ignore', 'pipe', 'inherit'] : 'inherit',
    encoding: 'utf8',
  });
  execute('brew', ['install', 'ruby-build'], {...env, HOMEBREW_NO_AUTO_UPDATE: '1'});
  execute('ruby-build', [versions.ruby, rubyDirectory], {
    ...env, RUBY_BUILD_TARBALL_OVERRIDE: rubySource,
  });
  const runtimeEnv = {...env, ...runtime};
  const ruby = path.join(rubyDirectory, 'bin', 'ruby');
  const actualRuby = String(execute(ruby, ['-e', 'print RUBY_VERSION'], runtimeEnv, true)).trim();
  if (actualRuby !== versions.ruby) {
    throw new Error(`Installed Ruby ${actualRuby}; expected ${versions.ruby}.`);
  }
  const gem = path.join(rubyDirectory, 'bin', 'gem');
  execute(gem, ['install', 'bundler', '--version', versions.bundler, '--no-document'], runtimeEnv);
  const bundle = path.join(gemDirectory, 'bin', 'bundle');
  execute(bundle, [`_${versions.bundler}_`, 'install'], runtimeEnv);
  // EAS invokes plain `pod install`. A generated Bundler binstub makes that
  // command use Gemfile.lock, not unrelated gems from the worker image.
  execute(bundle, [`_${versions.bundler}_`, 'binstubs', 'cocoapods', '--path', binDirectory], runtimeEnv);
  // EAS also invokes plain `fastlane gym`; install it for the new Ruby rather
  // than leaving the image's Ruby 3.2 executable with incompatible gem paths.
  execute(gem, ['install', 'fastlane', '--version', versions.fastlane, '--no-document'], runtimeEnv);
  const podVersion = String(execute(path.join(binDirectory, 'pod'), ['--version'], runtimeEnv, true)).trim();
  if (podVersion !== versions.cocoapods) {
    throw new Error(`Installed CocoaPods ${podVersion}; expected ${versions.cocoapods}.`);
  }
  execute(path.join(gemDirectory, 'bin', 'fastlane'), ['--version'], {
    ...runtimeEnv, FASTLANE_SKIP_UPDATE_CHECK: '1',
  });
  execute('git', ['diff', '--exit-code', '--', 'Gemfile.lock'], runtimeEnv);
  // Publish only after setup and checks succeed; failures stop the build.
  for (const [key, value] of Object.entries(runtime)) {
    execute('set-env', [key, value], runtimeEnv);
  }
};

if (require.main === module) {
  try {
    runPreInstall();
  } catch (error) {
    console.error(error.message);
    process.exitCode = Number.isInteger(error.status) && error.status > 0 ? error.status : 1;
  }
}

module.exports = {runPreInstall, rubySource, versions};
