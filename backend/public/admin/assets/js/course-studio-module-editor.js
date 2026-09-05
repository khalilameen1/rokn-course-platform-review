'use strict';

(function (window, document) {
    window.RoknCourseStudio.register('module-editor', function (core) {
        if (!core.canAuthor) return;
        const outline = core.use('outline');
        const coordinator = core.use('editor-coordinator');
        const sectionEditor = core.use('section-editor');
        const editor = document.getElementById('studioInlineModuleEditor');
        const form = document.getElementById('studioModuleForm');
        if (!editor || !form) return;

        const feedback = form.querySelector('[data-module-feedback]');
        const deleteButton = document.getElementById('studioInlineDeleteModule');
        const createUrl = form.action;
        const actorId = String(core.studio.dataset.actorId || '').trim();
        const recoveryKey = actorId
            ? `rokn-course-studio-pending-module:${actorId}:${core.studio.dataset.courseId || 'course'}`
            : null;
        let editing = null;
        let pendingRecovery = null;

        const forgetPendingCreate = () => {
            pendingRecovery = null;
            try {
                if (recoveryKey) window.sessionStorage.removeItem(recoveryKey);
            } catch (_) {}
        };
        const rememberPendingCreate = expectedVersion => {
            const fields = [];
            new FormData(form).forEach((value, name) => {
                if (typeof value === 'string' && name !== '_token') fields.push([name, value]);
            });
            const state = {
                expectedVersion: Number(expectedVersion),
                fields,
                savedAt: Date.now(),
            };
            pendingRecovery = state;
            try {
                if (recoveryKey) window.sessionStorage.setItem(recoveryKey, JSON.stringify(state));
            } catch (_) {}
        };
        const readPendingCreate = () => {
            if (!recoveryKey) return null;
            try {
                const state = JSON.parse(window.sessionStorage.getItem(recoveryKey) || 'null');
                if (!state || !Number.isSafeInteger(Number(state.expectedVersion))
                    || Number(state.expectedVersion) < 1 || !Array.isArray(state.fields)
                    || !state.fields.every(field => Array.isArray(field) && field.length === 2
                        && typeof field[0] === 'string' && typeof field[1] === 'string')
                    || Date.now() - Number(state.savedAt || 0) > 86400000) {
                    forgetPendingCreate();
                    return null;
                }
                return state;
            } catch (_) {
                forgetPendingCreate();
                return null;
            }
        };

        const setMethod = method => {
            const override = form.elements._method;
            override.value = method;
            override.disabled = method === 'POST';
        };
        const close = (force = false) => {
            if (!force && form.getAttribute('aria-busy') === 'true') return false;
            if (!force && !editing) forgetPendingCreate();
            editor.hidden = true;
            editing = null;
            core.showFeedback(feedback);
            coordinator.closed('module');
            return true;
        };
        coordinator.register('module', close);

        const resetForCreate = order => {
            form.reset();
            form.action = createUrl;
            setMethod('POST');
            editing = null;
            form.elements.authoring_request_id.value = core.uuid();
            form.elements.authoring_version.value = String(core.authoringVersion);
            form.elements.order.value = String(order);
            deleteButton.hidden = true;
            form.querySelector('.studio-inline-module__copy span').textContent = 'وحدة جديدة';
        };
        const open = trigger => {
            if (window.RoknAdminRequest.mutationsAreBlocked()
                || window.RoknCourseVideoUpload?.isBusy()) return false;
            const editTrigger = trigger.closest('[data-inline-module-edit]');
            const editNode = editTrigger?.closest('.outline-module');
            const editPayload = editTrigger ? outline.getModule(editTrigger.dataset.moduleId) : null;
            if ((editTrigger && (!editNode || !editPayload)) || !coordinator.activate('module')) return false;
            if (editTrigger) {
                forgetPendingCreate();
                const node = editNode;
                const payload = editPayload;
                node.after(outline.host);
                editing = {id: Number(payload.id), node, delete_url: payload.delete_url};
                form.reset();
                form.action = payload.update_url;
                setMethod('PATCH');
                form.elements.authoring_version.value = String(core.authoringVersion);
                form.elements.order.value = String(payload.order);
                form.elements.title_ar.value = payload.title_ar || payload.title;
                deleteButton.hidden = false;
                form.querySelector('.studio-inline-module__copy span').textContent = 'تعديل الوحدة';
            } else {
                if (!editor.hidden && pendingRecovery) forgetPendingCreate();
                const order = Number(trigger.dataset.insertOrder)
                    || outline.modulesList.querySelectorAll(':scope > .outline-module').length + 1;
                if (trigger.classList.contains('outline-module-insert')) trigger.after(outline.host);
                else outline.modulesList.append(outline.host);
                resetForCreate(order);
            }
            core.showFeedback(feedback);
            editor.hidden = false;
            form.elements.title_ar.focus();
            editor.scrollIntoView({behavior: 'smooth', block: 'center'});
            return true;
        };
        const moduleNode = module => {
            const node = document.createElement('article');
            node.className = 'outline-module';
            node.dataset.moduleId = String(module.id);
            const contentId = `module-${module.id}-content`;
            node.innerHTML = `<header class="outline-module__header"><button type="button" class="outline-module__drag studio-authoring-control" aria-label="اسحب لترتيب الوحدة"><i class="fa fa-bars" aria-hidden="true"></i></button><button type="button" class="outline-module__toggle" aria-expanded="true" aria-controls="${contentId}"><span class="outline-module__number"></span><span class="outline-module__name"><small></small><strong></strong></span><span class="outline-module__count">0 عناصر</span><i class="fa fa-chevron-up" aria-hidden="true"></i></button><button type="button" data-inline-module-edit data-module-id="${module.id}" class="outline-module__edit studio-authoring-control" aria-label="تعديل الوحدة"><i class="fa fa-pencil" aria-hidden="true"></i></button></header><div class="outline-module__content studio-sortable-sections" id="${contentId}" data-module-id="${module.id}"><div class="outline-item-actions studio-authoring-control" data-module-actions="${module.id}"><button type="button" data-inline-editor-open="project" data-module-id="${module.id}"><i class="fa fa-briefcase" aria-hidden="true"></i> إضافة مشروع عبور اختياري</button></div></div>`;
            node.querySelector('.outline-module__name strong').textContent = module.title;
            return node;
        };
        const validModule = (response, expectedId = null) => response?.success === true
            && Number.isSafeInteger(Number(response.module?.id))
            && (!expectedId || Number(response.module.id) === Number(expectedId))
            && typeof response.module?.title === 'string' && response.module.title.trim() !== ''
            && typeof response.module?.update_url === 'string' && response.module.update_url !== ''
            && typeof response.module?.delete_url === 'string' && response.module.delete_url !== '';

        form.addEventListener('submit', event => {
            event.preventDefault();
            if (form.getAttribute('aria-busy') === 'true') return;
            const target = editing;
            const creating = !target;
            const submittedVersion = creating && pendingRecovery
                ? Number(pendingRecovery.expectedVersion)
                : core.authoringVersion;
            if (creating && pendingRecovery) rememberPendingCreate(submittedVersion);
            let firstSectionTrigger = null;
            void core.mutate(async () => {
                const expectedVersion = creating ? submittedVersion : core.authoringVersion;
                const body = core.authoringFormData(form, expectedVersion);
                const response = await core.request(form.action, {
                    method: 'POST', headers: core.mutationHeaders(form), body,
                });
                const nextVersion = core.requireMutation(response, expectedVersion);
                if (!validModule(response, target?.id)) throw core.invalid();
                const knownModule = outline.getModule(response.module.id);
                const staleCreateReceipt = creating && pendingRecovery
                    && nextVersion < core.authoringVersion;
                if (staleCreateReceipt && !knownModule) {
                    forgetPendingCreate();
                    throw new window.RoknAdminRequest.AdminRequestError(
                        'حُفظت الوحدة سابقًا ثم حُذفت من نسخة أحدث\nأضفها من جديد إذا كنت ما زلت تحتاجها',
                        409,
                        'authoring_replay_superseded'
                    );
                }
                const canonical = staleCreateReceipt
                    ? knownModule
                    : {
                        ...response.module,
                        sections: knownModule?.sections || response.module.sections || [],
                    };
                core.syncVersion(nextVersion);
                outline.putModule(canonical);
                let node;
                if (creating) {
                    document.getElementById('studioEmptyCourse')?.remove();
                    node = outline.modulesList.querySelector(`:scope > .outline-module[data-module-id="${canonical.id}"]`);
                    if (!node) {
                        node = moduleNode(canonical);
                        if (outline.host.parentElement === outline.modulesList) outline.host.before(node);
                        else outline.modulesList.append(node);
                        const list = node.querySelector('.studio-sortable-sections');
                        outline.rebuildSectionInsertions(list);
                        outline.initSectionSortable(list);
                    } else {
                        node.querySelector('.outline-module__name strong').textContent = canonical.title;
                    }
                } else {
                    node = target.node;
                    node.querySelector('.outline-module__name strong').textContent = canonical.title;
                }
                outline.refreshModules();
                outline.rebuildModuleInsertions();
                if (creating) forgetPendingCreate();
                form.reset();
                close(true);
                if (creating && canonical.sections.length === 0) {
                    core.notify('تم حفظ الوحدة\nأضف أول مقطع');
                    firstSectionTrigger = node.querySelector('.outline-item-insert');
                } else if (!creating) {
                    core.notify('تم حفظ الوحدة');
                }
            }, {
                feedback,
                form,
                recoverableConflictCodes: creating ? ['authoring_in_progress'] : [],
                onReconcile: error => {
                    if (creating && ['mutation_outcome_unknown', 'invalid_authoring_response'].includes(error?.code)) {
                        rememberPendingCreate(submittedVersion);
                    }
                },
            }).then(() => {
                // Switching editors is deliberately deferred until mutate has
                // removed the form/studio busy gates. Calling the coordinator
                // from inside the mutation asks it to close a busy module form,
                // so the legitimate transition is rejected as if it were a
                // second user action during save.
                if (firstSectionTrigger?.isConnected) {
                    sectionEditor.openNew(firstSectionTrigger);
                }
            });
        });

        deleteButton.addEventListener('click', () => {
            const target = editing;
            if (!target) return;
            const payload = outline.getModule(target.id);
            const sections = Array.isArray(payload?.sections) ? payload.sections : [];
            const reelCount = sections.filter(section => section.type === 'lesson').length;
            const hasProject = sections.some(section => section.type === 'project');
            const summary = [reelCount ? `${reelCount} مقطع` : '', hasProject ? 'ومشروع العبور' : ''].filter(Boolean).join(' ');
            if (!window.confirm(summary ? `سيُحذف ${summary} مع الوحدة\nهل تريد المتابعة؟` : 'حذف هذه الوحدة؟')) return;
            void core.mutate(async () => {
                const expectedVersion = core.authoringVersion;
                const response = await core.request(target.delete_url, {
                    method: 'DELETE',
                    headers: core.mutationHeaders(form, {'Content-Type': 'application/json'}),
                    body: JSON.stringify({authoring_version: expectedVersion}),
                });
                const nextVersion = core.requireMutation(response, expectedVersion);
                const expectedIds = sections.map(section => Number(section.id)).sort((a, b) => a - b);
                const deletedIds = Array.isArray(response.section_ids)
                    ? response.section_ids.map(Number).sort((a, b) => a - b)
                    : [];
                if (Number(response.deleted_module_id) !== Number(target.id)
                    || JSON.stringify(expectedIds) !== JSON.stringify(deletedIds)) throw core.invalid();
                core.syncVersion(nextVersion);
                target.node.remove();
                outline.removeModule(target.id);
                close(true);
                outline.refreshModules();
                outline.rebuildModuleInsertions();
                core.notify('تم حذف الوحدة');
            }, {feedback, form});
        });

        document.addEventListener('click', event => {
            const trigger = event.target.closest('[data-inline-module-open], [data-inline-module-edit]');
            if (trigger) return open(trigger);
            if (event.target.closest('[data-inline-module-close]')) close();
        });

        const recovered = readPendingCreate();
        const createTrigger = recovered && document.querySelector('[data-inline-module-open]');
        pendingRecovery = recovered;
        if (recovered && createTrigger && open(createTrigger) !== false) {
            const allowedFields = new Set(['authoring_request_id', 'authoring_version', 'order', 'title_ar']);
            recovered.fields.forEach(([name, value]) => {
                const control = allowedFields.has(name) ? form.elements.namedItem(name) : null;
                if (control && 'value' in control && typeof value === 'string') control.value = value;
            });
            form.querySelector('.studio-inline-module__copy span').textContent = 'استكمال حفظ الوحدة';
            core.showFeedback(feedback, 'لم يصل تأكيد الحفظ\nاضغط حفظ الوحدة لاستكمال المحاولة');
        }
    });
})(window, document);
