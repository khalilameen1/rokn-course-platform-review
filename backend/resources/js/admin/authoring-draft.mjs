// Rokn's existing seven-day, account/route/tab-scoped authoring protocol.
// The classic DOM and native Livewire adapters share this store and receipt identity.
export function createDraftStore({ actor, path, formId, version = '', receipt, consumed = [] }, environment = window) {
    const unavailableStorage = { getItem: () => null, setItem: () => {}, removeItem: () => {} };
    let storage = unavailableStorage;
    let session = unavailableStorage;
    try { storage = environment.localStorage; session = environment.sessionStorage; } catch { /* Browsers may forbid persistent storage. */ }
    const now = () => environment.Date.now();
    const uuid = () => environment.crypto.randomUUID();
    const parse = (key, fallback) => {
        try { return JSON.parse(storage.getItem(key) || 'null') ?? fallback; } catch { return fallback; }
    };
    const write = (key, value) => { try { storage.setItem(key, JSON.stringify(value)); } catch { /* Private/full storage must not block editing. */ } };
    const remove = key => { try { storage.removeItem(key); } catch { /* Editing remains available. */ } };
    const tabKey = `rokn:authoring-tab:${path}:${formId}`;
    let tabId;
    try { tabId = session.getItem(tabKey); } catch { /* In-memory lane below. */ }
    if (!/^[0-9a-f-]{36}$/i.test(String(tabId || ''))) tabId = uuid();
    const documentId = uuid();
    const leaseKey = () => `rokn:authoring-document-lease:${tabId}`;
    const lease = parse(leaseKey(), null);
    if (environment.performance.getEntriesByType?.('navigation')?.[0]?.type !== 'reload'
        && lease && lease.documentId !== documentId && now() - Number(lease.seenAt || 0) < 10000) tabId = uuid();
    try { session.setItem(tabKey, tabId); } catch { /* No cross-refresh recovery in this browser. */ }
    const key = `rokn:course-authoring-draft:${actor}:${path}:${formId}:${tabId}`;
    const registryKey = `rokn:course-authoring-draft-index:${actor}`;
    const consumedReceipts = new Set(consumed);
    const fresh = saved => saved && now() - Number(saved.savedAt || 0) < 7 * 86400000;
    const registry = () => {
        const entries = parse(registryKey, []);
        return Array.isArray(entries) ? entries.filter(entry => entry && typeof entry.key === 'string' && fresh(entry)) : [];
    };
    const forget = (draftKey = key) => { remove(draftKey); write(registryKey, registry().filter(entry => entry.key !== draftKey)); };
    const save = saved => {
        write(key, saved);
        write(registryKey, [...registry().filter(entry => entry.key !== key), {
            key, tabId, path, formId, receipt: saved.receipt, savedAt: saved.savedAt,
        }].slice(-120));
    };
    const current = () => {
        const saved = parse(key, null);
        if (saved && (!fresh(saved) || consumedReceipts.has(String(saved.receipt || '')))) { forget(); return null; }
        return saved;
    };
    const renew = () => write(leaseKey(), { documentId, seenAt: now() });
    renew();
    const timer = environment.setInterval(renew, 3000);
    const close = () => {
        environment.clearInterval(timer);
        if (parse(leaseKey(), null)?.documentId === documentId) remove(leaseKey());
    };
    environment.addEventListener('pagehide', close, { once: true });
    return {
        key, tabId, forget, save, current, close,
        snapshot(values, hadFiles = false) { save({ version: String(version), savedAt: now(), hadFiles, receipt, values }); },
        setIdentity(nextVersion, nextReceipt) { version = String(nextVersion); receipt = nextReceipt; },
        recoverable() {
            return registry().filter(entry => entry.key !== key && entry.path === path && entry.formId === formId)
                .map(entry => ({ entry, saved: parse(entry.key, null) }))
                .filter(({ saved }) => fresh(saved) && saved.version === String(version) && !consumedReceipts.has(String(saved.receipt || '')))
                .sort((left, right) => right.saved.savedAt - left.saved.savedAt).slice(0, 3);
        },
        adopt(saved) { save({ ...saved, receipt: uuid(), savedAt: now() }); },
    };
}

// Filament's x-load loads this factory; $wire/$watch are the official Livewire/Alpine APIs.
export default function authoringDraft(config) {
    let store;
    let ready = false;
    let restoring = false;
    let timer;
    let dirty = false;
    let initialFiles;
    let form;
    let onSubmit;
    let onBeforeUnload;
    let onSaved;
    let unwatch;
    const notify = (title, warning = false) => {
        const notification = new window.FilamentNotification().title(title);
        (warning ? notification.warning() : notification.success()).send();
    };
    return {
        recoverable: [],
        async init() {
            store = createDraftStore({ ...config, path: location.pathname });
            form = this.$el.closest('form');
            initialFiles = Object.fromEntries(config.fileFields.map(field => [field, JSON.stringify(this.$wire.data[field] || {})]));
            await this.restore(store.current());
            this.recoverable = store.recoverable();
            unwatch = this.$wire.$watch('data', () => {
                if (!ready || restoring) return;
                dirty = true;
                clearTimeout(timer);
                timer = setTimeout(() => this.snapshot(), 250);
            });
            onSubmit = () => this.snapshot();
            form?.addEventListener('submit', onSubmit);
            onBeforeUnload = event => {
                if (!dirty) return;
                this.snapshot();
                event.preventDefault(); event.returnValue = '';
            };
            window.addEventListener('beforeunload', onBeforeUnload);
            onSaved = event => {
                if (event.detail.editorId !== this.$wire.$id) return;
                clearTimeout(timer);
                dirty = false;
                this.recoverable = [];
                config.version = event.detail.version;
                config.receipt = event.detail.receipt || config.receipt;
                store.setIdentity(event.detail.version, config.receipt);
                store.forget();
            };
            window.addEventListener('authoring-draft-saved', onSaved);
            ready = true;
            this.$el.dataset.draftReady = 'true';
        },
        snapshot() {
            if (!ready || restoring) return;
            const values = {};
            for (const field of config.fields) values[field] = JSON.parse(JSON.stringify(this.$wire.data[field] ?? null));
            const hadFiles = config.fileFields.some(field => Object.keys(this.$wire.data[field] || {}).length > 0
                && JSON.stringify(this.$wire.data[field] || {}) !== initialFiles[field]);
            store.snapshot(values, hadFiles);
        },
        async restore(saved) {
            if (!saved || saved.version !== String(config.version)) return;
            restoring = true;
            try {
                const result = await this.$wire.restoreAuthoringDraft(saved);
                if (result.state === 'completed') { store.forget(); window.location.assign(result.url); return; }
                if (result.state !== 'restored') return;
                config.receipt = saved.receipt;
                store.setIdentity(config.version, saved.receipt);
                dirty = true;
                notify(saved.hadFiles ? 'استعدنا النصوص غير المحفوظة اختر الملف مرة أخرى' : 'استعدنا المسودة غير المحفوظة', saved.hadFiles);
            } catch {
                notify('تعذر استعادة المسودة أعد تحميل الصفحة للمحاولة', true);
            } finally { restoring = false; }
        },
        async recover(index) {
            const candidate = this.recoverable[index];
            if (!candidate) return;
            store.adopt(candidate.saved);
            await this.restore(store.current());
            this.recoverable = [];
        },
        destroy() {
            clearTimeout(timer);
            unwatch?.();
            form?.removeEventListener('submit', onSubmit);
            window.removeEventListener('beforeunload', onBeforeUnload);
            window.removeEventListener('authoring-draft-saved', onSaved);
            store?.close();
        },
    };
}
