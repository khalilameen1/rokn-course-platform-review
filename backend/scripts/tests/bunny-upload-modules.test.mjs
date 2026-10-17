import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';
import {test} from 'node:test';
import vm from 'node:vm';

// The real tus-js-client browser build runs inside the sandbox; only the
// network (XMLHttpRequest) and the Rokn API are faked.
const tusSource = await readFile(new URL('../../node_modules/tus-js-client/dist/tus.min.js', import.meta.url), 'utf8');
const sources = Object.fromEntries(await Promise.all(['records', 'transfer', 'queue'].map(async name => [
    name,
    await readFile(new URL(`../../public/admin/assets/js/course-studio-bunny-upload-${name}.js`, import.meta.url), 'utf8'),
])));
const file = {name: 'درس.mp4', size: 4, type: 'video/mp4', lastModified: 1000};
const videoFile = (name = 'درس.mp4', size = 4, type = 'video/mp4') => new File([new Uint8Array(size)], name, {type, lastModified: 1000});

function storage() {
    const values = {};
    Object.defineProperties(values, {
        getItem: {value: key => values[key] ?? null},
        setItem: {value: (key, value) => { values[key] = String(value); }},
        removeItem: {value: key => { delete values[key]; }},
    });
    return values;
}

class UnrefBroadcastChannel extends BroadcastChannel {
    constructor(name) {
        super(name);
        this.unref();
    }
}

// A test clock: retry and probe delays (< 100s) run on the next tick; longer
// watchdogs only run when a test fires them explicitly.
function testClock() {
    const parked = new Map();
    let nextId = 1;
    return {
        setTimeout: (callback, delay = 0) => {
            if (delay < 100000) return setTimeout(callback, 0);
            const id = `parked-${nextId++}`;
            parked.set(id, callback);
            return id;
        },
        clearTimeout: id => (parked.delete(id) ? undefined : clearTimeout(id)),
        fireParked: () => {
            const callbacks = [...parked.values()];
            parked.clear();
            callbacks.forEach(callback => callback());
        },
    };
}

function runtime(extra = {}) {
    const clock = testClock();
    const globals = {
        setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout, fireParkedTimers: clock.fireParked,
        crypto: webcrypto, TextEncoder, btoa, URL, AbortController, performance, Blob, console,
        sessionStorage: storage(), localStorage: storage(),
        ...extra,
    };
    globals.window = globals;
    const context = vm.createContext(globals);
    vm.runInContext(tusSource, context);
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

test('two upload owners on one page share the tab identity instead of evicting each other', async () => {
    // Real timers: the 80ms probe window must see the sibling's reply.
    const context = runtime({BroadcastChannel: UnrefBroadcastChannel, setTimeout});
    const form = recordsFor(context);
    const queue = recordsFor(context);
    await Promise.all([form.ready, queue.ready]);
    form.read(file);
    form.save(savedRecord(form));
    assert.equal(queue.read(file).claim, 'signed-claim');
    assert.equal(Object.keys(context.sessionStorage).length, 1);
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

/*
 * A scripted TUS server behind a fake XMLHttpRequest. `respond` may return
 * {status, headers}, 'network-error' or 'hang'. By default it implements
 * creation, HEAD offset lookup and PATCH append for every upload URL.
 */
function tusServer(respond = () => null) {
    const log = [];
    const offsets = new Map();
    let created = 0;
    const live = new Set();
    const standard = (xhr, body) => {
        if (xhr.method === 'POST') {
            created += 1;
            const location = `/tus/video-${created}`;
            offsets.set(new URL(location, xhr.url).toString(), 0);
            return {status: 201, headers: {Location: location}};
        }
        if (!offsets.has(xhr.url)) return {status: 404};
        if (xhr.method === 'HEAD') {
            return {status: 200, headers: {'Upload-Offset': String(offsets.get(xhr.url)), 'Upload-Length': '4'}};
        }
        const offset = Number(xhr.headers['Upload-Offset']);
        assert.equal(offset, offsets.get(xhr.url), 'PATCH continues from the server offset');
        const next = offset + (body?.size || 0);
        offsets.set(xhr.url, next);
        return {status: 204, headers: {'Upload-Offset': String(next)}};
    };
    // Models the browser events tus-js-client and our stall watchdog use:
    // onload/onerror, upload progress listeners and loadend (also on abort).
    const emitter = () => {
        const listeners = {};
        return {
            addEventListener: (type, listener) => { (listeners[type] ||= []).push(listener); },
            dispatch: (type, event = {}) => (listeners[type] || []).forEach(listener => listener(event)),
        };
    };
    class FakeXMLHttpRequest {
        constructor() {
            const events = emitter();
            const uploadEvents = emitter();
            this.addEventListener = events.addEventListener;
            this.dispatch = events.dispatch;
            this.upload = {addEventListener: uploadEvents.addEventListener, dispatch: uploadEvents.dispatch};
            this.headers = {};
            this.status = 0;
            this.responseText = '';
            this.responseHeaders = {};
        }
        open(method, url) { this.method = method; this.url = url; }
        setRequestHeader(name, value) { this.headers[name] = value; }
        getResponseHeader(name) {
            const key = Object.keys(this.responseHeaders).find(header => header.toLowerCase() === name.toLowerCase());
            return key === undefined ? null : this.responseHeaders[key];
        }
        send(body) {
            log.push({method: this.method, url: this.url, headers: {...this.headers}});
            live.add(this);
            setTimeout(() => {
                if (this.aborted) return;
                const scripted = respond(this, body, log.length);
                if (scripted === 'hang') return;
                live.delete(this);
                if (scripted === 'network-error') {
                    this.onerror?.(new Error('network'));
                    this.dispatch('loadend');
                    return;
                }
                const result = scripted || standard(this, body);
                this.status = result.status;
                this.responseHeaders = result.headers || {};
                if (body?.size) {
                    const progress = {lengthComputable: true, loaded: body.size};
                    this.upload.onprogress?.(progress);
                    this.upload.dispatch('progress', progress);
                }
                this.onload?.();
                this.dispatch('loadend');
            }, 0);
        }
        abort() {
            this.aborted = true;
            live.delete(this);
            this.dispatch('loadend');
        }
    }
    return {FakeXMLHttpRequest, log, offsets, live, methods: () => log.map(entry => entry.method)};
}

function transferFixture({server = tusServer(), ready = Promise.resolve(), allocationError,
    renewError, stored: initial = null, authoringVersion = () => 7} = {}) {
    const events = [];
    let stored = initial;
    let allocations = 0;
    let renewals = 0;
    const records = {
        ready, fingerprint: () => 'fingerprint', newOperationId: () => `attempt-${allocations + 1}`,
        read: () => stored,
        save: record => { stored = record; events.push('save'); },
        clear: () => { stored = null; events.push('clear'); },
    };
    const context = runtime({XMLHttpRequest: server.FakeXMLHttpRequest});
    // The engine must work without a page or browser storage, even at runtime.
    delete context.localStorage;
    delete context.sessionStorage;
    const authorization = {
        headers: {AuthorizationSignature: 'signature', AuthorizationExpire: '1999999999', VideoId: 'video-guid', LibraryId: '42'},
        authorization_expires_in_seconds: 300,
        claim_expires_at: '2099-01-01T00:00:00Z',
    };
    const transfer = context.RoknBunnyUploadTransfer.create({
        records, currentAuthoringVersion: authoringVersion, sectionId: () => 'new',
        initUrl: '/init', renewUrl: '/renew', csrf: 'csrf',
        apiRequest: async (url, options) => {
            events.push(url);
            assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf');
            if (url === '/renew') {
                renewals += 1;
                if (renewError) throw renewError;
                return {data: {...authorization, claim: 'renewed-claim'}};
            }
            allocations += 1;
            assert.ok(stored.idempotencyKey, 'persist identity before allocation');
            if (allocationError) throw allocationError;
            return {data: {...authorization, claim: 'signed-claim', upload_endpoint: 'https://video.bunnycdn.com/tusupload'}};
        },
        onProgress: (_message, percent) => events.push(`progress:${Math.round(percent)}`),
        onClaim: claim => events.push(`claim:${claim}`),
    });
    return {transfer, events, server, context, stored: () => stored,
        allocations: () => allocations, renewals: () => renewals};
}

const decodeMetadata = header => Object.fromEntries(header.split(',').map(pair => {
    const [key, value] = pair.split(' ');
    return [key, Buffer.from(value, 'base64').toString('utf8')];
}));

test('a new upload allocates once, creates the TUS upload with Bunny headers and metadata, then patches', async () => {
    const fixture = transferFixture();
    assert.equal(await fixture.transfer.upload(videoFile(), 'المقطع'), 'signed-claim');
    assert.equal(fixture.allocations(), 1);
    assert.deepEqual(fixture.server.methods(), ['POST', 'PATCH']);
    const [create, patch] = fixture.server.log;
    assert.equal(create.url, 'https://video.bunnycdn.com/tusupload');
    for (const header of ['AuthorizationSignature', 'AuthorizationExpire', 'VideoId', 'LibraryId']) {
        assert.ok(create.headers[header], header);
        assert.ok(patch.headers[header], header);
    }
    assert.equal(create.headers['Tus-Resumable'], '1.0.0');
    assert.equal(create.headers['Upload-Length'], '4');
    assert.deepEqual(decodeMetadata(create.headers['Upload-Metadata']),
        {filename: 'درس.mp4', filetype: 'video/mp4', title: 'المقطع'});
    assert.ok(fixture.events.indexOf('save') < fixture.events.indexOf('/init'));
    assert.equal(fixture.stored().uploadUrl, 'https://video.bunnycdn.com/tus/video-1');
    assert.equal(fixture.events.at(-1), 'progress:100');
});

test('a reloaded page resumes the stored TUS URL from the server offset after renewing authorization', async () => {
    const server = tusServer();
    server.offsets.set('https://video.bunnycdn.com/tus/video-1', 2);
    const fixture = transferFixture({server, stored: {
        fingerprint: 'fingerprint', idempotencyKey: 'attempt-1', authoringVersion: 7,
        endpoint: 'https://video.bunnycdn.com/tusupload', claim: 'signed-claim',
        headers: null, authorizationDeadline: 0, uploadUrl: 'https://video.bunnycdn.com/tus/video-1',
    }});
    assert.equal(await fixture.transfer.upload(videoFile(), 'المقطع'), 'renewed-claim');
    assert.equal(fixture.allocations(), 0);
    assert.equal(fixture.renewals(), 1);
    assert.deepEqual(fixture.server.methods(), ['HEAD', 'PATCH']);
    assert.equal(fixture.server.log[1].headers['Upload-Offset'], '2');
    assert.equal(server.offsets.get('https://video.bunnycdn.com/tus/video-1'), 4);
});

test('a dropped PATCH is retried by tus-js-client and resumes the same upload', async () => {
    const server = tusServer((xhr, _body, count) => (xhr.method === 'PATCH' && count === 2 ? 'network-error' : null));
    const fixture = transferFixture({server});
    assert.equal(await fixture.transfer.upload(videoFile(), 'المقطع'), 'signed-claim');
    assert.deepEqual(fixture.server.methods(), ['POST', 'PATCH', 'HEAD', 'PATCH']);
    assert.equal(fixture.allocations(), 1);
});

test('a stalled request is abandoned by the watchdog and resumed instead of hanging', async () => {
    let stall = true;
    const server = tusServer(xhr => (xhr.method === 'PATCH' && stall ? 'hang' : null));
    const fixture = transferFixture({server});
    const running = fixture.transfer.upload(videoFile(), 'المقطع');
    while (!server.methods().includes('PATCH')) await new Promise(resolve => setTimeout(resolve, 0));
    stall = false;
    fixture.context.fireParkedTimers();
    assert.equal(await running, 'signed-claim');
    assert.equal(server.live.size, 0, 'the stalled request was aborted');
    assert.deepEqual(server.methods(), ['POST', 'PATCH', 'HEAD', 'PATCH']);
    assert.equal(fixture.allocations(), 1);
});

test('an expired signature renews through our API and keeps the resumable URL', async () => {
    const server = tusServer((xhr, _body, count) => (xhr.method === 'PATCH' && count === 2 ? {status: 401} : null));
    const fixture = transferFixture({server});
    assert.equal(await fixture.transfer.upload(videoFile(), 'المقطع'), 'renewed-claim');
    assert.equal(fixture.renewals(), 1);
    assert.deepEqual(fixture.server.methods(), ['POST', 'PATCH', 'HEAD', 'PATCH']);
    assert.equal(fixture.server.log.filter(entry => entry.method === 'POST').length, 1);
});

test('a revision change during upload stops the transfer with its stable code, without retry loops', async () => {
    let version = 7;
    const server = tusServer((xhr, _body, count) => {
        if (count === 2) version = 8;
        return xhr.method === 'PATCH' && count === 2 ? 'network-error' : null;
    });
    const fixture = transferFixture({server, authoringVersion: () => version});
    await assert.rejects(fixture.transfer.upload(videoFile(), 'المقطع'),
        error => error.code === 'bunny_upload_claim_unavailable');
    assert.deepEqual(fixture.server.methods(), ['POST', 'PATCH']);
});

test('a terminal renewal from our API is surfaced with its code and never treated as a lost provider video', async () => {
    const renewError = Object.assign(new Error('انتهت صلاحية عملية الرفع'), {status: 410, code: 'bunny_upload_claim_unavailable'});
    const fixture = transferFixture({renewError, stored: {
        fingerprint: 'fingerprint', idempotencyKey: 'attempt-1', authoringVersion: 7,
        endpoint: 'https://video.bunnycdn.com/tusupload', claim: 'signed-claim',
        headers: null, authorizationDeadline: 0, uploadUrl: 'https://video.bunnycdn.com/tus/video-1',
    }});
    await assert.rejects(fixture.transfer.upload(videoFile(), 'المقطع'), error => error === renewError);
    assert.equal(fixture.allocations(), 0, 'no re-allocation');
    assert.equal(fixture.events.includes('clear'), false, 'the record is left for the caller to retire by code');
    assert.deepEqual(fixture.server.log, []);
});

test('missing provider video re-allocates once, then fails without an allocation loop', async () => {
    const server = tusServer(xhr => (xhr.method === 'POST' ? {status: 404} : null));
    const fixture = transferFixture({server});
    await assert.rejects(fixture.transfer.upload(videoFile(), 'المقطع'),
        error => error.code === 'bunny_remote_upload_unavailable');
    assert.equal(fixture.allocations(), 2);
    assert.equal(fixture.events.filter(event => event === 'clear').length, 2);
    assert.equal(fixture.events.includes('progress:100'), false);
});

test('stopping mid-transfer aborts the request; reset and retry resume the same remote upload', async () => {
    let hang = true;
    const server = tusServer(xhr => (xhr.method === 'PATCH' && hang ? 'hang' : null));
    const fixture = transferFixture({server});
    const running = fixture.transfer.upload(videoFile(), 'المقطع');
    while (!fixture.server.methods().includes('PATCH')) await new Promise(resolve => setTimeout(resolve, 0));
    fixture.transfer.stop();
    await assert.rejects(running, error => error.cancelled === true);
    assert.equal(server.live.size, 0, 'the PATCH request is aborted');
    hang = false;
    fixture.transfer.reset();
    assert.equal(await fixture.transfer.upload(videoFile(), 'المقطع'), 'signed-claim');
    assert.deepEqual(fixture.server.methods(), ['POST', 'PATCH', 'HEAD', 'PATCH']);
    assert.equal(fixture.allocations(), 1);
});

test('cancellation while waiting for tab identity cannot allocate or report completion', async () => {
    let release;
    const ready = new Promise(resolve => { release = resolve; });
    const fixture = transferFixture({ready});
    const result = fixture.transfer.upload(videoFile(), 'المقطع');
    fixture.transfer.stop();
    release();
    await assert.rejects(result, error => error.cancelled === true);
    assert.deepEqual(fixture.events, []);
    fixture.transfer.reset();
    assert.equal(await fixture.transfer.upload(videoFile(), 'المقطع'), 'signed-claim');
});

test('MIME, extension and size limits are enforced before any allocation', async () => {
    const fixture = transferFixture();
    await assert.rejects(fixture.transfer.upload(videoFile('clip.mp4', 4, 'video/webm'), 'x'), /صيغة الفيديو/);
    await assert.rejects(fixture.transfer.upload(videoFile('clip.mkv', 4, 'video/x-matroska'), 'x'), /صيغة الفيديو/);
    await assert.rejects(fixture.transfer.upload(videoFile('clip.mp4', 0), 'x'), /5GB/);
    await assert.rejects(fixture.transfer.upload(videoFile(), ''), /عنوان/);
    assert.equal(fixture.allocations(), 0);
    assert.deepEqual(fixture.server.log, []);
});

test('draft conflicts and terminal claims are not treated as transient allocation failures', async () => {
    const error = Object.assign(new Error('revision changed'), {status: 409, code: 'course_revision_changed'});
    const fixture = transferFixture({allocationError: error});
    await assert.rejects(fixture.transfer.upload(videoFile(), 'المقطع'), value => value === error);
    assert.equal(fixture.transfer.isResumablePreparation(error), false);
    assert.equal(fixture.transfer.isResumablePreparation({code: 'bunny_upload_claim_unavailable'}), false);
    assert.equal(fixture.transfer.isResumablePreparation({code: 'bunny_upload_allocation_in_progress'}), true);
    assert.equal(fixture.allocations(), 1);
});

function queueFixture(behaviour) {
    const context = runtime();
    const started = [];
    const pending = new Map();
    const idle = [];
    const queue = context.RoknBunnyUploadQueue.create({
        run: (item, control) => {
            started.push(item.name);
            return behaviour(item, control, pending);
        },
        onIdle: snapshots => idle.push(Array.from(snapshots, snapshot => snapshot.state)),
    });
    const states = () => Array.from(queue.items(), snapshot => `${snapshot.title}:${snapshot.state}`);
    const settle = () => new Promise(resolve => setTimeout(resolve, 0));
    return {queue, started, pending, idle, states, settle};
}

const deferred = (pending, name) => new Promise((resolve, reject) => pending.set(name, {resolve, reject}));

test('queue runs one reel at a time and a failed reel does not stop the next ones', async () => {
    const fixture = queueFixture((item, _control, pending) => deferred(pending, item.name));
    fixture.queue.add(['a', 'b', 'c'].map(name => ({name, title: name})));
    await fixture.settle();
    assert.deepEqual(fixture.started, ['a']);
    assert.deepEqual(fixture.states(), ['a:uploading', 'b:queued', 'c:queued']);
    fixture.pending.get('a').reject(new Error('تعذر رفع الفيديو'));
    await fixture.settle();
    assert.deepEqual(fixture.started, ['a', 'b']);
    fixture.pending.get('b').resolve();
    await fixture.settle();
    fixture.pending.get('c').resolve();
    await fixture.settle();
    assert.deepEqual(fixture.states(), ['a:failed', 'b:done', 'c:done']);
    assert.equal(fixture.queue.items()[0].error, 'تعذر رفع الفيديو');
    assert.equal(fixture.queue.isBusy(), false);
    assert.deepEqual(fixture.idle, [['failed', 'done', 'done']]);
});

test('cancel stops the active upload through its handler; retry runs the same reel again', async () => {
    let cancelled = 0;
    const fixture = queueFixture((item, control, pending) => {
        const promise = deferred(pending, `${item.name}${fixture.started.length}`);
        control.onCancel(() => {
            cancelled += 1;
            pending.get(`${item.name}${fixture.started.length}`).reject(Object.assign(new Error('stop'), {cancelled: true}));
        });
        return promise;
    });
    fixture.queue.add([{name: 'a', title: 'a'}, {name: 'b', title: 'b'}]);
    await fixture.settle();
    const [first, second] = fixture.queue.items();
    assert.equal(first.canCancel, true);
    fixture.queue.cancel(second.id);
    fixture.queue.cancel(first.id);
    await fixture.settle();
    assert.equal(cancelled, 1);
    assert.deepEqual(fixture.states(), ['a:cancelled', 'b:cancelled']);
    assert.deepEqual(fixture.started, ['a'], 'a cancelled waiting reel never starts');
    fixture.queue.retry(second.id);
    await fixture.settle();
    assert.deepEqual(fixture.started, ['a', 'b']);
    fixture.pending.get('b2').resolve();
    await fixture.settle();
    assert.deepEqual(fixture.states(), ['a:cancelled', 'b:done']);
    fixture.queue.remove(first.id);
    fixture.queue.clearFinished();
    assert.deepEqual(fixture.states(), []);
});

test('saving cannot be cancelled, and stop-all never hands the slot to the next reel', async () => {
    let cancelHandlerCalls = 0;
    const fixture = queueFixture((item, control, pending) => {
        control.onCancel(() => { cancelHandlerCalls += 1; });
        control.saving();
        return deferred(pending, item.name);
    });
    fixture.queue.add([{name: 'a', title: 'a'}, {name: 'b', title: 'b'}, {name: 'c', title: 'c'}]);
    await fixture.settle();
    const [first] = fixture.queue.items();
    assert.equal(first.state, 'saving');
    assert.equal(first.canCancel, false);
    fixture.queue.cancelAll();
    assert.equal(cancelHandlerCalls, 0);
    fixture.pending.get('a').resolve();
    await fixture.settle();
    assert.deepEqual(fixture.states(), ['a:done', 'b:cancelled', 'c:cancelled']);
    assert.deepEqual(fixture.started, ['a']);
});

test('titles are editable only before the reel starts', async () => {
    const fixture = queueFixture((item, _control, pending) => deferred(pending, item.name));
    fixture.queue.add([{name: 'a', title: 'a'}, {name: 'b', title: 'b'}]);
    await fixture.settle();
    const [first, second] = fixture.queue.items();
    fixture.queue.rename(first.id, 'changed');
    fixture.queue.rename(second.id, '  الدرس الثاني  ');
    assert.deepEqual(Array.from(fixture.queue.items(), item => item.title), ['a', 'الدرس الثاني']);
});
