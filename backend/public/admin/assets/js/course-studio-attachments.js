'use strict';

(function (window, document) {
    window.RoknCourseStudio.register('attachments', function (core) {
        const panel = document.getElementById('studioCourseAttachments');
        const graphNode = document.getElementById('coursePdfAuthoringGraph');
        const list = document.getElementById('studioCoursePdfList');
        const empty = document.getElementById('studioCoursePdfEmpty');
        const editor = document.getElementById('studioCoursePdfEditor');
        const form = document.getElementById('coursePdfForm');
        if (!panel || !graphNode || !list || !editor || !form) return;

        let graph;
        try {
            graph = JSON.parse(graphNode.textContent || '{}');
        } catch (_) {
            return core.reconcile(core.invalid());
        }

        const pdfs = new Map((graph.pdfs || []).map(pdf => [Number(pdf.id), pdf]));
        const feedback = form.querySelector('[data-pdf-feedback]');
        const fileInput = form.elements.pdf_file;
        const submitLabel = form.querySelector('[data-studio-pdf-submit-label]');
        const fileHelp = form.querySelector('[data-studio-pdf-file-help]');
        const fileRequired = form.querySelector('[data-studio-pdf-file-required]');
        const deleteButton = form.querySelector('[data-studio-pdf-delete]');
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const activeInput = form.querySelector('input[type="checkbox"][name="is_active"]');
        const activeStatus = document.getElementById('statusBadge');
        const createUrl = String(graph.store_url || '');
        let editingId = null;

        const validPdf = pdf => Number.isSafeInteger(Number(pdf?.id))
            && typeof pdf?.title === 'string' && pdf.title.trim() !== ''
            && typeof pdf?.formatted_file_size === 'string'
            && typeof pdf?.preview_url === 'string' && pdf.preview_url !== ''
            && typeof pdf?.update_url === 'string' && pdf.update_url !== ''
            && typeof pdf?.toggle_url === 'string' && pdf.toggle_url !== ''
            && typeof pdf?.delete_url === 'string' && pdf.delete_url !== '';

        const setMethod = method => {
            const override = form.elements._method;
            override.value = method;
            override.disabled = method === 'POST';
        };
        const clearFileSelection = () => {
            fileInput.value = '';
            filePreview?.classList.remove('show');
            if (fileName) fileName.textContent = '';
            if (fileSize) fileSize.textContent = '';
        };
        const setActive = active => {
            if (activeInput) activeInput.checked = active;
            if (!activeStatus) return;
            activeStatus.textContent = active ? 'مفعّل' : 'غير مفعّل';
            activeStatus.classList.toggle('active', active);
            activeStatus.classList.toggle('inactive', !active);
        };

        const card = pdf => {
            const node = document.createElement('article');
            node.className = 'studio-attachment';
            node.dataset.pdfId = String(pdf.id);
            node.innerHTML = '<span class="studio-attachment__drag" aria-label="اسحب لترتيب الملف"><i class="fa fa-bars" aria-hidden="true"></i></span><span class="studio-attachment__icon"><i class="fa fa-file-pdf-o" aria-hidden="true"></i></span><span class="studio-attachment__copy"><strong></strong><small></small></span><span class="studio-attachment__actions"><a target="_blank" rel="noopener" aria-label="فتح الملف"><i class="fa fa-eye" aria-hidden="true"></i></a><button type="button" data-studio-pdf-toggle aria-label=""><i class="fa" aria-hidden="true"></i></button><button type="button" data-studio-pdf-edit aria-label="تعديل الملف"><i class="fa fa-pencil" aria-hidden="true"></i></button></span>';
            node.querySelector('strong').textContent = pdf.title;
            node.querySelector('small').textContent = `${pdf.formatted_file_size} · ${pdf.is_active ? 'ظاهر للطلاب' : 'مخفي'}`;
            node.querySelector('a').href = pdf.preview_url;
            const toggle = node.querySelector('[data-studio-pdf-toggle]');
            toggle.dataset.studioPdfToggle = String(pdf.id);
            toggle.setAttribute('aria-label', pdf.is_active ? 'إخفاء الملف' : 'إظهار الملف');
            toggle.querySelector('i').classList.add(pdf.is_active ? 'fa-eye-slash' : 'fa-eye');
            node.querySelector('[data-studio-pdf-edit]').dataset.studioPdfEdit = String(pdf.id);
            return node;
        };

        const render = ordered => {
            const canonical = ordered.slice().sort((left, right) => Number(left.order) - Number(right.order));
            pdfs.clear();
            canonical.forEach(pdf => pdfs.set(Number(pdf.id), pdf));
            list.replaceChildren(...canonical.map(card));
            empty.hidden = canonical.length > 0;
        };

        const openPanel = () => {
            panel.hidden = false;
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
        };
        const closePanel = () => {
            if (form.getAttribute('aria-busy') === 'true') return;
            editor.hidden = true;
            panel.hidden = true;
            core.showFeedback(feedback);
        };
        const resetCreate = () => {
            form.reset();
            clearFileSelection();
            form.action = createUrl;
            setMethod('POST');
            editingId = null;
            form.elements.authoring_request_id.value = core.uuid();
            form.elements.authoring_version.value = String(core.authoringVersion);
            form.elements.order.value = String(Math.max(0, ...Array.from(pdfs.values()).map(pdf => Number(pdf.order) || 0)) + 1);
            setActive(true);
            fileInput.required = true;
            if (fileHelp) fileHelp.textContent = 'اسحب الملف هنا أو انقر للاختيار';
            if (fileRequired) fileRequired.hidden = false;
            if (submitLabel) submitLabel.textContent = 'حفظ الملف';
            deleteButton.hidden = true;
            core.showFeedback(feedback);
        };
        const openCreate = () => {
            openPanel();
            resetCreate();
            editor.hidden = false;
            form.elements.title?.focus();
        };
        const openEdit = id => {
            const pdf = pdfs.get(Number(id));
            if (!pdf) return;
            openPanel();
            form.reset();
            clearFileSelection();
            editingId = Number(pdf.id);
            form.action = pdf.update_url;
            setMethod('PATCH');
            form.elements.authoring_version.value = String(core.authoringVersion);
            form.elements.title.value = pdf.title || '';
            form.elements.title_en.value = pdf.title_en || '';
            form.elements.description.value = pdf.description || '';
            form.elements.description_en.value = pdf.description_en || '';
            form.elements.order.value = String(pdf.order);
            setActive(Boolean(pdf.is_active));
            fileInput.required = false;
            if (fileHelp) fileHelp.textContent = 'اختر ملفًا جديدًا للاستبدال أو اتركه كما هو';
            if (fileRequired) fileRequired.hidden = true;
            if (submitLabel) submitLabel.textContent = 'حفظ التغييرات';
            deleteButton.hidden = false;
            core.showFeedback(feedback);
            editor.hidden = false;
            form.elements.title?.focus();
        };

        document.addEventListener('click', event => {
            if (event.target.closest('[data-studio-attachments-open]')) return openPanel();
            if (event.target.closest('[data-studio-attachments-close]')) return closePanel();
            if (event.target.closest('[data-studio-pdf-add]')) return openCreate();
            if (event.target.closest('[data-studio-pdf-cancel]')) {
                editor.hidden = true;
                core.showFeedback(feedback);
                return;
            }
            const edit = event.target.closest('[data-studio-pdf-edit]');
            if (edit) return openEdit(edit.dataset.studioPdfEdit);
            const toggle = event.target.closest('[data-studio-pdf-toggle]');
            if (toggle) {
                const id = Number(toggle.dataset.studioPdfToggle);
                const current = pdfs.get(id);
                if (!current) return;
                void core.mutate(async () => {
                    const expectedVersion = core.authoringVersion;
                    const response = await core.request(current.toggle_url, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': core.csrf},
                        body: JSON.stringify({authoring_version: expectedVersion}),
                    });
                    const nextVersion = core.requireMutation(response, expectedVersion);
                    if (!validPdf(response.pdf) || Number(response.pdf.id) !== id || response.pdf.is_active === current.is_active) throw core.invalid();
                    core.syncVersion(nextVersion);
                    pdfs.set(id, response.pdf);
                    render(Array.from(pdfs.values()));
                    core.notify(response.message || 'تم تحديث ظهور الملف');
                }, {reloadOnError: true});
            }
        });

        if (window.location.hash === '#studioCourseAttachments') openPanel();

        form.addEventListener('submit', event => {
            event.preventDefault();
            if (form.getAttribute('aria-busy') === 'true') return;
            const targetId = editingId;
            void core.mutate(async () => {
                const expectedVersion = core.authoringVersion;
                const body = new FormData(form);
                body.set('authoring_version', String(expectedVersion));
                const response = await core.request(form.action, {
                    method: 'POST', headers: {'X-CSRF-TOKEN': core.csrf}, body, timeout: 120000,
                });
                if (!validPdf(response.pdf) || (targetId && Number(response.pdf.id) !== targetId)) throw core.invalid();
                const duplicateCreate = !targetId && pdfs.has(Number(response.pdf.id));
                const nextVersion = core.requireMutation(response, expectedVersion, !duplicateCreate);
                core.syncVersion(nextVersion);
                pdfs.set(Number(response.pdf.id), response.pdf);
                render(Array.from(pdfs.values()));
                resetCreate();
                editor.hidden = true;
                core.notify(response.message || 'تم حفظ الملف');
            }, {feedback, form});
        });

        deleteButton.addEventListener('click', () => {
            const id = editingId;
            const pdf = pdfs.get(Number(id));
            if (!pdf || !window.confirm('حذف هذا الملف من الكورس؟')) return;
            void core.mutate(async () => {
                const expectedVersion = core.authoringVersion;
                const response = await core.request(pdf.delete_url, {
                    method: 'DELETE',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': core.csrf},
                    body: JSON.stringify({authoring_version: expectedVersion}),
                });
                const nextVersion = core.requireMutation(response, expectedVersion);
                if (!response?.pdf?.deleted || Number(response.pdf.id) !== Number(id)) throw core.invalid();
                core.syncVersion(nextVersion);
                pdfs.delete(Number(id));
                render(Array.from(pdfs.values()));
                resetCreate();
                editor.hidden = true;
                core.notify(response.message || 'تم حذف الملف');
            }, {feedback, form});
        });

        if (window.Sortable) {
            new Sortable(list, {
                handle: '.studio-attachment__drag',
                draggable: '.studio-attachment',
                animation: 160,
                ghostClass: 'is-dragging',
                onEnd: event => {
                    if (event.oldIndex === event.newIndex) return;
                    const order = Array.from(list.querySelectorAll(':scope > .studio-attachment')).map(node => Number(node.dataset.pdfId));
                    void core.mutate(async () => {
                        const expectedVersion = core.authoringVersion;
                        const response = await core.request(graph.reorder_url, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': core.csrf},
                            body: JSON.stringify({order, authoring_version: expectedVersion}),
                        });
                        const nextVersion = core.requireMutation(response, expectedVersion);
                        if (!Array.isArray(response.pdfs)
                            || response.pdfs.length !== order.length
                            || response.pdfs.some(pdf => !validPdf(pdf))
                            || response.pdfs.some((pdf, index) => Number(pdf.id) !== order[index])) throw core.invalid();
                        core.syncVersion(nextVersion);
                        render(response.pdfs);
                        core.notify(response.message || 'تم حفظ ترتيب الملفات');
                    }, {reloadOnError: true});
                },
            });
        }
    });
})(window, document);
