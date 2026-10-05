'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {evaluateAudit, lockFingerprint} = require('../audit-production-dependencies');

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
