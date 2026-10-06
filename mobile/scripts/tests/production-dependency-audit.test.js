'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {createRequire} = require('node:module');
const {evaluateAudit, lockFingerprint} = require('../audit-production-dependencies');
const {loadNycConfig, isLoading} = require('@istanbuljs/load-nyc-config');

// Exercise the shipped Istanbul consumer, not a replacement YAML parser.
// Fixtures are owned temporary directories; never touch the app's config.
function nycFixture(t, files) {
  // The real loader resolves extended config paths through realpath. Keep
  // this owned fixture on the same physical root when TEMP is a junction.
  const temporaryRoot = fs.realpathSync(os.tmpdir());
  const directory = fs.mkdtempSync(path.join(temporaryRoot, 'rokn-nyc-compat-'));
  t.after(() => {
    assert.equal(path.dirname(directory), temporaryRoot);
    assert.ok(path.basename(directory).startsWith('rokn-nyc-compat-'));
    fs.rmSync(directory, {recursive: true, force: true});
  });
  fs.writeFileSync(path.join(directory, 'package.json'), JSON.stringify({private: true}));
  for (const [name, contents] of Object.entries(files)) {
    fs.writeFileSync(path.join(directory, name), contents);
  }
  return directory;
}

test('real Istanbul YAML resolution and patched tool versions match the lock', () => {
  const consumer = createRequire(require.resolve('@istanbuljs/load-nyc-config/package.json'));
  assert.equal(consumer('js-yaml/package.json').version, '4.3.2');
  assert.equal(consumer('argparse/package.json').version, '2.0.1');
  assert.equal(require('compression/package.json').version, '1.8.2');
  assert.equal(require('source-map-js/package.json').version, '1.2.2');
  const lock = require('../../package-lock.json');
  assert.ok(!Object.keys(lock.packages).some(key => key.endsWith('/sprintf-js')));
  assert.ok(!lock.packages['node_modules/@istanbuljs/load-nyc-config/node_modules/js-yaml']);
});

test('real loader preserves JSON and both YAML extensions, scalar arrays and camelCase', async t => {
  for (const extension of ['json', 'yaml', 'yml']) {
    const file = `.nycrc.${extension}`;
    const data = {'all': true, 'include': 'src/**/*.js', 'exclude': ['vendor/**'],
      'check-coverage': true, 'branches': 80, 'extension': '.js'};
    const contents = extension === 'json' ? JSON.stringify(data)
      : 'all: true\ninclude: "src/**/*.js"\nexclude: ["vendor/**"]\ncheck-coverage: true\nbranches: 80\nextension: .js\n';
    const cwd = nycFixture(t, {[file]: contents});
    assert.deepEqual(await loadNycConfig({cwd, nycrcPath: file}), {
      cwd, all: true, include: ['src/**/*.js'], exclude: ['vendor/**'],
      checkCoverage: true, branches: 80, extension: ['.js'],
    });
  }
});

test('real loader preserves mixed extends precedence and relative cwd', async t => {
  const cwd = nycFixture(t, {
    'package.json': JSON.stringify({private: true, nyc: {lines: 75}}),
    'base.json': JSON.stringify({'cwd': './sources', 'all': true, 'lines': 80}),
    'second.yml': 'branches: 85\nlines: 90\n',
    '.nycrc.yaml': 'extends: ["./base.json", "./second.yml"]\nlines: 60\ninclude: ["**/*.js"]\n',
  });
  // load-nyc-config 1.1.0 applies extended values after child values, then
  // later extensions win. Preserve that actual contract rather than inventing one.
  assert.deepEqual(await loadNycConfig({cwd}), {
    cwd: path.join(cwd, 'sources'), all: true, lines: 90, branches: 85, include: ['**/*.js'],
  });
});

test('real loader rejects malformed YAML, missing files and invalid or circular extends', async t => {
  const cwd = nycFixture(t, {
    'broken.yml': 'include: [unfinished\n',
    'invalid.yml': 'extends: [23]\n',
    'missing-extends.yml': 'extends: ./absent.json\n',
    'a.yml': 'extends: ./b.yml\n',
    'b.yml': 'extends: ./a.yml\n',
  });
  for (const [nycrcPath, expected] of [
    ['broken.yml', /unexpected end/], ['absent.yml', /not found/],
    ['invalid.yml', /invalid 'extends'/], ['missing-extends.yml', /Could not resolve/],
    ['a.yml', /Circular extended configurations/],
  ]) {
    await assert.rejects(loadNycConfig({cwd, nycrcPath}), expected);
    assert.equal(isLoading(), false);
  }
});

test('actual Babel Istanbul plugin consumes YAML and instruments only configured files', t => {
  const cwd = nycFixture(t, {
    '.nycrc.yaml': 'include: ["lesson.js", "excluded.js"]\nexclude: ["excluded.js"]\ncoverage-variable: __rokn_yaml_coverage__\n',
    'lesson.js': 'function double(value) { return value * 2; }',
    'excluded.js': 'function excluded(value) { return value; }',
  });
  const babel = require('@babel/core');
  const plugin = require('babel-plugin-istanbul');
  const transform = name => babel.transformFileSync(path.join(cwd, name), {
    babelrc: false, configFile: false,
    plugins: [[plugin, {cwd, nycrcPath: '.nycrc.yaml'}]],
  }).code;
  assert.match(transform('lesson.js'), /__rokn_yaml_coverage__/);
  assert.match(transform('lesson.js'), /statementMap/);
  assert.doesNotMatch(transform('excluded.js'), /__rokn_yaml_coverage__|statementMap/);
});

function fixture() {
  const lock = {packages: {
    'node_modules/expo/node_modules/example-tool': {version: '1.2.3', integrity: 'sha512-reviewed'},
    'node_modules/example-root': {version: '4.5.6', integrity: 'sha512-root'},
  }};
  const advisory = {name: 'example-root', dependency: 'example-root',
    url: 'https://github.com/advisories/GHSA-example-reviewed', severity: 'high', range: '<=4.5.6'};
  const entries = {
    'example-tool': {severity: 'high', nodes: {'node_modules/expo/node_modules/example-tool':
      {...lock.packages['node_modules/expo/node_modules/example-tool']}}, via: ['example-root']},
    'example-root': {severity: 'high', nodes: {'node_modules/example-root':
      {...lock.packages['node_modules/example-root']}}, via: [advisory]},
  };
  const policy = {schemaVersion: 1, reviewedAt: '2026-10-05T00:00:00.000Z',
    expiresAt: '2026-10-19T00:00:00.000Z', lockSha256: lockFingerprint(lock), entries};
  const report = {auditReportVersion: 2,
    vulnerabilities: Object.fromEntries(Object.entries(entries).map(([name, entry]) =>
      [name, {name, severity: entry.severity, nodes: Object.keys(entry.nodes), via: structuredClone(entry.via)}])),
    metadata: {dependencies: {total: 2}, vulnerabilities: {info: 0, low: 0, moderate: 0, high: 2, critical: 0, total: 2}}};
  return {lock, policy, report, now: new Date('2026-10-05T12:00:00.000Z')};
}

const evaluate = f => evaluateAudit(f.report, f.lock, f.policy, f.now);

test('accepts only the exact reviewed nested package and advisory closure', () => {
  assert.deepEqual(evaluate(fixture()), ['example-tool', 'example-root']);
});

test('rejects a new advisory on an already accepted root', () => {
  const f = fixture();
  f.report.vulnerabilities['example-root'].via.push({...f.report.vulnerabilities['example-root'].via[0],
    url: 'https://github.com/advisories/GHSA-example-new'});
  assert.throws(() => evaluate(f), /Unreviewed/);
});

for (const field of ['url', 'range', 'dependency', 'name', 'severity']) {
  test(`rejects changed advisory ${field}`, () => {
    const f = fixture();
    f.report.vulnerabilities['example-root'].via[0][field] = field === 'severity' ? 'critical' : 'changed';
    assert.throws(() => evaluate(f), /Unreviewed/);
  });
}

test('registry descriptions and source IDs are not confused with advisory identity', () => {
  const f = fixture();
  Object.assign(f.report.vulnerabilities['example-root'].via[0], {source: 9999, title: 'Updated explanation'});
  assert.equal(evaluate(f).length, 2);
});

for (const field of ['version', 'integrity']) {
  test(`rejects changed nested installed ${field}`, () => {
    const f = fixture();
    f.lock.packages['node_modules/expo/node_modules/example-tool'][field] = 'changed';
    assert.throws(() => evaluate(f), /another lockfile/);
    // Matching the new lock hash alone must not authorize a different package.
    f.policy.lockSha256 = lockFingerprint(f.lock);
    assert.throws(() => evaluate(f), /Unreviewed/);
  });
}

test('rejects additional nested installations and missing lock entries', () => {
  const f = fixture();
  f.report.vulnerabilities['example-root'].nodes.push('node_modules/other/node_modules/example-root');
  assert.throws(() => evaluate(f), /Unreviewed/);
  f.report.vulnerabilities['example-root'].nodes.pop();
  delete f.lock.packages['node_modules/example-root'];
  f.policy.lockSha256 = lockFingerprint(f.lock);
  assert.throws(() => evaluate(f), /Unreviewed/);
});

test('rejects unreviewed packages even at low severity', () => {
  const f = fixture();
  f.report.vulnerabilities['new-package'] = {...structuredClone(f.report.vulnerabilities['example-root']),
    name: 'new-package', severity: 'low'};
  f.report.metadata.vulnerabilities.low++;
  f.report.metadata.vulnerabilities.total++;
  assert.throws(() => evaluate(f), /new-package/);
});

test('never accepts critical severity through a manifest', () => {
  const f = fixture();
  f.report.vulnerabilities['example-root'].severity = 'critical';
  f.policy.entries['example-root'].severity = 'critical';
  f.report.metadata.vulnerabilities.high--;
  f.report.metadata.vulnerabilities.critical++;
  assert.throws(() => evaluate(f), /Unreviewed/);
});

test('rejects stale acceptance exactly at expiration, before review and invalid dates', () => {
  for (const instant of ['2026-10-19T00:00:00.000Z', '2026-10-04T23:59:59.999Z', 'invalid']) {
    const f = fixture();
    f.now = new Date(instant);
    assert.throws(() => evaluate(f), /expired, invalid/);
  }
  const f = fixture();
  f.policy.expiresAt = '2026-10-20T00:00:00.000Z';
  assert.throws(() => evaluate(f), /expired, invalid/);
});

test('rejects missing, error and inconsistent reports instead of treating them as clean', () => {
  for (const report of [{}, null, {error: {code: 'EAUDITNOLOCK'}},
    {...fixture().report, auditReportVersion: 1},
    {...fixture().report, metadata: {dependencies: {total: 0}}}]) {
    const f = fixture();
    f.report = report;
    assert.throws(() => evaluate(f), /incomplete or failed/);
  }
  const f = fixture();
  f.report.metadata.vulnerabilities.high = 0;
  assert.throws(() => evaluate(f), /metadata/);
});

test('rejects unknown chain links, duplicate nodes and empty advisory chains', () => {
  for (const mutate of [
    f => f.report.vulnerabilities['example-tool'].via.push('unknown-root'),
    f => f.report.vulnerabilities['example-root'].nodes.push('node_modules/example-root'),
    f => { f.report.vulnerabilities['example-root'].via = []; },
  ]) {
    const f = fixture();
    mutate(f);
    assert.throws(() => evaluate(f));
  }
});

test('a real clean report needs no acceptance and a withdrawn leaf cannot leave dangling dependants', () => {
  const f = fixture();
  f.report.vulnerabilities = {};
  f.report.metadata.vulnerabilities = {info: 0, low: 0, moderate: 0, high: 0, critical: 0, total: 0};
  assert.deepEqual(evaluateAudit(f.report, f.lock, null, f.now), []);
  const dangling = fixture();
  delete dangling.report.vulnerabilities['example-root'];
  dangling.report.metadata.vulnerabilities.high--;
  dangling.report.metadata.vulnerabilities.total--;
  assert.throws(() => evaluate(dangling), /Unreviewed/);
});

test('a malformed acceptance fails closed', () => {
  for (const policy of [null, {}, {...fixture().policy, entries: null}]) {
    const f = fixture();
    f.policy = policy;
    assert.throws(() => evaluate(f), /expired, invalid/);
  }
});
