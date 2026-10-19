import { test } from 'node:test';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import authoringDraft, { createDraftStore } from '../../resources/js/admin/authoring-draft.mjs';

function storage() {
    const values = new Map();
    return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) };
}
function environment(local = storage(), session = storage(), navigation = 'navigate') {
    let time = 1000000;
    return {
        localStorage: local, sessionStorage: session, crypto: { randomUUID },
        Date: { now: () => time }, advance: amount => { time += amount; },
        performance: { getEntriesByType: () => [{ type: navigation }] },
        setInterval: () => 1, clearInterval: () => {}, addEventListener: () => {},
    };
}
const config = () => ({ actor: '12', path: '/operations/paths/create', formId: 'native-authoring', receipt: randomUUID() });

test('hard reload keeps one lane, values and create receipt', () => {
    const env = environment();
    const first = createDraftStore(config(), env);
    first.snapshot({ title_ar: 'مسار محفوظ', course_ids: [1, 2] }, true);
    const saved = first.current();
    const next = createDraftStore(config(), environment(env.localStorage, env.sessionStorage, 'reload'));
    assert.equal(next.key, first.key);
    assert.deepEqual(next.current(), saved);
    next.forget();
    assert.equal(next.current(), null);
});

test('a live cloned tab gets a separate lane and explicit recoverable draft', () => {
    const env = environment();
    const first = createDraftStore(config(), env);
    first.snapshot({ title_ar: 'تبويب أول' });
    const clone = createDraftStore(config(), environment(env.localStorage, env.sessionStorage));
    assert.notEqual(first.key, clone.key);
    assert.equal(clone.current(), null);
    assert.equal(clone.recoverable().length, 1);
    clone.adopt(clone.recoverable()[0].saved);
    assert.equal(clone.current().values.title_ar, 'تبويب أول');
    assert.notEqual(clone.current().receipt, first.current().receipt);
});

test('account, route and revision are isolated and consumed receipts are discarded', () => {
    const env = environment();
    const initial = config();
    const first = createDraftStore({ ...initial, version: 'v1' }, env);
    first.snapshot({ title_ar: 'أصل' });
    assert.equal(createDraftStore({ ...initial, actor: '99' }, environment(env.localStorage)).recoverable().length, 0);
    assert.equal(createDraftStore({ ...initial, path: '/other' }, environment(env.localStorage)).recoverable().length, 0);
    assert.equal(createDraftStore({ ...initial, version: 'v2' }, environment(env.localStorage)).recoverable().length, 0);
    const reloaded = createDraftStore({ ...initial, consumed: [initial.receipt] }, environment(env.localStorage, env.sessionStorage, 'reload'));
    assert.equal(reloaded.current(), null);
});

test('expired drafts and broken/full/private storage do not block authoring', () => {
    const env = environment();
    const store = createDraftStore(config(), env);
    store.snapshot({ title_ar: 'قديم' });
    env.advance(8 * 86400000);
    assert.equal(store.current(), null);
    Object.defineProperty(env, 'localStorage', { get() { throw new Error('blocked'); } });
    assert.doesNotThrow(() => createDraftStore(config(), env).snapshot({ title_ar: 'جديد' }));
});

test('the saved revision changes only after a successful acknowledgement', () => {
    const env = environment();
    const store = createDraftStore({ ...config(), version: 'first' }, env);
    store.snapshot({ name_ar: 'قبل' });
    assert.equal(store.current().version, 'first');
    store.setIdentity('second', randomUUID());
    store.forget();
    store.snapshot({ name_ar: 'بعد' });
    assert.equal(store.current().version, 'second');
});

test('the native adapter adopts the next singleton save receipt only after acknowledgement', async () => {
    const previousWindow = globalThis.window;
    const previousLocation = globalThis.location;
    const env = environment();
    const listeners = new Map();
    env.addEventListener = (name, listener) => listeners.set(name, listener);
    env.removeEventListener = name => listeners.delete(name);
    globalThis.window = env;
    globalThis.location = { pathname: '/operations/app-artwork' };
    const settings = { ...config(), version: 'initial', fields: ['name_ar'], fileFields: [] };
    const nextReceipt = randomUUID();
    const component = authoringDraft(settings);
    component.$el = { closest: () => ({ addEventListener() {}, removeEventListener() {} }), dataset: {} };
    component.$wire = { $id: 'singleton-editor', data: { name_ar: 'قبل الحفظ' }, $watch: () => () => {} };
    try {
        await component.init();
        component.snapshot();
        listeners.get('authoring-draft-saved')({ detail: { editorId: 'singleton-editor', version: 'saved', receipt: nextReceipt } });
        component.$wire.data.name_ar = 'بعد الحفظ';
        component.snapshot();
        const [entry] = JSON.parse(env.localStorage.getItem('rokn:course-authoring-draft-index:12'));
        const saved = JSON.parse(env.localStorage.getItem(entry.key));
        assert.equal(saved.version, 'saved');
        assert.equal(saved.receipt, nextReceipt);
        assert.equal(saved.values.name_ar, 'بعد الحفظ');
        assert.equal(settings.version, 'saved');
    } finally {
        component.destroy();
        if (previousWindow === undefined) delete globalThis.window; else globalThis.window = previousWindow;
        if (previousLocation === undefined) delete globalThis.location; else globalThis.location = previousLocation;
    }
});
