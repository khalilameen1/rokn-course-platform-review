'use strict';

(function (window, document) {
    window.RoknCourseStudio.register('outline', function (core) {
        const modulesList = document.getElementById('studioModulesList');
        const host = document.getElementById('studioInlineAuthoring');
        if (!modulesList || !host) return;

        const graph = core.graph;
        const modules = new Map((graph.modules || []).map(module => [Number(module.id), {
            ...module,
            sections: Array.isArray(module.sections) ? module.sections.slice() : [],
        }]));
        const initializedSectionLists = new WeakSet();

        const getModule = id => modules.get(Number(id)) || null;
        const getSection = id => {
            const expected = Number(id);
            for (const module of modules.values()) {
                const section = module.sections.find(candidate => Number(candidate.id) === expected);
                if (section) return section;
            }
            return null;
        };
        const putModule = module => modules.set(Number(module.id), {
            ...module,
            sections: Array.isArray(module.sections) ? module.sections.slice() : getModule(module.id)?.sections || [],
        });
        const removeModule = id => modules.delete(Number(id));
        const putSection = section => {
            const sectionId = Number(section.id);
            for (const [moduleId, module] of modules) {
                const next = module.sections.filter(candidate => Number(candidate.id) !== sectionId);
                if (moduleId === Number(section.module_id)) next.push({...section});
                modules.set(moduleId, {...module, sections: next.sort((a, b) => Number(a.order) - Number(b.order))});
            }
        };
        const removeSection = id => {
            const sectionId = Number(id);
            for (const [moduleId, module] of modules) {
                modules.set(moduleId, {
                    ...module,
                    sections: module.sections.filter(section => Number(section.id) !== sectionId),
                });
            }
        };
        const replaceStore = canonical => {
            modules.clear();
            canonical.forEach(putModule);
        };

        const refreshModule = node => {
            if (!node) return;
            const count = node.querySelectorAll(':scope > .outline-module__content > .outline-item').length;
            const label = node.querySelector('.outline-module__count');
            if (label) label.textContent = `${count} عناصر`;
        };
        const refreshModules = () => {
            Array.from(modulesList.querySelectorAll(':scope > .outline-module[data-module-id]')).forEach((node, index) => {
                const number = node.querySelector('.outline-module__number');
                const label = node.querySelector('.outline-module__name small');
                if (number) number.textContent = String(index + 1);
                if (label) label.textContent = `الوحدة ${index + 1}`;
                refreshModule(node);
            });
        };

        const insertionButton = (kind, ownerId, order) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = kind === 'module'
                ? 'outline-module-insert studio-authoring-control'
                : 'outline-item-insert studio-authoring-control';
            if (kind === 'module') button.dataset.inlineModuleOpen = '';
            else {
                button.dataset.inlineEditorOpen = 'lesson';
                button.dataset.moduleId = String(ownerId);
            }
            button.dataset.insertOrder = String(order);
            button.setAttribute('aria-label', kind === 'module' ? 'إضافة وحدة هنا' : 'إضافة مقطع هنا');
            button.innerHTML = `<i class="fa fa-plus" aria-hidden="true"></i><span>${kind === 'module' ? 'وحدة هنا' : 'مقطع هنا'}</span>`;
            return button;
        };
        const ensureProjectLast = list => {
            const project = list?.querySelector(':scope > .outline-item[data-section-type="project"]');
            const actions = list?.querySelector(':scope > .outline-item-actions');
            if (project) list.insertBefore(project, actions || null);
        };
        const rebuildSectionInsertions = list => {
            if (!list || !core.canAuthor || !list.dataset.moduleId) return;
            ensureProjectLast(list);
            list.querySelectorAll(':scope > .outline-item-insert').forEach(button => button.remove());
            const lessons = Array.from(list.querySelectorAll(':scope > .outline-item[data-section-type="lesson"]'));
            lessons.forEach((lesson, index) => lesson.before(insertionButton('section', list.dataset.moduleId, index + 1)));
            const project = list.querySelector(':scope > .outline-item[data-section-type="project"]');
            const actions = list.querySelector(':scope > .outline-item-actions');
            list.insertBefore(insertionButton('section', list.dataset.moduleId, lessons.length + 1), project || actions || null);
        };
        const rebuildModuleInsertions = () => {
            if (!core.canAuthor) return;
            modulesList.querySelectorAll(':scope > .outline-module-insert').forEach(button => button.remove());
            const nodes = Array.from(modulesList.querySelectorAll(':scope > .outline-module[data-module-id]'));
            nodes.forEach((node, index) => node.before(insertionButton('module', '', index + 1)));
            modulesList.append(insertionButton('module', '', nodes.length + 1));
        };

        const validCanonicalModules = canonical => Array.isArray(canonical) && canonical.every(module => {
            if (!Number.isSafeInteger(Number(module?.id))
                || typeof module?.title !== 'string' || module.title.trim() === ''
                || typeof module?.update_url !== 'string' || module.update_url === ''
                || typeof module?.delete_url !== 'string' || module.delete_url === ''
                || !Array.isArray(module?.sections)) return false;
            const ordered = module.sections.slice().sort((a, b) => Number(a.order) - Number(b.order));
            const projects = ordered.filter(section => section.type === 'project');
            return projects.length <= 1
                && (!projects.length || ordered.at(-1)?.type === 'project')
                && ordered.every(section => Number.isSafeInteger(Number(section?.id))
                    && Number(section.module_id) === Number(module.id)
                    && ['lesson', 'project'].includes(section.type)
                    && typeof section.title === 'string' && section.title.trim() !== ''
                    && typeof section.row_label === 'string' && section.row_label.trim() !== ''
                    && typeof section.update_url === 'string' && section.update_url !== ''
                    && typeof section.delete_url === 'string' && section.delete_url !== '');
        });
        const applyCanonical = canonical => {
            canonical.slice().sort((a, b) => Number(a.order) - Number(b.order)).forEach(module => {
                const moduleNode = modulesList.querySelector(`:scope > .outline-module[data-module-id="${module.id}"]`);
                if (!moduleNode) return;
                modulesList.append(moduleNode);
                const list = moduleNode.querySelector('.studio-sortable-sections');
                const actions = list?.querySelector(':scope > .outline-item-actions');
                module.sections.slice().sort((a, b) => Number(a.order) - Number(b.order)).forEach(section => {
                    const node = document.querySelector(`.outline-item[data-section-id="${section.id}"]`);
                    if (node && list) list.insertBefore(node, actions || null);
                });
                ensureProjectLast(list);
                rebuildSectionInsertions(list);
            });
            replaceStore(canonical);
            refreshModules();
            rebuildModuleInsertions();
        };
        const reorder = (url, payload, validatesLayout, message) => core.mutate(async () => {
            const expectedVersion = core.authoringVersion;
            const response = await core.request(url, {
                method: 'POST',
                headers: core.mutationHeaders(null, {'Content-Type': 'application/json'}),
                body: JSON.stringify({...payload, authoring_version: expectedVersion}),
            });
            const nextVersion = core.requireMutation(response, expectedVersion);
            if (!validCanonicalModules(response.modules) || !validatesLayout(response.modules)) throw core.invalid();
            core.syncVersion(nextVersion);
            applyCanonical(response.modules);
            core.notify(message);
        }, {reloadOnError: true});

        const editorIsOpen = () => !document.getElementById('studioInlineEditor')?.hidden
            || !document.getElementById('studioInlineModuleEditor')?.hidden;
        const initSectionSortable = list => {
            if (!core.canAuthor || !window.Sortable || !list || !list.dataset.moduleId || initializedSectionLists.has(list)) return;
            initializedSectionLists.add(list);
            ensureProjectLast(list);
            new Sortable(list, {
                group: 'studio-lessons',
                handle: '.outline-item__drag',
                draggable: '.outline-item[data-section-type="lesson"]',
                filter: '.outline-item[data-section-type="project"]',
                animation: 160,
                ghostClass: 'is-dragging',
                onMove: () => !editorIsOpen(),
                onEnd: event => {
                    if (event.from === event.to && event.oldIndex === event.newIndex) return;
                    const lists = Array.from(new Set([event.from, event.to]));
                    lists.forEach(ensureProjectLast);
                    const sections = lists.flatMap(target => Array.from(target.querySelectorAll(':scope > .outline-item')).map((node, index) => ({
                        id: Number(node.dataset.sectionId), order: index + 1, module_id: Number(target.dataset.moduleId),
                    })));
                    reorder(
                        graph.section_reorder_url,
                        {sections},
                        canonical => sections.every(expected => canonical.some(module => module.sections.some(section => (
                            Number(section.id) === Number(expected.id)
                            && Number(section.module_id) === Number(expected.module_id)
                            && Number(section.order) === Number(expected.order)
                        )))),
                        'تم حفظ ترتيب المحتوى'
                    );
                },
            });
        };

        document.querySelectorAll('.studio-sortable-sections[data-module-id]:not([data-module-id=""])').forEach(list => {
            rebuildSectionInsertions(list);
            initSectionSortable(list);
        });
        rebuildModuleInsertions();
        if (core.canAuthor && window.Sortable) {
            new Sortable(modulesList, {
                handle: '.outline-module__drag',
                draggable: '.outline-module[data-module-id]',
                animation: 160,
                ghostClass: 'is-dragging',
                onMove: () => !editorIsOpen(),
                onEnd: event => {
                    if (event.oldIndex === event.newIndex) return;
                    const order = Array.from(modulesList.querySelectorAll(':scope > .outline-module[data-module-id]'))
                        .map((node, index) => ({id: Number(node.dataset.moduleId), order: index + 1}));
                    reorder(
                        graph.module_reorder_url,
                        {modules: order},
                        canonical => order.every(expected => canonical.some(module => Number(module.id) === expected.id && Number(module.order) === expected.order)),
                        'تم حفظ ترتيب الوحدات'
                    );
                },
            });
        }

        document.addEventListener('click', event => {
            const toggle = event.target.closest('.outline-module__toggle[aria-controls]');
            if (!toggle) return;
            const content = document.getElementById(toggle.getAttribute('aria-controls'));
            if (!content) return;
            const expanded = toggle.getAttribute('aria-expanded') !== 'false';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            content.hidden = expanded;
        });

        core.provide('outline', {
            modulesList,
            host,
            getModule,
            getSection,
            putModule,
            removeModule,
            putSection,
            removeSection,
            refreshModule,
            refreshModules,
            rebuildSectionInsertions,
            rebuildModuleInsertions,
            ensureProjectLast,
            initSectionSortable,
        });
    });
})(window, document);
