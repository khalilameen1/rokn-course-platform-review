'use strict';

const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scanner = require('../verify-repository-secrets');
const dbPasswordName = ['DB', 'PASSWORD'].join('_');
const unknownSecretName = ['NEW_PROVIDER_CLIENT', 'SECRET'].join('_');

function withDirectory(run) {
  const directory = fs.mkdtempSync(
    path.join(os.tmpdir(), 'rokn-mobile-secret-scan-'),
  );
  try {
    run(directory);
  } finally {
    fs.rmSync(directory, {recursive: true, force: true});
  }
}

test('history queries inspect shared blobs once without omitting revisions or binary files', () => {
  const source = fs.readFileSync(
    path.join(__dirname, '../verify-repository-secrets.js'),
    'utf8',
  );
  const calls = [];
  const treeQueries = [];
  const blobQueries = [];
  const oid = 'a'.repeat(40);
  const context = {
    module: {exports: {}},
    Buffer,
    __dirname: path.resolve(__dirname, '..'),
  };
  context.require = Object.assign(
    name =>
      name === 'node:child_process'
        ? {
            execFileSync: (command, args, options) => {
              if (args.includes('ls-tree')) {
                treeQueries.push(args[6]);
                return Buffer.from(`100644 blob ${oid}       5\tshared.txt\0`);
              }
              blobQueries.push(options.input.toString());
              return Buffer.from(`${oid} blob 5\nplain\n`);
            },
            spawnSync: (command, args, options) => {
              calls.push({command, args, options});
              return {status: 1, stdout: '', stderr: ''};
            },
          }
        : require(name),
    {main: {}},
  );
  vm.runInNewContext(source, context);
  const subject = context.module.exports;
  const commits = Array.from({length: 101}, (_, index) =>
    index.toString(16).padStart(40, '0'),
  );
  assert.equal(subject.historyContentIssues('/fixture', commits).length, 0);
  assert.deepEqual(treeQueries, commits);
  assert.deepEqual(blobQueries, [oid + '\n']);
  assert.equal(calls.length, subject.historyContentPatterns.length);
  assert.ok(!fs.existsSync(calls[0].args[1]));
  for (const [, pattern] of subject.historyContentPatterns) {
    const queries = calls.filter(call => call.args.includes(pattern));
    assert.deepEqual(
      queries.flatMap(call => [...call.args].filter(arg => arg === oid)),
      [oid],
    );
    for (const {command, args, options} of queries) {
      assert.equal(command, 'git');
      assert.ok(args.includes('--threads=1'));
      assert.ok(args.includes('-l'));
      assert.ok(args.includes('--no-index'));
      assert.ok(args.includes('-a'));
      assert.ok(!args.includes('-I'));
      assert.equal(options.env, undefined);
    }
  }
});

test('history inspection fails closed and cleans its own workspace on Git failure', () => {
  const source = fs.readFileSync(
    path.join(__dirname, '../verify-repository-secrets.js'),
    'utf8',
  );
  const oid = 'a'.repeat(40);
  let workspace;
  const context = {
    module: {exports: {}},
    Buffer,
    __dirname: path.resolve(__dirname, '..'),
  };
  context.require = Object.assign(
    name =>
      name === 'node:child_process'
        ? {
            execFileSync: (command, args) =>
              Buffer.from(
                args.includes('ls-tree')
                  ? `100644 blob ${oid}       5\tshared.txt\0`
                  : `${oid} blob 5\nplain\n`,
              ),
            spawnSync: (command, args) => {
              workspace = args[1];
              return {status: 2, stderr: 'private contents must not be logged'};
            },
          }
        : require(name),
    {main: {}},
  );
  vm.runInNewContext(source, context);
  assert.throws(
    () =>
      context.module.exports.historyContentIssues('/fixture', ['b'.repeat(40)]),
    error =>
      /could not inspect Git blobs/.test(error.message) &&
      !error.message.includes('private contents'),
  );
  assert.ok(workspace);
  assert.equal(fs.existsSync(workspace), false);
});

test('reports private material without returning its value', () => {
  withDirectory(directory => {
    const fixture =
      '-----BEGIN' +
      ' PRIVATE KEY-----\nnot-a-real-key\n-----END' +
      ' PRIVATE KEY-----';
    fs.writeFileSync(path.join(directory, 'credentials.txt'), fixture);

    const issues = scanner.scanFiles(directory, ['credentials.txt']);

    assert.deepEqual(issues, [
      {path: 'credentials.txt', rule: 'private_key_material'},
    ]);
    assert.doesNotMatch(JSON.stringify(issues), /not-a-real-key/);
  });
});

test('scans large files and mixed binary content without waiving them', () => {
  withDirectory(directory => {
    const contents = Buffer.concat([
      Buffer.from('DB_' + 'PASSWORD=ordinaryProductionPassword123!\n'),
      Buffer.alloc(2_100_000, 0),
    ]);
    fs.writeFileSync(path.join(directory, 'large-config.bin'), contents);

    assert.deepEqual(scanner.scanFiles(directory, ['large-config.bin']), [
      {
        path: 'large-config.bin',
        rule: 'non_placeholder_secret_assignment',
      },
    ]);
  });
});

test('covers minified project secrets and modern provider credentials', () => {
  const contents = [
    JSON.stringify({
      ['OPENROUTER_' + 'API_KEY']: 'ordinaryProductionPassword123!',
    }),
    '-----BEGIN ' + 'ENCRYPTED PRIVATE KEY-----',
    'ASIA' + 'A'.repeat(16),
    'github_' + 'pat_' + 'A'.repeat(24),
  ].join('\n');

  assert.deepEqual(scanner.scanContents('config.json', contents), [
    'private_key_material',
    'aws_access_key',
    'github_access_token',
    'non_placeholder_secret_assignment',
  ]);
});

test('covers shell, source-code and Compose secret assignments', () => {
  const assignments = [
    'export DB_' + 'PASSWORD=ordinaryProductionPassword123!',
    '- DB_' + 'PASSWORD=ordinaryProductionPassword123!',
    'const DB_' + "PASSWORD = 'ordinaryProductionPassword123!';",
    'env DB_' + 'PASSWORD=ordinaryProductionPassword123! command',
  ];

  assignments.forEach(contents =>
    assert.deepEqual(scanner.scanContents('config.txt', contents), [
      'non_placeholder_secret_assignment',
    ]),
  );
  assert.deepEqual(
    scanner.scanContents(
      'config.txt',
      'env DB_' + 'PASSWORD=${DB_PASSWORD} command',
    ),
    [],
  );
});

test('rejects concatenated secret values and accepts only full references', () => {
  const unsafe = [
    `const ${dbPasswordName} = "\${DB_PASSWORD}" + "ordinaryProductionPassword123!";`,
    `const ${dbPasswordName} = "..." + "ordinaryProductionPassword123!";`,
    `env ${dbPasswordName}="\${DB_PASSWORD}"ordinaryProductionPassword123! command`,
    `export ${dbPasswordName}="..."ordinaryProductionPassword123!`,
    `const ${dbPasswordName} =\n  "ordinaryProductionPassword123!";`,
    `process.env.${dbPasswordName} = "ordinaryProductionPassword123!";`,
    `DB_HOST=localhost ${dbPasswordName}=ordinaryProductionPassword123! command`,
    `env DB_HOST=localhost ${dbPasswordName}=ordinaryProductionPassword123! command`,
    `$env:${dbPasswordName} = "ordinaryProductionPassword123!"`,
    `process.env["${dbPasswordName}"] = "ordinaryProductionPassword123!";`,
    `process.env['${dbPasswordName}'] = 'ordinaryProductionPassword123!';`,
    `"${dbPasswordName}":\n  "ordinaryProductionPassword123!"`,
    `${dbPasswordName}:\n  ordinaryProductionPassword123!`,
  ];
  unsafe.forEach(contents =>
    assert.deepEqual(scanner.scanContents('config.txt', contents), [
      'non_placeholder_secret_assignment',
    ]),
  );

  [
    `const ${dbPasswordName} = process.env.DB_PASSWORD;`,
    `export ${dbPasswordName}=$DB_PASSWORD`,
    `env ${dbPasswordName}="\${DB_PASSWORD}" command`,
    `export ${dbPasswordName}="\${DB_PASSWORD}" # inherited`,
    `process.env.${dbPasswordName} = process.env.${dbPasswordName}; // inherited`,
  ].forEach(contents =>
    assert.deepEqual(scanner.scanContents('config.txt', contents), []),
  );
});

test('fails closed on an empty secret operator outside reviewed examples', () => {
  const contents = `${dbPasswordName}=\n`;
  assert.deepEqual(scanner.scanContents('runtime.env', contents), [
    'non_placeholder_secret_assignment',
  ]);
  assert.deepEqual(scanner.scanContents('.env.example', contents), []);
});

test('rejects sensitive paths and non-placeholder assignments', () => {
  withDirectory(directory => {
    fs.writeFileSync(
      path.join(directory, '.env.local'),
      'DB_' + 'PASSWORD=latestProductionPassword123!\n',
    );

    assert.deepEqual(scanner.scanFiles(directory, ['.env.local']), [
      {path: '.env.local', rule: 'environment_file'},
      {path: '.env.local', rule: 'non_placeholder_secret_assignment'},
    ]);
  });
});

test('does not treat root as a placeholder password', () => {
  assert.equal(scanner.isPlaceholder('root'), false);
});

test('allows only exact reviewed secret references and placeholders', () => {
  const accepted = [
    '...',
    '<PLACEHOLDER>',
    '${{ secrets.ROKN_SMOKE_PASSWORD }}',
    '${{ vars.ROKN_SMOKE_PASSWORD }}',
    '${ROKN_SMOKE_PASSWORD}',
    '/run/secrets/firebase-service-account.json',
    '/var/run/secrets/firebase-service-account.json',
  ];

  accepted.forEach(value => assert.equal(scanner.isPlaceholder(value), true));
  [
    'prefix-${{ secrets.ROKN_SMOKE_PASSWORD }}',
    '${{ secrets.ROKN_SMOKE_PASSWORD }}-suffix',
    '/run/secrets/firebase-service-account.json.backup!',
  ].forEach(value => assert.equal(scanner.isPlaceholder(value), false));
});

test('requires every declared or referenced secret name to be classified', () => {
  const repositoryRoot = path.resolve(__dirname, '..', '..');
  const sources = [
    fs.readFileSync(path.join(repositoryRoot, '.env.example'), 'utf8'),
    fs.readFileSync(
      path.join(repositoryRoot, '..', '.github', 'workflows', 'mobile-ci.yml'),
      'utf8',
    ),
  ].join('\n');
  assert.deepEqual(scanner.findUncoveredSecretNames(sources), []);
  [
    unknownSecretName + '=ordinaryProductionPassword123!',
    'export ' + unknownSecretName + '=ordinaryProductionPassword123!',
    '- ' + unknownSecretName + '=ordinaryProductionPassword123!',
    'const ' + unknownSecretName + " = 'ordinaryProductionPassword123!';",
    'env ' + unknownSecretName + '=ordinaryProductionPassword123! command',
  ].forEach(contents => {
    assert.deepEqual(scanner.findUncoveredSecretNames(contents), [
      'NEW_PROVIDER_CLIENT_SECRET',
    ]);
    assert.deepEqual(scanner.scanContents('config.txt', contents), [
      'unclassified_secret_name',
    ]);
  });
});

test('allows reviewed example placeholders and audited Firebase paths', () => {
  withDirectory(directory => {
    fs.mkdirSync(path.join(directory, 'android', 'app'), {recursive: true});
    fs.writeFileSync(
      path.join(directory, '.env.example'),
      'APP_' + 'KEY=\nDB_' + 'PASSWORD=replace-me\n',
    );
    fs.writeFileSync(
      path.join(directory, 'android', 'app', 'google-services.json'),
      JSON.stringify({
        project_info: {project_id: 'public-client-id'},
        client: [{api_key: [{current_key: 'AIza' + 'A'.repeat(35)}]}],
      }),
    );

    assert.deepEqual(
      scanner.scanFiles(directory, [
        '.env.example',
        'android/app/google-services.json',
      ]),
      [],
    );
  });
});

test('rejects a Google API key outside the audited mobile client configs', () => {
  withDirectory(directory => {
    fs.writeFileSync(
      path.join(directory, 'unexpected-config.txt'),
      'key=' + 'AIza' + 'A'.repeat(35),
    );

    assert.deepEqual(scanner.scanFiles(directory, ['unexpected-config.txt']), [
      {path: 'unexpected-config.txt', rule: 'google_api_key'},
    ]);
  });
});

test('detects credential-shaped paths in repository history inventories', () => {
  assert.deepEqual(
    scanner.scanPathNames([
      'docs/architecture.md',
      'secrets/release-signing.p12',
      'ops/.env_copy',
    ]),
    [
      {path: 'ops/.env_copy', rule: 'environment_file'},
      {path: 'secrets/release-signing.p12', rule: 'private_key_file'},
    ],
  );
});

test('detects secret content that was deleted from the current tree', () => {
  withDirectory(directory => {
    const git = (...args) =>
      execFileSync('git', args, {
        cwd: directory,
        stdio: 'ignore',
      });

    git('init', '--quiet');
    git('config', 'user.email', 'security-test@rokn.invalid');
    git('config', 'user.name', 'Rokn Security Test');
    fs.writeFileSync(
      path.join(directory, 'old-credentials.txt'),
      Buffer.concat([
        Buffer.from([0, 255, 254]),
        Buffer.from(
          'سياق عربي\n' +
            '-----BEGIN' +
            ' PRIVATE KEY-----\nnot-a-real-key\n-----END' +
            ' PRIVATE KEY-----',
        ),
      ]),
    );
    fs.writeFileSync(
      path.join(directory, 'old-config.txt'),
      `process.env["${dbPasswordName}"] = "ordinaryProductionPassword123!";\n`,
    );
    fs.writeFileSync(
      path.join(directory, 'old-unclassified.txt'),
      `export ${unknownSecretName}=ordinaryProductionPassword123!\n`,
    );
    git('add', 'old-credentials.txt', 'old-config.txt', 'old-unclassified.txt');
    git('commit', '--quiet', '-m', 'historical fixture');
    fs.unlinkSync(path.join(directory, 'old-credentials.txt'));
    fs.unlinkSync(path.join(directory, 'old-config.txt'));
    fs.unlinkSync(path.join(directory, 'old-unclassified.txt'));
    git('add', '--all');
    git('commit', '--quiet', '-m', 'delete historical fixture');

    const result = scanner.verify({root: directory, includeHistory: true});

    assert.deepEqual(result.issues, [
      {
        path: 'history:old-config.txt',
        rule: 'non_placeholder_secret_assignment',
      },
      {
        path: 'history:old-credentials.txt',
        rule: 'private_key_material',
      },
      {
        path: 'history:old-unclassified.txt',
        rule: 'unclassified_secret_name',
      },
    ]);
  });
});

test('history scan resolves blobs when mobile is nested in a monorepo', () => {
  withDirectory(directory => {
    const mobileRoot = path.join(directory, 'mobile');
    fs.mkdirSync(mobileRoot, {recursive: true});
    const git = (...args) =>
      execFileSync('git', args, {
        cwd: directory,
        stdio: 'ignore',
      });

    git('init');
    git('config', 'user.email', 'ci@example.com');
    git('config', 'user.name', 'CI');
    fs.writeFileSync(path.join(mobileRoot, '.env.example'), 'APP_KEY=\n');
    git('add', '.');
    git('commit', '-m', 'fixture');

    assert.deepEqual(
      scanner.verify({root: mobileRoot, includeHistory: true}).issues,
      [],
    );
  });
});

test('allows an audited Firebase client config in repository history', () => {
  withDirectory(directory => {
    const git = (...args) =>
      execFileSync('git', args, {
        cwd: directory,
        stdio: 'ignore',
      });

    git('init', '--quiet');
    git('config', 'user.email', 'security-test@rokn.invalid');
    git('config', 'user.name', 'Rokn Security Test');
    fs.mkdirSync(path.join(directory, 'android', 'app'), {recursive: true});
    fs.writeFileSync(
      path.join(directory, 'android', 'app', 'google-services.json'),
      JSON.stringify({
        project_info: {project_id: 'public-client-id'},
        client: [{api_key: [{current_key: 'AIza' + 'A'.repeat(35)}]}],
      }),
    );
    git('add', 'android/app/google-services.json');
    git('commit', '--quiet', '-m', 'audited public Firebase config');

    const result = scanner.verify({root: directory, includeHistory: true});

    assert.deepEqual(result.issues, []);
  });
});

test('historical public-config exceptions do not follow identical blobs copied on another branch', () => {
  withDirectory(directory => {
    const git = (...args) =>
      execFileSync('git', args, {cwd: directory, stdio: 'ignore'});
    git('init', '--quiet');
    git('config', 'user.email', 'security-test@rokn.invalid');
    git('config', 'user.name', 'Rokn Security Test');
    fs.mkdirSync(path.join(directory, 'mobile', 'android', 'app'), {
      recursive: true,
    });
    fs.mkdirSync(path.join(directory, 'backend'));
    const contents = JSON.stringify({key: 'AIza' + 'A'.repeat(35)});
    fs.writeFileSync(
      path.join(directory, 'mobile', 'android', 'app', 'google-services.json'),
      contents,
    );
    // The scanner must retain mobile scope, not inspect backend history.
    fs.writeFileSync(path.join(directory, 'backend', 'config.txt'), contents);
    git('add', '.');
    git('commit', '--quiet', '-m', 'public client config');
    git('branch', 'clean-head');
    git('checkout', '--quiet', '-b', 'historical-copy');
    fs.writeFileSync(
      path.join(directory, 'mobile', 'copied إعدادات.txt'),
      contents,
    );
    fs.writeFileSync(
      path.join(directory, 'mobile', 'archived signing.key'),
      'plain',
    );
    git('add', '.');
    git('commit', '--quiet', '-m', 'copy fixture on another branch');
    git('checkout', '--quiet', 'clean-head');
    assert.deepEqual(
      scanner.verify({
        root: path.join(directory, 'mobile'),
        includeHistory: true,
      }).issues,
      [
        {path: 'history:archived signing.key', rule: 'private_key_file'},
        {path: 'history:copied إعدادات.txt', rule: 'google_api_key'},
      ],
    );
  });
});
