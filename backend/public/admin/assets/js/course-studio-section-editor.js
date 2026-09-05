'use strict';

(function (window, document) {
    window.RoknCourseStudio.register('section-editor', function (core) {
        if (!core.canAuthor) return;
        const outline = core.use('outline');
        const coordinator = core.use('editor-coordinator');
        const editor = document.getElementById('studioInlineEditor');
        const form = document.getElementById('sectionForm');
        if (!editor || !form) return;

        const lessonFields = document.getElementById('studioInlineLessonFields');
        const projectFields = document.getElementById('studioInlineProjectFields');
        const sectionType = document.getElementById('section_type');
        const titleInput = form.elements.title_ar;
        const projectRequirements = form.elements.project_requirements_ar;
        const videoInput = document.getElementById('bunny_video');
        const saveLabel = document.querySelector('#studioInlineSaveSection span');
        const eyebrow = document.getElementById('studioInlineEditorEyebrow');
        const title = document.getElementById('studioInlineEditorTitle');
        const deleteButton = document.getElementById('studioInlineDeleteSection');
        const feedback = document.getElementById('studioInlineFeedback');
        const createUrl = form.action;
        let editing = null;

        core.registerBusySource(() => Boolean(window.RoknCourseVideoUpload?.isBusy()));
        new MutationObserver(core.refreshBusy).observe(form, {attributes: true, attributeFilter: ['aria-busy']});

        const setMethod = method => {
            const override = form.elements._method;
            override.value = method;
            override.disabled = method === 'POST';
        };
        const setMode = (mode, isEditing = false, videoRequired = !isEditing) => {
            const project = mode === 'project';
            sectionType.value = project ? 'project' : 'lesson';
            lessonFields.hidden = project;
            projectFields.hidden = !project;
            projectRequirements.required = project;
            videoInput.required = !project && videoRequired;
            videoInput.dataset.videoRequired = !project && videoRequired ? 'true' : 'false';
            eyebrow.textContent = isEditing ? (project ? 'تعديل المشروع' : 'تعديل المقطع') : (project ? 'مشروع عبور' : 'مقطع جديد');
            title.textContent = isEditing ? 'عدّل العنصر في موضعه' : (project ? 'أضف المشروع بعد مقاطع الوحدة' : 'أضف المقطع داخل الوحدة');
            saveLabel.textContent = isEditing ? 'حفظ التعديل' : (project ? 'حفظ المشروع' : 'حفظ المقطع');
        };
        const listFor = moduleId => document.querySelector(`.studio-sortable-sections[data-module-id="${Number(moduleId)}"]`);
        const expandList = list => {
            if (!list?.hidden) return;
            list.hidden = false;
            list.closest('.outline-module')?.querySelector('.outline-module__toggle')?.setAttribute('aria-expanded', 'true');
        };
        const close = (force = false) => {
            if (!force && (form.getAttribute('aria-busy') === 'true' || window.RoknCourseVideoUpload?.isBusy())) return false;
            editor.hidden = true;
            editing = null;
            core.showFeedback(feedback);
            coordinator.closed('section');
            return true;
        };
        coordinator.register('section', close);

        const resetForCreate = (moduleId, order, mode) => {
            form.reset();
            form.action = createUrl;
            form.dataset.sectionId = '';
            setMethod('POST');
            editing = null;
            form.elements.authoring_request_id.value = core.uuid();
            form.elements.authoring_version.value = String(core.authoringVersion);
            form.elements.module_id.value = String(moduleId);
            form.elements.order.value = String(order);
            deleteButton.hidden = true;
            setMode(mode, false, mode === 'lesson');
            window.RoknCourseVideoUpload?.setSectionContext(null, mode === 'lesson');
        };
        const openNew = trigger => {
            if (window.RoknAdminRequest.mutationsAreBlocked() || window.RoknCourseVideoUpload?.isBusy()) return;
            const moduleId = Number(trigger?.dataset.moduleId);
            const list = listFor(moduleId);
            if (!list || !coordinator.activate('section')) return;
            expandList(list);
            const order = Number(trigger.dataset.insertOrder)
                || list.querySelectorAll(':scope > .outline-item[data-section-type="lesson"]').length + 1;
            const project = list.querySelector(':scope > .outline-item[data-section-type="project"]');
            if (trigger.dataset.inlineEditorOpen === 'project') {
                (project || list.querySelector(':scope > .outline-item-actions'))?.before(outline.host);
            } else {
                trigger.after(outline.host);
            }
            resetForCreate(moduleId, order, trigger.dataset.inlineEditorOpen === 'project' ? 'project' : 'lesson');
            editor.hidden = false;
            titleInput.focus();
            editor.scrollIntoView({behavior: 'smooth', block: 'center'});
        };
        const openExisting = trigger => {
            const payload = outline.getSection(trigger.dataset.inlineSectionEdit);
            if (!payload || window.RoknCourseVideoUpload?.isBusy()) return;
            const list = listFor(payload.module_id);
            const item = list?.querySelector(`.outline-item[data-section-id="${payload.id}"]`);
            if (!list || !item || !coordinator.activate('section')) return;
            expandList(list);
            item.after(outline.host);
            form.reset();
            editing = payload;
            form.action = payload.update_url;
            form.dataset.sectionId = String(payload.id);
            setMethod('PATCH');
            form.elements.authoring_version.value = String(core.authoringVersion);
            form.elements.module_id.value = String(payload.module_id);
            form.elements.order.value = String(payload.order);
            form.elements.title_ar.value = payload.title_ar || payload.title;
            form.elements.lesson_description_ar.value = payload.lesson_description_ar || '';
            form.elements.lesson_duration_minutes.value = payload.lesson_duration_minutes || '';
            form.elements.is_opened.checked = Boolean(payload.is_opened);
            form.elements.project_requirements_ar.value = payload.project_requirements_ar || '';
            form.elements.is_graduation_project.checked = Boolean(payload.is_graduation_project);
            const selectedTypes = new Set(payload.project_submission_types || []);
            form.querySelectorAll('[name="project_submission_types[]"]').forEach(input => input.checked = selectedTypes.has(input.value));
            const videoRequired = payload.type === 'lesson' && !payload.has_video;
            setMode(payload.type, true, videoRequired);
            deleteButton.hidden = false;
            window.RoknCourseVideoUpload?.setSectionContext(String(payload.id), videoRequired);
            core.showFeedback(feedback);
            editor.hidden = false;
            titleInput.focus();
            editor.scrollIntoView({behavior: 'smooth', block: 'center'});
        };

        const sectionNode = section => {
            const item = document.createElement('div');
            item.className = 'outline-item';
            item.dataset.sectionId = String(section.id);
            item.dataset.sectionType = section.type;
            if (section.type === 'lesson') {
                item.innerHTML = '<button type="button" class="outline-item__drag studio-authoring-control" aria-label="اسحب لترتيب المقطع"><i class="fa fa-ellipsis-v" aria-hidden="true"></i></button>';
            }
            const icon = document.createElement('span');
            icon.className = `outline-item__icon outline-item__icon--${section.type}`;
            icon.innerHTML = `<i class="fa ${section.type === 'project' ? 'fa-briefcase' : 'fa-play-circle'}" aria-hidden="true"></i>`;
            const copy = document.createElement('span');
            copy.className = 'outline-item__copy';
            const sectionTitle = document.createElement('strong');
            sectionTitle.textContent = section.title;
            const meta = document.createElement('small');
            meta.textContent = section.type === 'project'
                ? 'مشروع عبور بعد الوحدة'
                : `مقطع${section.lesson_duration_minutes ? ` · ${section.lesson_duration_minutes} دقيقة` : ''}${section.is_opened ? ' · مجاني' : ''}`;
            copy.append(sectionTitle, meta);
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'outline-item__edit studio-authoring-control';
            edit.dataset.inlineSectionEdit = String(section.id);
            edit.innerHTML = '<i class="fa fa-pencil" aria-hidden="true"></i><span>تعديل</span>';
            item.append(icon, copy, edit);
            return item;
        };
        const validSection = (response, expected) => {
            const section = response?.section;
            return response?.success === true
                && Number.isSafeInteger(Number(section?.id))
                && (!expected.id || Number(section.id) === Number(expected.id))
                && Number(section?.module_id) === Number(expected.moduleId)
                && section?.type === expected.type
                && typeof section?.title === 'string' && section.title.trim() !== ''
                && typeof section?.update_url === 'string' && section.update_url !== ''
                && typeof section?.delete_url === 'string' && section.delete_url !== '';
        };

        form.addEventListener('submit', event => {
            event.preventDefault();
            if (form.getAttribute('aria-busy') === 'true') return;
            const previous = editing;
            const creating = !previous;
            const type = sectionType.value;
            const claimRequired = type === 'lesson' && videoInput.dataset.videoRequired === 'true';
            if (claimRequired && !String(form.elements.bunny_video_claim?.value || '').trim()) return;
            const moduleId = Number(form.elements.module_id.value);
            const order = Number(form.elements.order.value);
            let focusNextLesson = false;
            void core.mutate(async () => {
                const expectedVersion = core.authoringVersion;
                const body = core.authoringFormData(form, expectedVersion);
                const response = await core.request(form.action, {
                    method: 'POST', headers: core.mutationHeaders(form), body, timeout: 30000,
                });
                const nextVersion = core.requireMutation(response, expectedVersion);
                if (!validSection(response, {id: previous?.id, moduleId, type})) throw core.invalid();
                core.syncVersion(nextVersion);
                const canonical = response.section;
                const list = listFor(canonical.module_id);
                const oldNode = creating ? null : document.querySelector(`.outline-item[data-section-id="${canonical.id}"]`);
                const node = sectionNode(canonical);
                if (oldNode) oldNode.replaceWith(node); else outline.host.before(node);
                outline.putSection(canonical);
                outline.rebuildSectionInsertions(list);
                outline.refreshModule(list?.closest('.outline-module'));
                if (type === 'lesson') {
                    if (!window.RoknCourseVideoUpload?.resetAfterCommit) throw core.invalid();
                    window.RoknCourseVideoUpload.resetAfterCommit();
                }
                if (!creating) {
                    close(true);
                    core.notify('تم حفظ التعديل');
                    return;
                }
                if (type === 'project') {
                    const actions = list?.querySelector(':scope > .outline-item-actions');
                    actions?.querySelector('[data-inline-editor-open="project"]')?.remove();
                    if (actions && !actions.querySelector('[data-project-present]')) {
                        const present = document.createElement('span');
                        present.dataset.projectPresent = '';
                        present.innerHTML = '<i class="fa fa-check-circle" aria-hidden="true"></i> مشروع العبور مضاف';
                        actions.append(present);
                    }
                    outline.ensureProjectLast(list);
                    close(true);
                    core.notify('تم حفظ مشروع العبور');
                    return;
                }
                resetForCreate(moduleId, order + 1, 'lesson');
                editor.hidden = false;
                focusNextLesson = true;
                core.notify('تم حفظ المقطع\nيمكنك إضافة المقطع التالي');
            }, {feedback, form}).then(() => {
                // resetForCreate runs while the studio is inert. Restore focus
                // only after the save gate is gone so keyboard authoring can
                // continue without a second click.
                if (!focusNextLesson || editor.hidden) return;
                titleInput.focus();
                editor.scrollIntoView({behavior: 'smooth', block: 'center'});
            });
        });

        deleteButton.addEventListener('click', () => {
            const target = editing;
            if (!target || !window.confirm('حذف هذا العنصر من الكورس؟')) return;
            void core.mutate(async () => {
                const expectedVersion = core.authoringVersion;
                const response = await core.request(target.delete_url, {
                    method: 'DELETE',
                    headers: core.mutationHeaders(form, {'Content-Type': 'application/json'}),
                    body: JSON.stringify({authoring_version: expectedVersion}),
                });
                const nextVersion = core.requireMutation(response, expectedVersion);
                if (Number(response.deleted_section_id) !== Number(target.id)) throw core.invalid();
                core.syncVersion(nextVersion);
                const list = listFor(target.module_id);
                list?.querySelector(`.outline-item[data-section-id="${target.id}"]`)?.remove();
                outline.removeSection(target.id);
                if (target.type === 'project') {
                    const actions = list?.querySelector(':scope > .outline-item-actions');
                    actions?.querySelector('[data-project-present]')?.remove();
                    if (actions && !actions.querySelector('[data-inline-editor-open="project"]')) {
                        const add = document.createElement('button');
                        add.type = 'button';
                        add.dataset.inlineEditorOpen = 'project';
                        add.dataset.moduleId = String(target.module_id);
                        add.innerHTML = '<i class="fa fa-briefcase" aria-hidden="true"></i> إضافة مشروع عبور اختياري';
                        actions.append(add);
                    }
                }
                outline.rebuildSectionInsertions(list);
                outline.refreshModule(list?.closest('.outline-module'));
                close(true);
                core.notify('تم حذف العنصر');
            }, {feedback, form});
        });

        document.addEventListener('click', event => {
            const add = event.target.closest('[data-inline-editor-open]');
            if (add) return openNew(add);
            const edit = event.target.closest('[data-inline-section-edit]');
            if (edit) return openExisting(edit);
            if (event.target.closest('[data-inline-editor-close]')) close();
        });

        core.provide('section-editor', {openNew});
    });
})(window, document);
