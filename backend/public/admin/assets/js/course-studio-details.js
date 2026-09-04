'use strict';

(function (window, document) {
    window.RoknCourseStudio.register('course-details', function (core) {
        const panel = document.getElementById('studioCoursePanel');
        const form = document.getElementById('studioCourseForm');
        if (!panel || !form) return;

        const feedback = panel.querySelector('[data-course-feedback]');

        const open = target => {
            panel.hidden = false;
            const section = document.getElementById(`course-editor-${target}`)
                || document.getElementById('course-editor-details')
                || panel;
            section.scrollIntoView({behavior: 'smooth', block: 'start'});
            const focusable = section.querySelector('input:not([type="hidden"]), select, textarea, button');
            window.setTimeout(() => focusable?.focus(), 180);
        };

        const close = () => {
            if (form.getAttribute('aria-busy') === 'true') return;
            panel.hidden = true;
            core.showFeedback(feedback);
        };

        document.addEventListener('click', event => {
            const trigger = event.target.closest('[data-studio-course-open]');
            if (trigger) {
                open(trigger.dataset.studioCourseOpen || 'details');
                return;
            }
            if (event.target.closest('[data-studio-course-close]')) close();
        });

        if (['#studioCoursePanel', '#course-editor-details', '#course-editor-image', '#course-editor-plans', '#course-editor-settings'].includes(window.location.hash)) {
            open(window.location.hash.replace('#course-editor-', '') || 'details');
        }

        form.addEventListener('submit', event => {
            event.preventDefault();
            if (form.getAttribute('aria-busy') === 'true') return;
            const submitter = event.submitter;
            const intent = submitter?.value || 'save';

            void core.mutate(async () => {
                const expectedVersion = core.authoringVersion;
                const body = new FormData(form);
                body.set('authoring_version', String(expectedVersion));
                body.set('publishing_intent', intent);
                const response = await core.request(form.action, {
                    method: 'POST',
                    headers: {'X-CSRF-TOKEN': core.csrf},
                    body,
                    timeout: 45000,
                });
                const nextVersion = core.requireMutation(response, expectedVersion);
                const course = response?.course;
                if (response?.saved !== true
                    || Number(course?.authoring_version) !== Number(nextVersion)
                    || !['draft', 'coming_soon', 'published', 'unlisted'].includes(course?.publishing_status)
                    || typeof course?.studio_url !== 'string' || course.studio_url === '') {
                    throw core.invalid();
                }
                core.syncVersion(nextVersion);
                const issues = Array.isArray(response.issues) ? response.issues.filter(Boolean) : [];
                if (issues.length) core.notify(issues[0], true);
                else core.notify(response.warning || response.message || 'تم حفظ الكورس');
                window.location.assign(course.studio_url);
            }, {feedback, form});
        });
    });
})(window, document);
