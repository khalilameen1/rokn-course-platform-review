'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const test = require('node:test');
const YAML = require('yaml');

const workflow = YAML.parse(fs.readFileSync(path.resolve(
  __dirname, '../../../.github/workflows/refresh-ios-lock.yml',
), 'utf8'));
const jobs = Object.values(workflow.jobs);
const commits = jobs.flatMap(job => job.steps.filter(step => step.name?.startsWith('Commit ')));
const bash = process.platform === 'win32'
  ? 'C:\\Program Files\\Git\\bin\\bash.exe'
  : 'bash';
const fixtureEnv = cwd => {
  const env = {
    ...process.env,
    GIT_CONFIG_NOSYSTEM: '1',
    GIT_CONFIG_GLOBAL: path.join(cwd, 'rokn-absent-global.config'),
  };
  for (const key of ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE']) delete env[key];
  return env;
};
const run = (command, args, cwd, env = fixtureEnv(cwd)) => spawnSync(command, args, {
  cwd, env, encoding: 'utf8', windowsHide: true,
});
const git = (cwd, ...args) => {
  const result = run('git', args, cwd);
  assert.equal(result.status, 0, result.stderr || result.error?.message);
  return result.stdout.trim();
};
const shell = (script, cwd, branch, type = 'branch') => run(
  bash, ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', script], cwd,
  {...fixtureEnv(cwd), GITHUB_REF_TYPE: type, ROKN_LOCK_REFRESH_BRANCH: branch},
);
const write = (cwd, name, contents) => {
  const file = path.join(cwd, name);
  fs.mkdirSync(path.dirname(file), {recursive: true});
  fs.writeFileSync(file, contents);
};
const androidFiles = [
  'mobile/android/app/gradle.lockfile',
  'mobile/android/buildscript-gradle.lockfile',
  'mobile/android/settings-gradle.lockfile',
  'mobile/android/gradle/verification-metadata.xml',
];
const nativeFiles = [...androidFiles, 'mobile/Gemfile.lock', 'mobile/ios/Podfile.lock'];
const legalFile = 'mobile/NATIVE_THIRD_PARTY_NOTICES.md';

test('native refresh compiles Android once on Linux and resolves CocoaPods on macOS', () => {
  const linux = workflow.jobs['refresh-android'];
  const mac = workflow.jobs.refresh;
  const linuxRun = linux.steps.map(step => step.run || '').join('\n');
  const macRun = mac.steps.map(step => step.run || '').join('\n');
  assert.equal(mac.needs, 'refresh-android');
  assert.match(linuxRun, /:app:lintRelease :app:testReleaseUnitTest :app:bundleRelease/);
  assert.doesNotMatch(macRun, /:app:(?:bundleRelease|lintRelease|testReleaseUnitTest)/);
  assert.match(macRun, /:app:roknResolvedReleaseLicenseInputs/);
  assert.match(macRun, /bundle _4\.0\.20_ exec pod install/);
  assert.match(macRun, /npm run verify:ios-lock/);
});

const fixture = t => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rokn-lock-ref-test-'));
  t.after(() => {
    const resolved = path.resolve(directory);
    assert.equal(path.dirname(resolved), path.resolve(os.tmpdir()));
    assert(path.basename(resolved).startsWith('rokn-lock-ref-test-'));
    fs.rmSync(resolved, {recursive: true, force: true});
  });
  return directory;
};

test('lock refresh binds both checkouts and all writes to the selected branch', () => {
  assert.equal(workflow.env.ROKN_LOCK_REFRESH_BRANCH, '${{ github.ref_name }}');
  assert.equal(commits.length, 3);
  for (const job of jobs) {
    const checkout = job.steps.findIndex(step => step.uses?.startsWith('actions/checkout@'));
    assert(checkout > 0);
    assert.equal(job.steps[checkout].with.ref, '${{ env.ROKN_LOCK_REFRESH_BRANCH }}');
    assert.equal(job.steps[0].name, 'Require a branch target for lock refresh');
    assert.equal(job.steps[0]['working-directory'], '.');
  }
  for (const step of commits) {
    assert.match(step.run, /git fetch origin "refs\/heads\/\$ROKN_LOCK_REFRESH_BRANCH:refs\/remotes\/origin\/\$ROKN_LOCK_REFRESH_BRANCH"/);
    assert.match(step.run, /git rebase "origin\/\$ROKN_LOCK_REFRESH_BRANCH"/);
    assert.match(step.run, /git push origin "HEAD:refs\/heads\/\$ROKN_LOCK_REFRESH_BRANCH"/);
    assert.doesNotMatch(step.run, /--force|HEAD:main|origin\/main|\$\{\{/);
  }
});

test('actual branch guards reject tags and invalid refs before checkout', t => {
  const directory = fixture(t);
  for (const job of jobs) {
    const guard = job.steps[0].run;
    const accepted = shell(guard, directory, 'codex/final-gate');
    assert.equal(accepted.status, 0, accepted.stderr || accepted.error?.message);
    assert.notEqual(shell(guard, directory, 'v1.0.61', 'tag').status, 0);
    assert.notEqual(shell(guard, directory, '').status, 0);
    assert.notEqual(shell(guard, directory, 'codex/invalid..ref').status, 0);
  }
});

for (const branch of ['main', 'codex/final-gate', 'codex/branch;echo-owned']) {
  test(`actual commit steps preserve target ownership and no-change behavior on ${branch}`, t => {
    const directory = fixture(t);
    const remote = path.join(directory, 'origin.git');
    const seed = path.join(directory, 'seed');
    const checkout = path.join(directory, 'checkout');
    fs.mkdirSync(seed);
    git(directory, 'init', '--bare', '--initial-branch=main', remote);
    git(seed, 'init', '--initial-branch=main');
    git(seed, 'config', 'user.name', 'Local fixture');
    git(seed, 'config', 'user.email', 'fixture@example.invalid');
    for (const file of [...nativeFiles, legalFile]) write(seed, file, 'fixture baseline\n');
    git(seed, 'add', '.');
    git(seed, 'commit', '-m', 'Local baseline');
    git(seed, 'remote', 'add', 'origin', remote);
    git(seed, 'push', 'origin', 'main');
    git(directory, 'clone', remote, checkout);
    if (branch !== 'main') {
      git(checkout, 'checkout', '-b', branch);
      git(checkout, 'push', 'origin', `HEAD:refs/heads/${branch}`);
    }
    // Actual scripts can only contact this disposable local origin, never GitHub.
    assert.equal(path.resolve(git(checkout, 'remote', 'get-url', 'origin')), path.resolve(remote));
    for (const protocol of ['http', 'https', 'ssh', 'git']) {
      git(checkout, 'config', `protocol.${protocol}.allow`, 'never');
    }
    const initialMain = git(remote, 'rev-parse', 'refs/heads/main');
    for (const [index, step] of commits.entries()) {
      const files = index === 0 ? androidFiles : index === 1 ? nativeFiles : [legalFile];
      for (const file of files) write(checkout, file, `fixture change ${index}\n`);
      if (index === 1) write(checkout, 'pending-legal-work.txt', 'retained across native-lock commit\n');
      const before = git(remote, 'rev-parse', `refs/heads/${branch}`);
      const result = shell(step.run, checkout, branch);
      assert.equal(result.status, 0, result.stderr || result.error?.message);
      const after = git(remote, 'rev-parse', `refs/heads/${branch}`);
      assert.notEqual(after, before, step.name);
      assert.equal(git(checkout, 'rev-parse', 'HEAD'), after);
      if (branch !== 'main') assert.equal(git(remote, 'rev-parse', 'refs/heads/main'), initialMain);
      if (index >= 1) {
        assert.equal(fs.readFileSync(path.join(checkout, 'pending-legal-work.txt'), 'utf8'),
          'retained across native-lock commit\n');
        assert.equal(git(remote, 'ls-tree', `refs/heads/${branch}`, 'pending-legal-work.txt'), '');
      }
      const unchanged = shell(step.run, checkout, branch);
      assert.equal(unchanged.status, 0, unchanged.stderr || unchanged.error?.message);
      assert.equal(git(remote, 'rev-parse', `refs/heads/${branch}`), after);
    }
  });
}
