'use strict';

/*
 * Multi-file upload queue. No DOM, network or storage access: `run(item,
 * control)` performs one item (upload, then save) and the caller renders
 * `onChange` snapshots.
 *
 * The observable behaviour follows the multi-upload queues of YouTube Studio
 * and Vimeo: every selected file becomes its own row with progress, a failed
 * row does not stop the rows after it, and each row can be cancelled or
 * retried on its own. This is Rokn-owned code reproducing that UX; no code
 * from either product is used.
 *
 * Concurrency is 1 by design: a Bunny upload claim is bound to the course
 * authoring version, and saving a reel advances that version, so a second
 * in-flight claim would be invalidated by the first save.
 */
(function (window) {
    const ACTIVE = new Set(['uploading', 'saving']);
    const PENDING = new Set(['queued', 'uploading', 'saving']);

    const create = ({run, onChange = () => {}, onIdle = () => {}}) => {
        const items = [];
        let nextId = 1;
        let running = null;

        const snapshot = item => Object.freeze({
            id: item.id,
            title: item.title,
            moduleId: item.moduleId,
            state: item.state,
            percent: item.percent,
            message: item.message,
            error: item.error,
            canCancel: item.state === 'queued' || item.state === 'uploading',
            canRetry: item.state === 'failed' || item.state === 'cancelled',
            canRemove: !PENDING.has(item.state),
            canRename: item.state === 'queued' || ((item.state === 'failed' || item.state === 'cancelled') && !item.claim),
        });
        const emit = () => onChange(items.map(snapshot));
        const find = id => items.find(item => item.id === Number(id)) || null;

        const pump = (afterRun = false) => {
            if (running) return;
            const item = items.find(candidate => candidate.state === 'queued');
            if (!item) {
                if (afterRun) onIdle(items.map(snapshot));
                return;
            }
            running = item;
            item.state = 'uploading';
            item.error = '';
            item.cancelHandler = null;
            const control = Object.freeze({
                progress(message, percent) {
                    if (running !== item) return;
                    item.message = String(message || '');
                    if (Number.isFinite(percent)) item.percent = Math.max(0, Math.min(100, percent));
                    emit();
                },
                // Once saving starts the server mutation cannot be withdrawn.
                saving() {
                    item.state = 'saving';
                    item.cancelHandler = null;
                    emit();
                },
                onCancel(handler) { item.cancelHandler = handler; },
            });
            emit();
            Promise.resolve()
                .then(() => run(item, control))
                .then(() => {
                    item.state = 'done';
                    item.percent = 100;
                    item.message = 'تم حفظ المقطع';
                }, error => {
                    item.state = error?.cancelled || item.cancelRequested ? 'cancelled' : 'failed';
                    item.message = item.state === 'cancelled' ? 'تم الإيقاف' : 'تعذر الإكمال';
                    item.error = item.state === 'failed' ? String(error?.message || 'تعذر رفع الفيديو') : '';
                })
                .finally(() => {
                    item.cancelHandler = null;
                    item.cancelRequested = false;
                    running = null;
                    emit();
                    pump(true);
                });
        };

        const add = entries => {
            entries.forEach(entry => items.push({
                ...entry,
                id: nextId++,
                state: 'queued',
                percent: 0,
                message: 'في الانتظار',
                error: '',
                cancelHandler: null,
                cancelRequested: false,
            }));
            emit();
            pump();
        };
        const cancel = id => {
            const item = find(id);
            if (!item) return;
            if (item.state === 'queued') {
                item.state = 'cancelled';
                item.message = 'تم الإيقاف';
                emit();
                pump();
            } else if (item.state === 'uploading') {
                item.cancelRequested = true;
                item.cancelHandler?.();
            }
        };
        const retry = id => {
            const item = find(id);
            if (!item || !(item.state === 'failed' || item.state === 'cancelled')) return;
            item.state = 'queued';
            item.message = 'في الانتظار';
            item.error = '';
            emit();
            pump();
        };
        const remove = id => {
            const item = find(id);
            if (!item || PENDING.has(item.state)) return;
            items.splice(items.indexOf(item), 1);
            emit();
        };
        const rename = (id, title) => {
            const item = find(id);
            if (!item || !snapshot(item).canRename) return;
            item.title = String(title || '').trim();
            emit();
        };

        return Object.freeze({
            add,
            cancel,
            retry,
            remove,
            rename,
            cancelAll: () => {
                // Withdraw waiting rows first so stopping the active row cannot
                // hand the slot to the next one.
                items.filter(item => item.state === 'queued').forEach(item => {
                    item.state = 'cancelled';
                    item.message = 'تم الإيقاف';
                });
                if (running?.state === 'uploading') cancel(running.id);
                emit();
            },
            clearFinished: () => {
                for (let index = items.length - 1; index >= 0; index -= 1) {
                    if (items[index].state === 'done') items.splice(index, 1);
                }
                emit();
            },
            isBusy: () => items.some(item => PENDING.has(item.state)),
            isActive: () => items.some(item => ACTIVE.has(item.state)),
            items: () => items.map(snapshot),
        });
    };

    window.RoknBunnyUploadQueue = Object.freeze({create});
})(window);
