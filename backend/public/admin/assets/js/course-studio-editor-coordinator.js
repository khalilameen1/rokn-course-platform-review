'use strict';

(function (window) {
    window.RoknCourseStudio.register('editor-coordinator', function (core) {
        const editors = new Map();
        let active = null;

        core.provide('editor-coordinator', {
            register(name, close) {
                if (editors.has(name)) throw new Error(`Course Studio editor already registered: ${name}`);
                editors.set(name, close);
            },
            activate(name) {
                for (const [otherName, close] of editors) {
                    if (otherName === name || close() !== false) continue;
                    return false;
                }
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
