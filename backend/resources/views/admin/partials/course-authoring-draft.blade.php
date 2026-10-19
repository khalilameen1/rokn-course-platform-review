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
<script type="module">
import { createDraftStore } from '{{ versioned_asset('js/authoring-draft.js') }}';
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
    const store = createDraftStore({
        actor: @json((string) auth()->id()), path: location.pathname, formId: form.id,
        version: String(versionInput?.value || ''), receipt: receiptInput.value,
        consumed: [...consumedReceipts],
    });
    const forgetDraft = () => store.forget();

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
            store.save(saved);
        } catch (_) {}
    };

    try {
        const saved = store.current();
        const currentVersion = String(versionInput?.value || '');
        if (saved && consumedReceipts.has(String(saved.receipt || ''))) {
            forgetDraft();
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
        forgetDraft();
    }

    const recoverableDrafts = store.recoverable();
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
                store.adopt(candidate.saved);
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
        forgetDraft();
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
