'use strict';

(function (window) {
    window.RoknCourseStudio.register('editor-coordinator', function (core) {
        const editors = new Map();
        let active = null;
        // Compare editable content, not hidden version/intent fields which can
        // legitimately change when another studio form is saved.
        const snapshot = form => JSON.stringify(Array.from(form.querySelectorAll(
            'input:not([type="hidden"]), textarea, select, [name="bunny_video_claim"]'
        ), control => {
            if (control.type === 'file') return Array.from(control.files, file => [file.name, file.size, file.lastModified]);
            if (['checkbox', 'radio'].includes(control.type)) return [control.value, control.checked];
            return control.value;
        }));
        const confirmDiscard = (name = active) => {
            const editor = editors.get(name);
            return name !== active || !editor || editor.saved === snapshot(editor.form)
                || window.confirm('لديك تعديلات غير محفوظة\nهل تريد تجاهلها والمتابعة؟');
        };

        core.provide('editor-coordinator', {
            register(name, close, form) {
                if (editors.has(name)) throw new Error(`Course Studio editor already registered: ${name}`);
                editors.set(name, {close, form, saved: snapshot(form)});
            },
            markClean(name) {
                const editor = editors.get(name);
                editor.saved = snapshot(editor.form);
            },
            confirmDiscard,
            activate(name) {
                // Replacing another section/module uses the same form too.
                if (active && editors.get(active).close() === false) return false;
                active = name;
                return true;
            },
            closed(name) {
                if (active === name) active = null;
            },
            isOpen() {
                return active !== null;
            },
        });
    });
})(window);
