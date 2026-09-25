import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';
import {test} from 'node:test';
import vm from 'node:vm';

const sources = Object.fromEntries(await Promise.all(['records', 'transfer'].map(async name => [
    name,
    await readFile(new URL(`../../public/admin/assets/js/course-studio-bunny-upload-${name}.js`, import.meta.url), 'utf8'),
])));
const file = {name: 'درس.mp4', size: 4, type: 'video/mp4', lastModified: 1000};

function storage() {
    const values = {};
    Object.defineProperties(values, {
        getItem: {value: key => values[key] ?? null},
        setItem: {value: (key, value) => { values[key] = String(value); }},
        removeItem: {value: key => { delete values[key]; }},
    });
    return values;
}

function runtime(extra = {}) {
    const globals = {
        setTimeout, clearTimeout, crypto: webcrypto, TextEncoder,
        btoa, URL, AbortController, performance,
        sessionStorage: storage(), localStorage: storage(),
        ...extra,
    };
    globals.window = globals;
    const context = vm.createContext(globals);
    for (const source of Object.values(sources)) vm.runInContext(source, context);
    return context;
}

function recordsFor(context, overrides = {}) {
    return context.RoknBunnyUploadRecords.create({
        ownerId: 'admin-1', courseId: 'course-3', sectionId: () => 'new',
        currentAuthoringVersion: () => 7, ...overrides,
    });
}

function savedRecord(records) {
    return {
        fingerprint: records.fingerprint(file), idempotencyKey: records.newOperationId(),
        claim: 'signed-claim', claimExpiresAt: '2099-01-01T00:00:00Z',
        headers: {AuthorizationSignature: 'old-signature'}, authorizationDeadline: 12345,
        uploadUrl: 'https://upload.example/video-1',
    };
}

test('resume survives reload but transient authorization is always renewed', async () => {
    const context = runtime();
    const records = recordsFor(context);
    await records.ready;
    assert.equal(records.read(file), null);
    const record = savedRecord(records);
    records.save(record);
    const reloaded = recordsFor(context);
    const restored = reloaded.read(file);
    assert.equal(restored.idempotencyKey, record.idempotencyKey);
    assert.equal(restored.uploadUrl, record.uploadUrl);
    assert.equal(restored.headers, null);
    assert.equal(restored.authorizationDeadline, 0);
    assert.equal(restored.version, 3);
    assert.equal(restored.authoringVersion, 7);
});

test('owner, section, file, tab and authoring revision cannot adopt another resume record', () => {
    const context = runtime();
    const records = recordsFor(context);
    records.read(file);
    records.save(savedRecord(records));
    const originalKey = Object.keys(context.localStorage)[0];
    assert.equal(recordsFor(context, {ownerId: 'admin-2'}).read(file), null);
    assert.equal(recordsFor(context, {sectionId: () => 'section-2'}).read(file), null);
    assert.equal(recordsFor(context).read({...file, lastModified: 2000}), null);
    const otherTab = runtime({localStorage: context.localStorage});
    assert.equal(recordsFor(otherTab).read(file), null);
    assert.ok(context.localStorage.getItem(originalKey));
    assert.equal(recordsFor(context, {currentAuthoringVersion: () => 8}).read(file), null);
    assert.equal(context.localStorage.getItem(originalKey), null);
});

test('expired claims and abandoned preparations are discarded, not renewed', () => {
    for (const kind of ['claim', 'preparation']) {
        const context = runtime();
        const records = recordsFor(context);
        records.read(file);
        records.save(savedRecord(records));
        const key = Object.keys(context.localStorage)[0];
        const value = JSON.parse(context.localStorage.getItem(key));
        if (kind === 'claim') value.claimExpiresAt = '2000-01-01T00:00:00Z';
        else { value.claim = null; value.savedAt = Date.now() - 16 * 60 * 1000; }
        context.localStorage.setItem(key, JSON.stringify(value));
        assert.equal(records.read(file), null, kind);
        assert.equal(context.localStorage.getItem(key), null, kind);
    }
});

test('legacy key is migrated only when the full resume context matches', () => {
    const context = runtime();
    const records = recordsFor(context);
    records.read(file);
    records.save(savedRecord(records));
    const key = Object.keys(context.localStorage)[0];
    const legacyKey = 'rokn:bunny-upload:admin-1:course-3:new';
    context.localStorage.setItem(legacyKey, context.localStorage.getItem(key));
    context.localStorage.removeItem(key);
    assert.equal(records.read(file).claim, 'signed-claim');
    assert.equal(context.localStorage.getItem(legacyKey), null);
    assert.ok(context.localStorage.getItem(key));
});

test('switching form context keeps recovery state; commit removes only the selected record', () => {
    const context = runtime();
    const records = recordsFor(context);
    records.read(file);
    records.save(savedRecord(records));
    const firstKey = Object.keys(context.localStorage)[0];
    records.release();
    assert.ok(context.localStorage.getItem(firstKey));
    const secondFile = {...file, name: 'next.mp4'};
    records.read(secondFile);
    records.save({...savedRecord(records), fingerprint: records.fingerprint(secondFile)});
    assert.equal(Object.keys(context.localStorage).length, 2);
    records.clear();
    assert.deepEqual(Object.keys(context.localStorage), [firstKey]);
});

function transferFixture({headStatus = 200, ready = Promise.resolve(), allocationError} = {}) {
    const events = [];
    let stored = null;
    let allocations = 0;
    const records = {
        ready, fingerprint: () => 'fingerprint', newOperationId: () => `attempt-${allocations + 1}`,
        read: () => stored,
        save: record => { stored = record; events.push('save'); },
        clear: () => { stored = null; events.push('clear'); },
    };
    const context = runtime({fetch: async (_url, options) => {
        events.push(options.method);
        return options.method === 'POST'
            ? {ok: true, headers: new Headers({Location: '/video-1'})}
            : {ok: headStatus === 200, status: headStatus, headers: new Headers({'Upload-Offset': '4'})};
    }});
    // The engine must work without a page or browser storage, even at runtime.
    delete context.localStorage;
    delete context.sessionStorage;
    const transfer = context.RoknBunnyUploadTransfer.create({
        records, currentAuthoringVersion: () => 7, sectionId: () => 'new',
        initUrl: '/init', renewUrl: '/renew', csrf: 'csrf',
        apiRequest: async (url, options) => {
            events.push(url);
            allocations += 1;
            assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf');
            assert.ok(stored.idempotencyKey, 'persist identity before allocation');
            if (allocationError) throw allocationError;
            return {data: {
                claim: 'signed-claim', upload_endpoint: 'https://upload.example/tus',
                headers: {AuthorizationSignature: 'signature'},
                authorization_expires_in_seconds: 300,
                claim_expires_at: '2099-01-01T00:00:00Z',
            }};
        },
        onProgress: (_message, percent) => events.push(`progress:${percent}`),
        onClaim: claim => events.push(`claim:${claim}`),
    });
    return {transfer, events, allocations: () => allocations};
}

test('upload engine owns allocation and TUS completion without a DOM or localStorage', async () => {
    const fixture = transferFixture();
    assert.equal(await fixture.transfer.upload(file, 'المقطع'), 'signed-claim');
    assert.equal(fixture.allocations(), 1);
    assert.ok(fixture.events.indexOf('save') < fixture.events.indexOf('/init'));
    assert.ok(fixture.events.indexOf('POST') < fixture.events.indexOf('HEAD'));
    assert.equal(fixture.events.at(-1), 'progress:100');
});

test('cancellation while waiting for tab identity cannot allocate or report completion', async () => {
    let release;
    const ready = new Promise(resolve => { release = resolve; });
    const fixture = transferFixture({ready});
    const result = fixture.transfer.upload(file, 'المقطع');
    fixture.transfer.stop();
    release();
    await assert.rejects(result, error => error.cancelled === true);
    assert.deepEqual(fixture.events, []);
    fixture.transfer.reset();
    assert.equal(await fixture.transfer.upload(file, 'المقطع'), 'signed-claim');
});

test('missing provider upload restarts once, then fails without an allocation loop', async () => {
    const fixture = transferFixture({headStatus: 404});
    await assert.rejects(fixture.transfer.upload(file, 'المقطع'),
        error => error.code === 'bunny_remote_upload_unavailable');
    assert.equal(fixture.allocations(), 2);
    assert.equal(fixture.events.filter(event => event === 'clear').length, 2);
    assert.equal(fixture.events.includes('progress:100'), false);
});

test('draft conflicts and terminal claims are not treated as transient allocation failures', async () => {
    const error = Object.assign(new Error('revision changed'), {status: 409, code: 'course_revision_changed'});
    const fixture = transferFixture({allocationError: error});
    await assert.rejects(fixture.transfer.upload(file, 'المقطع'), value => value === error);
    assert.equal(fixture.transfer.isResumablePreparation(error), false);
    assert.equal(fixture.transfer.isResumablePreparation({code: 'bunny_upload_claim_unavailable'}), false);
    assert.equal(fixture.transfer.isResumablePreparation({code: 'bunny_upload_allocation_in_progress'}), true);
    assert.equal(fixture.allocations(), 1);
});
