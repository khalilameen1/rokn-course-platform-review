'use strict';

// Attribution for a local test build from an intentionally dirty worktree.
// Production still requires the existing clean-commit release gates.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const [mode, reportPath] = process.argv.slice(2);
if (!['capture', 'verify'].includes(mode) || !reportPath) {
  throw new Error('Usage: node scripts/capture-local-build-source.js capture|verify <report.json>');
}
const sha = data => crypto.createHash('sha256').update(data).digest('hex');
const files = [...new Set(execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z'], {
  cwd: root, encoding: 'utf8', windowsHide: true,
}).split('\0').filter(file => file.startsWith('mobile/') && fs.existsSync(path.join(root, file))))].sort();
const entries = files.map(file => ({path: file, sha256: sha(fs.readFileSync(path.join(root, file)))}));
const fingerprint = sha(JSON.stringify(entries));
if (mode === 'capture') {
  const commit = execFileSync('git', ['rev-parse', 'HEAD'], {cwd: root, encoding: 'utf8', windowsHide: true}).trim();
  fs.writeFileSync(reportPath, JSON.stringify({version: 1, scope: 'Tracked and nonignored local mobile files; includes uncommitted changes', commit, fingerprint, files: entries}, null, 2));
} else {
  const expected = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
  if (expected.fingerprint !== fingerprint) {
    const previous = new Map(expected.files.map(file => [file.path, file.sha256]));
    const current = new Map(entries.map(file => [file.path, file.sha256]));
    const changed = [...new Set([...previous.keys(), ...current.keys()])].filter(file => previous.get(file) !== current.get(file));
    throw new Error(`Mobile source changed during build: ${changed.join(', ')}`);
  }
}
console.log(JSON.stringify({mode, files: entries.length, fingerprint}));
