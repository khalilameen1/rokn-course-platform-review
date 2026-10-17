'use strict';

/*
 * Multi-reel upload: pick several videos for one module; each file is
 * uploaded with the same Bunny transfer as the inline form and then saved
 * through the section editor's createLesson (same endpoint, receipt recovery
 * and outline update). Queue mechanics live in RoknBunnyUploadQueue.
 */
(function (window, document) {
    window.RoknCourseStudio.register('reel-queue', function (core) {
        if (!core.canAuthor) return;
        const panel = document.getElementById('studioReelQueue');
        const list = document.getElementById('studioReelQueueList');
        const summary = document.getElementById('studioReelQueueSummary');
        const picker = document.getElementById('studioReelQueueInput');
        const form = document.getElementById('sectionForm');
        if (!panel || !list || !picker || !form || !window.RoknBunnyUploadQueue
            || !window.RoknBunnyUploadTransfer || !window.RoknBunnyUploadRecords || !window.tus) return;

        const sectionEditor = core.use('section-editor');
        const outline = core.use('outline');
        const coordinator = core.use('editor-coordinator');
        const ownerId = String(core.studio.dataset.actorId || '');
        const courseId = String(form.dataset.courseId || '');
        const terminalCodes = new Set(['bunny_upload_claim_unavailable', 'bunny_upload_operation_unavailable']);
        // A separate resume scope from the inline form ('new'/section id).
        const records = window.RoknBunnyUploadRecords.create({
            ownerId, courseId, sectionId: () => 'queue', currentAuthoringVersion: () => core.authoringVersion,
        });
        const forgetRecord = file => {
            records.read(file);
            records.clear();
        };
        const titleFromFile = file => String(file.name || '')
            .replace(/\.[^.]+$/, '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 255) || 'مقطع جديد';
        const moduleTitle = moduleId => outline.getModule(moduleId)?.title || 'الوحدة';

        const run = async (item, control) => {
            if (item.claim && item.claimVersion !== core.authoringVersion) item.claim = '';
            if (!item.title) throw new Error('أضف عنوان المقطع أولًا');
            if (!item.claim) {
                const transfer = window.RoknBunnyUploadTransfer.create({
                    records,
                    currentAuthoringVersion: () => core.authoringVersion,
                    sectionId: () => 'new',
                    initUrl: form.dataset.bunnyUploadInit,
                    renewUrl: form.dataset.bunnyUploadRenew,
                    csrf: form.querySelector('input[name="_token"]')?.value || '',
                    apiRequest: (url, options) => window.RoknAdminRequest.request(url, options),
                    onProgress: (message, percent) => control.progress(message, percent),
                    onClaim: () => {},
                });
                control.onCancel(() => transfer.stop());
                const version = core.authoringVersion;
                try {
                    item.claim = await transfer.upload(item.file, item.title);
                    transfer.assertActive();
                } catch (error) {
                    if (terminalCodes.has(String(error?.code || ''))) forgetRecord(item.file);
                    throw error;
                }
                item.claimVersion = version;
            }

            control.saving();
            control.progress('جاري حفظ المقطع', 100);
            const result = await sectionEditor.createLesson({
                moduleId: item.moduleId,
                title: item.title,
                claim: item.claim,
                intentId: item.intentId,
                state: item.createState,
            });
            if (result.created) {
                forgetRecord(item.file);
                item.claim = '';
                if (result.superseded) {
                    queue.cancelAll();
                    core.notify('تم حفظ المقطع لكن الكورس تغيّر من نافذة أخرى\nأعد تحميل الصفحة ثم أكمل باقي الملفات', true, 8000);
                }
                return;
            }
            const failure = result.failure;
            if (failure?.fields?.includes('bunny_video_claim_terminal')) {
                // The server will not attach this upload any more; a retry must
                // upload again under a fresh claim.
                forgetRecord(item.file);
                item.claim = '';
            }
            throw failure || new Error('تعذر حفظ المقطع');
        };

        const rows = new Map();
        const stateLabel = {
            queued: 'في الانتظار', uploading: 'جاري الرفع', saving: 'جاري الحفظ',
            done: 'تم الحفظ', failed: 'تعذر الإكمال', cancelled: 'متوقف',
        };
        const button = (action, label) => {
            const node = document.createElement('button');
            node.type = 'button';
            node.dataset.reelQueueAction = action;
            node.dataset.uploadEscape = '';
            node.textContent = label;
            return node;
        };
        const rowFor = snapshot => {
            if (rows.has(snapshot.id)) return rows.get(snapshot.id);
            const row = document.createElement('li');
            row.className = 'studio-reel-queue__item';
            row.dataset.reelQueueItem = String(snapshot.id);
            row.innerHTML = '<div class="studio-reel-queue__copy"><label class="studio-reel-queue__title"><span class="sr-only">عنوان المقطع</span><input type="text" maxlength="255" autocomplete="off"></label><strong class="studio-reel-queue__name"></strong><small class="studio-reel-queue__meta"></small></div><div class="studio-inline-upload__track"><div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div><div class="studio-reel-queue__status"><small class="studio-reel-queue__message"></small><div class="studio-reel-queue__buttons"></div></div><p class="studio-reel-queue__error" hidden></p>';
            const buttons = row.querySelector('.studio-reel-queue__buttons');
            buttons.append(button('cancel', 'إيقاف'), button('retry', 'إعادة المحاولة'), button('remove', 'إزالة'));
            row.querySelector('input').addEventListener('change', event => queue.rename(snapshot.id, event.target.value));
            rows.set(snapshot.id, row);
            list.append(row);
            return row;
        };
        const render = snapshots => {
            const seen = new Set(snapshots.map(snapshot => snapshot.id));
            for (const [id, row] of rows) {
                if (!seen.has(id)) {
                    row.remove();
                    rows.delete(id);
                }
            }
            snapshots.forEach(snapshot => {
                const row = rowFor(snapshot);
                row.dataset.state = snapshot.state;
                const input = row.querySelector('input');
                const name = row.querySelector('.studio-reel-queue__name');
                input.closest('label').hidden = !snapshot.canRename;
                name.hidden = snapshot.canRename;
                if (document.activeElement !== input) input.value = snapshot.title;
                name.textContent = snapshot.title || 'بدون عنوان';
                row.querySelector('.studio-reel-queue__meta').textContent = `${moduleTitle(snapshot.moduleId)} · ${stateLabel[snapshot.state]}`;
                const bar = row.querySelector('.progress-bar');
                bar.style.width = `${snapshot.percent}%`;
                bar.setAttribute('aria-valuenow', String(Math.round(snapshot.percent)));
                row.querySelector('.studio-reel-queue__message').textContent = snapshot.message;
                const error = row.querySelector('.studio-reel-queue__error');
                error.textContent = snapshot.error;
                error.hidden = !snapshot.error;
                row.querySelector('[data-reel-queue-action="cancel"]').hidden = !snapshot.canCancel;
                row.querySelector('[data-reel-queue-action="retry"]').hidden = !snapshot.canRetry;
                row.querySelector('[data-reel-queue-action="remove"]').hidden = !snapshot.canRemove;
            });
            const done = snapshots.filter(snapshot => snapshot.state === 'done').length;
            const failed = snapshots.filter(snapshot => snapshot.state === 'failed').length;
            const active = snapshots.find(snapshot => snapshot.state === 'uploading' || snapshot.state === 'saving');
            summary.textContent = [
                `تم حفظ ${done} من ${snapshots.length}`,
                failed ? `${failed} تحتاج إعادة المحاولة` : '',
                active ? `الآن: ${active.title}` : '',
            ].filter(Boolean).join(' · ');
            panel.hidden = snapshots.length === 0;
            core.refreshBusy();
        };
        const queue = window.RoknBunnyUploadQueue.create({
            run,
            onChange: render,
            onIdle: snapshots => {
                const saved = snapshots.filter(snapshot => snapshot.state === 'done').length;
                if (snapshots.some(snapshot => snapshot.state === 'failed')) {
                    core.notify('انتهت قائمة الرفع وبعض الملفات تحتاج إعادة المحاولة', true, 5000);
                } else if (saved) {
                    core.notify(`تم حفظ ${saved} مقاطع`);
                }
            },
        });
        window.RoknCourseReelQueue = Object.freeze({isBusy: queue.isBusy});
        core.registerBusySource(queue.isBusy);

        let pickerModuleId = null;
        document.addEventListener('click', event => {
            const open = event.target.closest('[data-reel-queue-open]');
            if (open) {
                if (window.RoknAdminRequest.mutationsAreBlocked() || window.RoknCourseVideoUpload?.isBusy()) return;
                if (coordinator.isOpen()) {
                    core.notify('احفظ المحرر المفتوح أو أغلقه أولًا ثم ارفع المقاطع', true);
                    return;
                }
                pickerModuleId = Number(open.dataset.moduleId);
                picker.value = '';
                picker.click();
                return;
            }
            const action = event.target.closest('[data-reel-queue-action]');
            if (action) {
                const id = Number(action.closest('[data-reel-queue-item]')?.dataset.reelQueueItem);
                queue[action.dataset.reelQueueAction]?.(id);
                return;
            }
            if (event.target.closest('[data-reel-queue-cancel-all]')) queue.cancelAll();
            if (event.target.closest('[data-reel-queue-clear]')) queue.clearFinished();
        });
        picker.addEventListener('change', () => {
            const moduleId = pickerModuleId;
            const files = Array.from(picker.files || []);
            picker.value = '';
            if (!moduleId || !files.length || !outline.getModule(moduleId)) return;
            // File pickers do not guarantee order; numbered reels (01, 02 ...)
            // are placed in natural name order.
            files.sort((a, b) => a.name.localeCompare(b.name, 'ar', {numeric: true}));
            queue.add(files.map(file => ({
                file,
                title: titleFromFile(file),
                moduleId,
                claim: '',
                claimVersion: 0,
                intentId: core.uuid(),
                createState: {pendingVersion: null},
            })));
            panel.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        });
        window.addEventListener('beforeunload', event => {
            if (!queue.isBusy() || window.RoknAdminRequest.mutationsAreBlocked()) return;
            event.preventDefault();
            event.returnValue = '';
        });
    });
})(window, document);
