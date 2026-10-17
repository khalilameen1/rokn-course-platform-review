@php
    $draftFormId = $formId ?? null;
    $consumedDraftReceipts = array_keys((array) session('admin_authoring_draft_receipts', []));
    try {
        if (auth()->id() && \Illuminate\Support\Facades\Schema::hasTable('admin_authoring_draft_receipts')) {
            $persistedDraftReceipts = \Illuminate\Support\Facades\DB::table('admin_authoring_draft_receipts')
                ->where('actor_id', auth()->id())
                ->where('consumed_at', '>=', now()->subDays(7))
                ->pluck('receipt')
                ->all();
            $consumedDraftReceipts = array_values(array_unique(array_merge(
                $consumedDraftReceipts,
                $persistedDraftReceipts
            )));
        }
    } catch (\Throwable $exception) {
        report($exception);
    }
@endphp
@if($draftFormId)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById(@json($draftFormId));
    if (!form || !window.localStorage || !window.sessionStorage) return;
    const submitControls = () => Array.from(new Set([
        ...form.querySelectorAll('button[type="submit"], input[type="submit"]'),
        ...document.querySelectorAll('[form="' + CSS.escape(form.id) + '"][type="submit"]'),
    ]));
    const conflictMessage = @json($errors->first('authoring_version') ?: $errors->first('editor_version'));
    if (conflictMessage) {
        const conflict = document.createElement('div');
        conflict.className = 'alert alert-warning mb-3';
        conflict.style.whiteSpace = 'pre-line';
        const copy = document.createElement('div');
        copy.textContent = conflictMessage;
        const conflictReloadButton = document.createElement('button');
        conflictReloadButton.type = 'button';
        conflictReloadButton.className = 'btn btn-sm btn-warning mt-2';
        conflictReloadButton.textContent = 'تحميل أحدث نسخة';
        conflictReloadButton.addEventListener('click', () => window.location.reload());
        conflict.append(copy, conflictReloadButton);
        form.prepend(conflict);
        // Validation redirects carry the old form values. The hidden revision,
        // however, is rendered from the newly loaded course. Saving or even
        // snapshotting that hybrid would let stale fields adopt the latest
        // revision on the next click. This page is therefore read-only until
        // one clean GET loads one coherent version.
        submitControls().forEach(button => {
            button.disabled = true;
        });
        form.addEventListener('submit', event => {
            event.preventDefault();
            conflictReloadButton.focus();
        });
        return;
    }
    // A create request gets a fresh idempotency UUID after a hard reload. It
    // must not invalidate the user's saved text; only an existing resource's
    // editor revision decides whether a draft is safe to restore.
    const versionInput = form.querySelector('[name="authoring_version"], [name="editor_version"]');
    const tabIdentityKey = 'rokn:authoring-tab:' + location.pathname + ':' + form.id;
    let tabId = sessionStorage.getItem(tabIdentityKey);
    if (!/^[0-9a-f-]{36}$/i.test(String(tabId || ''))) {
        tabId = window.crypto?.randomUUID?.() || @json((string) \Illuminate\Support\Str::uuid());
        sessionStorage.setItem(tabIdentityKey, tabId);
    }
    // Duplicate-tab/window.open clones sessionStorage in major browsers. A
    // short document lease distinguishes that clone from a hard reload: reload
    // keeps the same draft, while a concurrently live owner gets a new lane.
    const documentId = window.crypto?.randomUUID?.() || @json((string) \Illuminate\Support\Str::uuid());
    const navigationType = performance.getEntriesByType?.('navigation')?.[0]?.type || '';
    const leasePrefix = 'rokn:authoring-document-lease:';
    const currentLeaseKey = () => leasePrefix + tabId;
    try {
        const lease = JSON.parse(localStorage.getItem(currentLeaseKey()) || 'null');
        if (
            navigationType !== 'reload'
            && lease
            && lease.documentId !== documentId
            && Date.now() - Number(lease.seenAt || 0) < 10000
        ) {
            tabId = window.crypto?.randomUUID?.() || @json((string) \Illuminate\Support\Str::uuid());
            sessionStorage.setItem(tabIdentityKey, tabId);
        }
    } catch (_) {}
    const renewDocumentLease = () => {
        try {
            localStorage.setItem(currentLeaseKey(), JSON.stringify({documentId, seenAt: Date.now()}));
        } catch (_) {}
    };
    renewDocumentLease();
    const documentLeaseTimer = setInterval(renewDocumentLease, 3000);
    window.addEventListener('pagehide', () => {
        clearInterval(documentLeaseTimer);
        try {
            const lease = JSON.parse(localStorage.getItem(currentLeaseKey()) || 'null');
            if (lease?.documentId === documentId) localStorage.removeItem(currentLeaseKey());
        } catch (_) {}
    });
    const draftPrefix = 'rokn:course-authoring-draft:{{ auth()->id() }}:' + location.pathname + ':' + form.id;
    const key = draftPrefix + ':' + tabId;
    const registryKey = 'rokn:course-authoring-draft-index:{{ auth()->id() }}';
    const consumedReceipts = new Set(@json($consumedDraftReceipts));
    const ignored = new Set(['_token', '_method', 'authoring_version', 'editor_version', 'authoring_request_id', 'authoring_draft_receipt', 'password', 'password_confirmation', 'current_password', 'confirm_send']);
    const enqueueDraftMicrotask = window.queueMicrotask || (callback => Promise.resolve().then(callback));
    const receiptInput = form.querySelector('[name="authoring_draft_receipt"]') || (() => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'authoring_draft_receipt';
        input.value = @json((string) \Illuminate\Support\Str::uuid());
        form.appendChild(input);
        return input;
    })();
    const createIntentInput = form.querySelector('[name="authoring_request_id"]') || (() => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'authoring_request_id';
        input.value = receiptInput.value;
        form.appendChild(input);
        return input;
    })();
    let dirty = false;
    let submitting = false;
    let submitQueued = false;

    const readRegistry = () => {
        try {
            const parsed = JSON.parse(localStorage.getItem(registryKey) || '[]');
            return Array.isArray(parsed)
                ? parsed.filter(entry => entry && typeof entry.key === 'string' && Date.now() - Number(entry.savedAt || 0) < 7 * 86400000)
                : [];
        } catch (_) {
            return [];
        }
    };
    const writeRegistry = entries => {
        try { localStorage.setItem(registryKey, JSON.stringify(entries.slice(-120))); } catch (_) {}
    };
    const forgetDraft = draftKey => {
        localStorage.removeItem(draftKey);
        writeRegistry(readRegistry().filter(entry => entry.key !== draftKey));
    };
    const indexDraft = saved => {
        const entries = readRegistry().filter(entry => entry.key !== key);
        entries.push({
            key,
            tabId,
            path: location.pathname,
            formId: form.id,
            receipt: saved.receipt,
            savedAt: saved.savedAt,
        });
        writeRegistry(entries);
    };

    const snapshot = () => {
        const values = {};
        let hadFiles = false;
        form.querySelectorAll('input, textarea, select').forEach(field => {
            if (!field.name || ignored.has(field.name) || field.disabled) return;
            if (
                field.type === 'hidden'
                && form.querySelector('input[type="checkbox"][name="' + CSS.escape(field.name) + '"]')
            ) return;
            if (field.type === 'file') {
                hadFiles = hadFiles || Boolean(field.files && field.files.length);
                return;
            }
            if (field.type === 'radio') {
                if (field.checked) values[field.name] = field.value;
                return;
            }
            if (field.type === 'checkbox') {
                if (field.name.endsWith('[]')) {
                    values[field.name] = values[field.name] || [];
                    if (field.checked) values[field.name].push(field.value);
                } else {
                    values[field.name] = field.checked;
                }
                return;
            }
            if (field.multiple) {
                values[field.name] = Array.from(field.selectedOptions).map(option => option.value);
                return;
            }
            values[field.name] = field.value;
        });
        try {
            const saved = {
                version: String(versionInput?.value || ''),
                savedAt: Date.now(),
                hadFiles,
                receipt: receiptInput.value,
                values,
            };
            localStorage.setItem(key, JSON.stringify(saved));
            indexDraft(saved);
        } catch (_) {}
    };

    try {
        const saved = JSON.parse(localStorage.getItem(key) || 'null');
        const currentVersion = String(versionInput?.value || '');
        if (saved && consumedReceipts.has(String(saved.receipt || ''))) {
            forgetDraft(key);
        } else if (saved && saved.version === currentVersion && Date.now() - saved.savedAt < 7 * 86400000) {
            if (typeof saved.receipt === 'string' && saved.receipt) {
                receiptInput.value = saved.receipt;
                createIntentInput.value = saved.receipt;
            }
            document.dispatchEvent(new CustomEvent('rokn:authoring-draft-prepare', {
                detail: {formId: form.id, values: saved.values || {}},
            }));
            Object.entries(saved.values || {}).forEach(([name, value]) => {
                const fields = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
                fields.forEach(field => {
                    if (
                        field.type === 'hidden'
                        && form.querySelector('input[type="checkbox"][name="' + CSS.escape(field.name) + '"]')
                    ) return;
                    if (field.type === 'checkbox') {
                        field.checked = Array.isArray(value) ? value.includes(field.value) : Boolean(value);
                    }
                    else if (field.type === 'radio') field.checked = field.value === value;
                    else if (field.multiple && Array.isArray(value)) {
                        Array.from(field.options).forEach(option => option.selected = value.includes(option.value));
                    } else field.value = value ?? '';
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                });
            });
            dirty = Object.keys(saved.values || {}).length > 0;
            if (saved.hadFiles) {
                const note = document.createElement('div');
                note.className = 'alert alert-warning mt-3';
                note.textContent = 'استعدنا النصوص التي لم تُحفظ\nاختر الملف مرة أخرى قبل الحفظ';
                form.prepend(note);
            }
        }
    } catch (_) {
        forgetDraft(key);
    }

    const recoverableDrafts = readRegistry()
        .filter(entry => entry.key !== key && entry.path === location.pathname && entry.formId === form.id)
        .map(entry => ({entry, saved: (() => {
            try { return JSON.parse(localStorage.getItem(entry.key) || 'null'); } catch (_) { return null; }
        })()}))
        .filter(candidate => candidate.saved && !consumedReceipts.has(String(candidate.saved.receipt || '')))
        .sort((left, right) => Number(right.saved.savedAt || 0) - Number(left.saved.savedAt || 0));
    if (recoverableDrafts.length) {
        const recovery = document.createElement('div');
        recovery.className = 'alert alert-info mb-3';
        const title = document.createElement('div');
        title.textContent = 'توجد مسودة أخرى لهذا النموذج';
        recovery.appendChild(title);
        recoverableDrafts.slice(0, 3).forEach((candidate, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-primary mt-2 ml-2';
            button.textContent = index === 0 ? 'استعادة أحدث مسودة' : 'استعادة المسودة ' + (index + 1);
            button.addEventListener('click', () => {
                const restored = {
                    ...candidate.saved,
                    receipt: window.crypto?.randomUUID?.() || @json((string) \Illuminate\Support\Str::uuid()),
                    savedAt: Date.now(),
                };
                localStorage.setItem(key, JSON.stringify(restored));
                indexDraft(restored);
                location.reload();
            });
            recovery.appendChild(button);
        });
        form.prepend(recovery);
    }

    let timer;
    form.addEventListener('input', () => {
        dirty = true;
        clearTimeout(timer);
        timer = setTimeout(snapshot, 250);
    });
    form.addEventListener('change', () => {
        dirty = true;
        snapshot();
    });
    form.addEventListener('reset', () => enqueueDraftMicrotask(() => {
        clearTimeout(timer);
        dirty = false;
        forgetDraft(key);
    }));
    form.addEventListener('submit', event => {
        if (submitting || submitQueued) {
            event.preventDefault();
            return;
        }
        submitQueued = true;
        enqueueDraftMicrotask(() => {
            if (event.defaultPrevented) {
                submitQueued = false;
                return;
            }
            submitting = true;
            snapshot();
            form.setAttribute('aria-busy', 'true');
            submitControls().forEach(button => {
                button.disabled = true;
            });
        });
    });
    window.addEventListener('beforeunload', event => {
        if (!dirty || submitting) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
</script>
@endif
