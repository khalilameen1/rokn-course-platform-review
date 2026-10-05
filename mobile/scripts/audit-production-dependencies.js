'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {createHash} = require('node:crypto');
const {isDeepStrictEqual} = require('node:util');

const root = path.resolve(__dirname, '..');
const severities = ['info', 'low', 'moderate', 'high', 'critical'];
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const lockFingerprint = lock => createHash('sha256').update(JSON.stringify(lock)).digest('hex');

// npm remains the source of the live audit. Only these reviewed facts are
// compared; changing registry source IDs, descriptions or CVSS prose is not a
// new advisory, but changing its identity, severity or affected range is.
function normalizeVia(via) {
  if (!Array.isArray(via) || via.length === 0) throw new Error('Missing advisory chain.');
  return via.map(item => {
    if (typeof item === 'string' && item !== '') return JSON.stringify(item);
    if (!object(item) || !['name', 'dependency', 'url', 'severity', 'range']
      .every(key => typeof item[key] === 'string' && item[key] !== '')
      || !severities.includes(item.severity)) {
      throw new Error('Malformed advisory.');
    }
    return JSON.stringify({name: item.name, dependency: item.dependency,
      url: item.url, severity: item.severity, range: item.range});
  }).sort();
}

function validateReport(report) {
  if (!object(report) || report.error || report.auditReportVersion !== 2
    || !object(report.vulnerabilities) || !object(report.metadata?.vulnerabilities)
    || !Number.isInteger(report.metadata?.dependencies?.total)
    || report.metadata.dependencies.total < 1) {
    throw new Error('npm audit returned an incomplete or failed report.');
  }
  const counts = Object.fromEntries(severities.map(severity => [severity, 0]));
  for (const [name, entry] of Object.entries(report.vulnerabilities)) {
    if (!object(entry) || entry.name !== name || !severities.includes(entry.severity)
      || !Array.isArray(entry.nodes) || entry.nodes.length === 0
      || entry.nodes.some(node => typeof node !== 'string' || !node.startsWith('node_modules/'))
      || new Set(entry.nodes).size !== entry.nodes.length) {
      throw new Error(`Malformed dependency entry: ${name}.`);
    }
    normalizeVia(entry.via);
    counts[entry.severity]++;
  }
  const actual = {...counts, total: Object.keys(report.vulnerabilities).length};
  if (Object.keys(actual).some(key => actual[key] !== report.metadata.vulnerabilities[key])) {
    throw new Error('npm audit metadata does not match its dependency entries.');
  }
}

function evaluateAudit(report, lock, policy, now = new Date()) {
  validateReport(report);
  const names = Object.keys(report.vulnerabilities);
  if (names.length === 0) return [];
  const reviewedAt = Date.parse(policy?.reviewedAt);
  const expiresAt = Date.parse(policy?.expiresAt);
  if (!object(lock?.packages) || policy?.schemaVersion !== 1 || !object(policy.entries)
    || !Number.isFinite(reviewedAt) || !Number.isFinite(expiresAt)
    || expiresAt <= reviewedAt || expiresAt - reviewedAt > 14 * 24 * 60 * 60 * 1000
    || !Number.isFinite(now.getTime()) || now.getTime() < reviewedAt
    || now.getTime() >= expiresAt || policy.lockSha256 !== lockFingerprint(lock)) {
    throw new Error('Dependency risk acceptance is expired, invalid or belongs to another lockfile.');
  }
  const unreviewed = [];
  for (const [name, entry] of Object.entries(report.vulnerabilities)) {
    const accepted = Object.hasOwn(policy.entries, name) ? policy.entries[name] : null;
    let matches = object(accepted) && object(accepted.nodes)
      && entry.severity !== 'critical' && entry.severity === accepted.severity
      && isDeepStrictEqual([...entry.nodes].sort(), Object.keys(accepted.nodes).sort())
      && isDeepStrictEqual(normalizeVia(entry.via), normalizeVia(accepted.via));
    if (matches) matches = entry.nodes.every(node => {
      const installed = lock.packages[node];
      const reviewed = accepted.nodes[node];
      return object(installed) && object(reviewed)
        && typeof reviewed.version === 'string' && reviewed.version !== ''
        && typeof reviewed.integrity === 'string' && reviewed.integrity !== ''
        && installed.version === reviewed.version && installed.integrity === reviewed.integrity;
    });
    if (matches) matches = entry.via.every(via => typeof via !== 'string'
      || Object.hasOwn(report.vulnerabilities, via));
    if (!matches) unreviewed.push(name);
  }
  if (unreviewed.length) {
    throw new Error(`Unreviewed production dependency advisories: ${unreviewed.join(', ')}.`);
  }
  return names;
}

function main() {
  const npmCli = process.env.npm_execpath;
  if (!npmCli || !fs.existsSync(npmCli)) {
    throw new Error('npm audit must run through npm so its verified CLI path is available.');
  }
  const audit = spawnSync(process.execPath,
    [npmCli, 'audit', '--omit=dev', '--audit-level=high', '--json'],
    {cwd: root, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024});
  if (audit.error || ![0, 1].includes(audit.status)) {
    throw new Error(audit.stderr || audit.error?.message || 'npm audit could not run.');
  }
  const report = JSON.parse(audit.stdout);
  const lock = JSON.parse(fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'));
  const policy = JSON.parse(fs.readFileSync(path.join(__dirname, 'production-audit-risk-acceptance.json'), 'utf8'));
  const reviewed = evaluateAudit(report, lock, policy);
  if (reviewed.length) {
    console.warn(`Live npm audit: ${reviewed.length} dependency entries remain vulnerable. `
      + `Exact reviewed risk accepted temporarily until ${policy.expiresAt}; dependencies are NOT patched.`);
  } else {
    console.log('Live npm audit passed with no production dependency advisories.');
  }
}

if (require.main === module) {
  try { main(); } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
module.exports = {evaluateAudit, lockFingerprint};
