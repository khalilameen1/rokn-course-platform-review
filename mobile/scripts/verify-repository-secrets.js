'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {execFileSync, spawnSync} = require('node:child_process');

const repositoryRoot = path.resolve(__dirname, '..');
const maxGitBlobBytes = 256 * 1024 * 1024;
const auditedPublicClientConfigs = new Set([
  'android/app/google-services.json',
  'ios/GoogleService-Info.plist',
  'ios/Rokn/GoogleService-Info.plist',
]);

function sensitivePathRule(relativePath) {
  const normalized = relativePath.replaceAll('\\', '/').replace(/^\/+/, '');
  const lower = normalized.toLowerCase();
  const basename = path.posix.basename(lower);

  if (basename.startsWith('.env') && lower !== '.env.example') {
    return 'environment_file';
  }
  if (basename === 'info.txt') {
    return 'hosting_credentials_file';
  }
  if (
    /(?:^\/|\/)(?:id_rsa|id_ed25519|service[-_]?account[^/]*)$/i.test(
      '/' + normalized,
    )
  ) {
    return 'credential_file';
  }
  if (/\.(?:key|pem|p12|pfx|jks|mobileprovision)$/i.test(normalized)) {
    return 'private_key_file';
  }

  return null;
}

const contentRules = [
  [
    'private_key_material',
    /-----BEGIN(?: RSA| EC| OPENSSH| DSA| ENCRYPTED)? PRIVATE KEY-----/,
  ],
  ['aws_access_key', /\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/],
  [
    'github_access_token',
    /\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/,
  ],
  ['slack_access_token', /\bxox[baprs]-[A-Za-z0-9-]{20,}\b/],
  ['stripe_live_secret', /\b(?:sk|rk)_live_[A-Za-z0-9]{16,}\b/],
  [
    'firebase_legacy_server_key',
    /\bAAAA[A-Za-z0-9_-]{7,}:[A-Za-z0-9_-]{20,}\b/,
  ],
  ['google_api_key', /\bAIza[0-9A-Za-z_-]{35}\b/],
  [
    'credentialed_connection_url',
    /\b(?:mysql|postgres(?:ql)?|redis):\/\/[^:\s/]+:[^@\s/]+@/i,
  ],
];

const historyContentPatterns = [
  [
    'private_key_material',
    '-----BEGIN( RSA| EC| OPENSSH| DSA| ENCRYPTED)? PRIVATE KEY-----',
  ],
  ['aws_access_key', '(AKIA|ASIA)[0-9A-Z]{16}'],
  [
    'github_access_token',
    '(gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})',
  ],
  ['slack_access_token', 'xox[baprs]-[A-Za-z0-9-]{20,}'],
  ['stripe_live_secret', '(sk|rk)_live_[A-Za-z0-9]{16,}'],
  ['firebase_legacy_server_key', 'AAAA[A-Za-z0-9_-]{7,}:[A-Za-z0-9_-]{20,}'],
  ['google_api_key', 'AIza[0-9A-Za-z_-]{35}'],
  [
    'credentialed_connection_url',
    '(mysql|postgres|postgresql|redis)://[^:/[:space:]]+:[^@/[:space:]]+@',
  ],
];

const secretNames = [
  'APP_KEY',
  'DB_PASSWORD',
  'MAIL_PASSWORD',
  'TRANSACTIONAL_MAIL_PASSWORD',
  'REDIS_PASSWORD',
  'AWS_ACCESS_KEY_ID',
  'AWS_SECRET_ACCESS_KEY',
  'FCM_SERVER_KEY',
  'FIREBASE_CREDENTIALS',
  'FIREBASE_CREDENTIALS_BASE64',
  'GOOGLE_MAPS_API_KEY',
  'GOOGLE_MAPS_BROWSER_KEY',
  'GOOGLE_CLIENT_SECRET',
  'FACEBOOK_CLIENT_SECRET',
  'TIKTOK_CLIENT_SECRET',
  'APPLE_CLIENT_SECRET',
  'PUSHER_APP_SECRET',
  'KASHIER_API_KEY',
  'KASHIER_SECRET_KEY',
  'KASHIER_WEBHOOK_SECRET',
  'KASHIER_LIVE_API_KEY',
  'KASHIER_LIVE_SECRET_KEY',
  'KASHIER_TEST_API_KEY',
  'KASHIER_TEST_SECRET_KEY',
  'BUNNY_API_KEY',
  'BUNNY_LIBRARY_API_KEY',
  'BUNNY_STREAM_API_KEY',
  'BUNNY_STORAGE_PASSWORD',
  'BUNNY_TOKEN_AUTH_KEY',
  'BUNNY_STORAGE_TOKEN_AUTH_KEY',
  'OPENROUTER_API_KEY',
  'REWARD_TOMBSTONE_HMAC_KEY',
  'WHATSAPP_ACCESS_TOKEN',
  'WHATSAPP_APP_SECRET',
  'WHATSPIE_API_KEY',
  'UPLOAD_KEY_PASSWORD',
  'UPLOAD_STORE_PASSWORD',
  'ROKN_IOS_DISTRIBUTION_P12_BASE64',
  'ROKN_IOS_DISTRIBUTION_P12_PASSWORD',
  'ROKN_IOS_APPSTORE_PROFILE_BASE64',
  'BUILD_CERTIFICATE_BASE64',
  'BUILD_PROVISION_PROFILE_BASE64',
  'P12_PASSWORD',
  'KEYCHAIN_PASSWORD',
  'ROKN_SMOKE_FIXTURE_TOKEN',
  'ROKN_SMOKE_EMAIL',
  'ROKN_SMOKE_PASSWORD',
  'ROKN_SMOKE_APK_URL',
  'ROKN_SMOKE_APK_PROVENANCE_URL',
  'ROKN_SMOKE_FORCED_UPDATE_FIXTURE_URL',
  'ROKN_SMOKE_APK_SIGNER_SHA256',
  'SENTRY_AUTH_TOKEN',
];
const auditedNonSecretNames = new Set([
  'DEVICE_TOKEN',
  'LEGACY_SECURE_TOKEN_KEYS',
  'ROKN_SMOKE_OAUTH_PASSWORD_FIELD',
  'SECURE_TOKEN_KEY',
]);
const secretNamePattern = secretNames.join('|');
const genericLineSecretAssignment =
  /^[ \t]*(?:(?:export|set|const|let|var)[ \t]+|-[ \t]+)?["']?([A-Z][A-Z0-9_]*)["']?[ \t]*(?:=(?![=>])|:)[ \t]*([^\r\n]*)$/gim;
const genericCommandSecretAssignment =
  /^[ \t]*env[ \t]+["']?([A-Z][A-Z0-9_]*)["']?[ \t]*=(?![=>])[ \t]*([^\r\n]*)$/gim;
const genericJsonSecretAssignment =
  /(?:[,{])[ \t]*["']?([A-Z][A-Z0-9_]*)["']?[ \t]*:[ \t]*(?:"((?:\\.|[^"\\])*)"|'((?:\\.|[^'\\])*)'|([^,}\r\n]*))/gim;
const genericSourceSecretAssignment =
  /(?:^|[;\r\n])[ \t]*(?:(?:const|let|var)[ \t]+|process\.env\.|\$env:)([A-Z][A-Z0-9_]*)[ \t]*=(?![=>])\s*([^;\r\n]*)/gim;
const genericBracketSourceSecretAssignment =
  /(?:^|[;\r\n])[ \t]*process\.env\[[ \t]*["']([A-Z][A-Z0-9_]*)["'][ \t]*\][ \t]*=(?![=>])\s*([^;\r\n]*)/gim;
const genericMultilineColonSecretAssignment =
  /^[ \t]*(?:-[ \t]+)?["']?([A-Z][A-Z0-9_]*)["']?[ \t]*:[ \t]*\r?\n[ \t]*(?:"((?:\\.|[^"\\])*)"|'((?:\\.|[^'\\])*)'|([^#\r\n]*))/gim;
const genericShellSecretAssignment =
  /(?:^|[ \t])([A-Z][A-Z0-9_]*)=(?![=>])(?:"((?:\\.|[^"\\])*)"([^\s;,]*)|'((?:\\.|[^'\\])*)'([^\s;,]*)|(\$\{\{[^\r\n]*?\}\}|\$\{[^}\r\n]+\}|\$[A-Z][A-Z0-9_]*|[^\s;,]+))/gm;
historyContentPatterns.push([
  'named_secret_assignment',
  `(${secretNamePattern})["']?[[:space:]]*(=|:)`,
]);
historyContentPatterns.push([
  'secret_like_assignment',
  `(APP_KEY|[A-Z][A-Z0-9_]*(PASSWORD|SECRET|API_KEY|TOKEN|CREDENTIALS|HMAC_KEY)[A-Z0-9_]*)["']?[[:space:]]*(=|:)`,
]);
historyContentPatterns.push([
  'bracket_named_secret_assignment',
  `process\\.env\\[[[:space:]]*["'](${secretNamePattern})["'][[:space:]]*\\][[:space:]]*=`,
]);
historyContentPatterns.push([
  'bracket_secret_like_assignment',
  `process\\.env\\[[[:space:]]*["'](APP_KEY|[A-Z][A-Z0-9_]*(PASSWORD|SECRET|API_KEY|TOKEN|CREDENTIALS|HMAC_KEY)[A-Z0-9_]*)["'][[:space:]]*\\][[:space:]]*=`,
]);

function isPlaceholder(value) {
  const normalized = String(value)
    .trim()
    .replace(/^["']|["']$/g, '');

  return (
    normalized === '' ||
    /^(?:null|false|none|n\/a)$/i.test(normalized) ||
    /^(?:\.{3}|<[^<>\r\n]+>)$/.test(normalized) ||
    /^\$\{\{\s*(?:secrets|vars)\.[A-Z][A-Z0-9_]*\s*\}\}$/i.test(normalized) ||
    /^\$\{[A-Z][A-Z0-9_]*\}$/.test(normalized) ||
    /^\$[A-Z][A-Z0-9_]*$/.test(normalized) ||
    /^process\.env\.[A-Z][A-Z0-9_]*$/.test(normalized) ||
    /^\/(?:run|var\/run)\/secrets\/[A-Za-z0-9._/-]+$/.test(normalized) ||
    /^(?:example(?:[-_ ].+)?|placeholder(?:[-_ ].+)?|dummy(?:[-_ ].+)?|fake(?:[-_ ].+)?|test(?:ing)?(?:[-_ ].+)?|ci(?:[-_ ].+)?|(?:replace[-_ ]+(?:me|this|value|before[-_ ]deploy))(?:[-_ ].+)?|change[-_ ]?me(?:[-_ ].+)?|your[-_ ].+|generate[-_ ].+|use[-_ ]a[-_ ]strong[-_ ].+|[A-Z][A-Z0-9_]*(?:_HERE|_PLACEHOLDER))$/i.test(
      normalized,
    ) ||
    /^base64:A{20,}={0,2}$/.test(normalized)
  );
}

function assignmentValue(value) {
  const normalized = String(value)
    .trim()
    .replace(/[ \t]+(?:#|\/\/).*$/, '')
    .trim()
    .replace(/[;,]+$/, '')
    .trim();
  const quoted = normalized.match(
    /^(?:"((?:\\.|[^"\\])*)"|'((?:\\.|[^'\\])*)')$/,
  );

  return quoted ? quoted[1] ?? quoted[2] ?? '' : normalized;
}

function commandAssignmentValue(value) {
  const normalized = String(value).trim();
  if (normalized === '') return '';

  if (normalized.startsWith('${{')) {
    const end = normalized.indexOf('}}');
    if (end < 0 || (normalized[end + 2] && !/\s/.test(normalized[end + 2]))) {
      return normalized;
    }
    return normalized.slice(0, end + 2);
  }

  if (normalized.startsWith('${')) {
    const end = normalized.indexOf('}');
    if (end < 0 || (normalized[end + 1] && !/\s/.test(normalized[end + 1]))) {
      return normalized;
    }
    return normalized.slice(0, end + 1);
  }

  if (normalized[0] === '"' || normalized[0] === "'") {
    const quote = normalized[0];
    let escaped = false;
    for (let index = 1; index < normalized.length; index += 1) {
      const character = normalized[index];
      if (escaped) {
        escaped = false;
        continue;
      }
      if (character === '\\') {
        escaped = true;
        continue;
      }
      if (character === quote) {
        if (normalized[index + 1] && !/\s/.test(normalized[index + 1])) {
          return normalized;
        }
        return normalized.slice(1, index);
      }
    }
    return normalized;
  }

  return normalized.split(/\s/, 1)[0];
}

function assignmentEntries(contents) {
  const entries = [];

  genericLineSecretAssignment.lastIndex = 0;
  for (const match of contents.matchAll(genericLineSecretAssignment)) {
    entries.push({name: match[1], value: assignmentValue(match[2])});
  }

  genericCommandSecretAssignment.lastIndex = 0;
  for (const match of contents.matchAll(genericCommandSecretAssignment)) {
    entries.push({name: match[1], value: commandAssignmentValue(match[2])});
  }

  genericJsonSecretAssignment.lastIndex = 0;
  for (const match of contents.matchAll(genericJsonSecretAssignment)) {
    entries.push({
      name: match[1],
      value: match[2] ?? match[3] ?? match[4] ?? '',
    });
  }

  genericSourceSecretAssignment.lastIndex = 0;
  for (const match of contents.matchAll(genericSourceSecretAssignment)) {
    entries.push({name: match[1], value: assignmentValue(match[2])});
  }

  genericBracketSourceSecretAssignment.lastIndex = 0;
  for (const match of contents.matchAll(genericBracketSourceSecretAssignment)) {
    entries.push({name: match[1], value: assignmentValue(match[2])});
  }

  genericMultilineColonSecretAssignment.lastIndex = 0;
  for (const match of contents.matchAll(
    genericMultilineColonSecretAssignment,
  )) {
    entries.push({
      name: match[1],
      value: match[2] ?? match[3] ?? assignmentValue(match[4] ?? ''),
    });
  }

  genericShellSecretAssignment.lastIndex = 0;
  for (const match of contents.matchAll(genericShellSecretAssignment)) {
    const quotedSuffix = match[3] ?? match[5] ?? '';
    const quotedValue = match[2] ?? match[4];
    entries.push({
      name: match[1],
      value:
        quotedValue === undefined
          ? match[6] ?? ''
          : quotedSuffix === ''
          ? quotedValue
          : `${quotedValue}${quotedSuffix}`,
    });
  }

  return entries;
}

function isSecretLikeName(name) {
  return (
    name === 'APP_KEY' ||
    /(?:^|_)PASSWORD(?:_|$)/.test(name) ||
    /(?:^|_)SECRET(?:S)?(?:_|$)/.test(name) ||
    /(?:^|_)API_KEY(?:_|$)/.test(name) ||
    /(?:^|_)TOKEN(?:_|$)/.test(name) ||
    /(?:^|_)CREDENTIALS(?:_|$)/.test(name) ||
    /(?:^|_)HMAC_KEY(?:_|$)/.test(name) ||
    /^(?:AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY)$/.test(name)
  );
}

function unclassifiedSecretAssignmentNames(contents, knownNames = secretNames) {
  const known = new Set(knownNames);

  return [
    ...new Set(
      assignmentEntries(contents)
        .filter(
          entry =>
            isSecretLikeName(entry.name) &&
            !known.has(entry.name) &&
            !auditedNonSecretNames.has(entry.name) &&
            !isPlaceholder(entry.value),
        )
        .map(entry => entry.name),
    ),
  ].sort();
}

function findUncoveredSecretNames(contents, knownNames = secretNames) {
  const known = new Set(knownNames);
  const candidates = new Set();
  const githubSecretPattern = /\$\{\{\s*secrets\.([A-Z][A-Z0-9_]*)\s*\}\}/g;

  for (const entry of assignmentEntries(contents)) {
    if (isSecretLikeName(entry.name)) candidates.add(entry.name);
  }

  for (const match of contents.matchAll(githubSecretPattern)) {
    candidates.add(match[1]);
  }

  return [...candidates]
    .filter(name => !known.has(name) && !auditedNonSecretNames.has(name))
    .sort();
}

function scanPathNames(relativePaths) {
  return [...new Set(relativePaths)]
    .map(relativePath => relativePath.replaceAll('\\', '/').replace(/^\/+/, ''))
    .map(relativePath => ({
      path: relativePath,
      rule: sensitivePathRule(relativePath),
    }))
    .filter(issue => issue.rule)
    .sort((left, right) =>
      (left.path + '|' + left.rule).localeCompare(
        right.path + '|' + right.rule,
      ),
    );
}

function scanContents(relativePath, contents) {
  const normalized = relativePath.replaceAll('\\', '/').replace(/^\/+/, '');
  const rules = [];

  for (const [rule, pattern] of contentRules) {
    if (
      rule === 'google_api_key' &&
      auditedPublicClientConfigs.has(normalized)
    ) {
      continue;
    }
    if (pattern.test(contents)) rules.push(rule);
  }

  if (findUncoveredSecretNames(contents).length > 0) {
    rules.push('unclassified_secret_name');
  }

  const known = new Set(secretNames);
  if (
    assignmentEntries(contents).some(
      entry => known.has(entry.name) && !isPlaceholder(entry.value),
    )
  ) {
    rules.push('non_placeholder_secret_assignment');
  }

  if (normalized !== '.env.example') {
    const missingKnownAssignment = new RegExp(
      `^[ \\t]*(?:(?:export|set|const|let|var)[ \\t]+|-[ \\t]+)?["']?(?:${secretNamePattern})["']?[ \\t]*=(?![=>])[ \\t]*(?:#.*)?$`,
      'gim',
    );
    if (missingKnownAssignment.test(contents)) {
      rules.push('non_placeholder_secret_assignment');
    }
  }

  return [...new Set(rules)];
}

function scanFiles(root, relativePaths) {
  const rootPath = fs.realpathSync(root);
  const rootPrefix = rootPath + path.sep;
  const issues = [];

  for (const relativePath of [...new Set(relativePaths)]) {
    const normalized = relativePath.replaceAll('\\', '/').replace(/^\/+/, '');
    const candidate = path.resolve(rootPath, normalized);

    if (
      !candidate.startsWith(rootPrefix) ||
      !fs.existsSync(candidate) ||
      !fs.statSync(candidate).isFile()
    ) {
      continue;
    }

    const pathRule = sensitivePathRule(normalized);
    if (pathRule) issues.push({path: normalized, rule: pathRule});

    const buffer = fs.readFileSync(candidate);
    const contents = buffer.toString('utf8');

    for (const rule of scanContents(normalized, contents)) {
      issues.push({path: normalized, rule});
    }
  }

  return [
    ...new Map(
      issues.map(issue => [issue.path + '|' + issue.rule, issue]),
    ).values(),
  ].sort((left, right) =>
    (left.path + '|' + left.rule).localeCompare(right.path + '|' + right.rule),
  );
}

function gitOutput(root, args) {
  try {
    return execFileSync('git', ['-C', root, ...args], {
      encoding: 'buffer',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch {
    throw new Error('Repository secret scan could not enumerate Git files.');
  }
}

function historyContentIssues(root, commits) {
  const issues = [];
  const gitPathPrefix = gitOutput(root, ['rev-parse', '--show-prefix'])
    .toString('utf8')
    .trim();

  for (const [rule, pattern] of historyContentPatterns) {
    for (let offset = 0; offset < commits.length; offset += 50) {
      const commitChunk = commits.slice(offset, offset + 50);
      const result = spawnSync(
        'git',
        // git-grep's documented worker limit avoids parallel blob expansion
        // exhausting hosted macOS resources. Coverage stays identical: all
        // revisions and files (including binary files) remain in the query.
        ['-C', root, 'grep', '--threads=1', '-l', '-E', '-e', pattern, ...commitChunk, '--'],
        {encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe']},
      );

      if (result.status === 1) continue;
      if (result.error || result.status !== 0) {
        throw new Error(
          'Repository secret history scan could not inspect Git blobs ' +
            `(${rule}; status=${result.status}; signal=${result.signal}; ` +
            `error=${result.error?.code || 'none'}).` +
            // git grep -l reports names, not content. Do not expose any raw
            // subprocess output in public logs, even when the query fails.
            (/invalid|regular expression|repetition|bracket|range/i.test(result.stderr || '')
              ? ' Git reported a regular-expression diagnostic.' : ''),
        );
      }

      for (const match of result.stdout.split(/\r?\n/).filter(Boolean)) {
        const parsed = match.match(/^([0-9a-f]{40}):(.*)$/);
        if (!parsed) {
          throw new Error(
            'Repository secret history scan returned an invalid blob path.',
          );
        }
        const [, commit, matchedPath] = parsed;
        if (
          rule === 'named_secret_assignment' ||
          rule === 'secret_like_assignment' ||
          rule === 'bracket_named_secret_assignment' ||
          rule === 'bracket_secret_like_assignment'
        ) {
          const contents = execFileSync(
            'git',
            [
              '-C',
              root,
              'cat-file',
              'blob',
              commit + ':' + gitPathPrefix + matchedPath,
            ],
            {
              encoding: 'buffer',
              stdio: ['ignore', 'pipe', 'pipe'],
              maxBuffer: maxGitBlobBytes,
            },
          ).toString('utf8');
          const detected =
            rule === 'named_secret_assignment' ||
            rule === 'bracket_named_secret_assignment'
              ? scanContents(matchedPath, contents).includes(
                  'non_placeholder_secret_assignment',
                )
              : unclassifiedSecretAssignmentNames(contents).length > 0;
          if (detected) {
            issues.push({
              path: 'history:' + matchedPath,
              rule:
                rule === 'named_secret_assignment' ||
                rule === 'bracket_named_secret_assignment'
                  ? 'non_placeholder_secret_assignment'
                  : 'unclassified_secret_name',
            });
          }
          continue;
        }
        if (
          rule === 'google_api_key' &&
          auditedPublicClientConfigs.has(matchedPath)
        ) {
          continue;
        }
        issues.push({
          path: 'history:' + matchedPath,
          rule,
        });
      }
    }
  }

  return issues;
}

function verify({root = repositoryRoot, includeHistory = false} = {}) {
  const currentOutput = gitOutput(root, [
    'ls-files',
    '--cached',
    '--others',
    '--exclude-standard',
    '-z',
  ]);
  const currentPaths = currentOutput
    .toString('utf8')
    .split('\0')
    .filter(Boolean);
  const issues = scanFiles(root, currentPaths);
  const rootWorkflowPath = path.resolve(
    root,
    '..',
    '.github',
    'workflows',
    'mobile-ci.yml',
  );
  let additionalFileCount = 0;
  if (fs.existsSync(rootWorkflowPath)) {
    additionalFileCount = 1;
    const logicalPath = '.github/workflows/mobile-ci.yml';
    const contents = fs.readFileSync(rootWorkflowPath, 'utf8');
    const pathRule = sensitivePathRule(logicalPath);
    if (pathRule) issues.push({path: logicalPath, rule: pathRule});
    scanContents(logicalPath, contents).forEach(rule =>
      issues.push({path: logicalPath, rule}),
    );
  }

  if (includeHistory) {
    const gitPathPrefix = gitOutput(root, ['rev-parse', '--show-prefix'])
      .toString('utf8')
      .trim();
    const historyPaths = gitOutput(root, [
      'log',
      '--all',
      '--format=',
      '--name-only',
      '--',
      '.',
    ])
      .toString('utf8')
      .split(/\r?\n/)
      .map(value => value.trim())
      .map(value =>
        gitPathPrefix !== '' && value.startsWith(gitPathPrefix)
          ? value.slice(gitPathPrefix.length)
          : value,
      )
      .filter(Boolean);
    issues.push(
      ...scanPathNames(historyPaths).map(issue => ({
        path: 'history:' + issue.path,
        rule: issue.rule,
      })),
    );

    const commits = gitOutput(root, ['rev-list', '--all'])
      .toString('utf8')
      .split(/\r?\n/)
      .map(value => value.trim())
      .filter(value => /^[0-9a-f]{40}$/.test(value));
    issues.push(...historyContentIssues(root, commits));
  }

  const deduplicatedIssues = [
    ...new Map(
      issues.map(issue => [issue.path + '|' + issue.rule, issue]),
    ).values(),
  ].sort((left, right) =>
    (left.path + '|' + left.rule).localeCompare(right.path + '|' + right.rule),
  );

  return {
    issues: deduplicatedIssues,
    currentFileCount: currentPaths.length + additionalFileCount,
  };
}

function main() {
  const result = verify({includeHistory: process.argv.includes('--history')});

  if (result.issues.length > 0) {
    console.error(
      'Repository secret scan failed. Values are intentionally redacted.',
    );
    result.issues.forEach(issue =>
      console.error('- ' + issue.path + ' [' + issue.rule + ']'),
    );
    process.exitCode = 1;
    return;
  }

  console.log(
    'Repository secret scan passed (' +
      result.currentFileCount +
      ' current files).',
  );
}

if (require.main === module) main();

module.exports = {
  assignmentValue,
  assignmentEntries,
  commandAssignmentValue,
  findUncoveredSecretNames,
  historyContentIssues,
  historyContentPatterns,
  isPlaceholder,
  isSecretLikeName,
  scanContents,
  scanFiles,
  scanPathNames,
  secretNames,
  sensitivePathRule,
  unclassifiedSecretAssignmentNames,
  verify,
};
