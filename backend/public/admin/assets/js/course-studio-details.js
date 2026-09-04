'use strict';

(function (window, document) {
    window.RoknCourseStudio.register('course-details', function (core) {
        const panel = document.getElementById('studioCoursePanel');
        const form = document.getElementById('studioCourseForm');
        if (!panel || !form) return;

        const feedback = panel.querySelector('[data-course-feedback]');

        const applySavedCourse = course => {
            document.querySelectorAll('[data-studio-course-title]').forEach(node => {
                node.textContent = course.title;
            });
            if (typeof course.image_url !== 'string' || course.image_url === '') return;
            const imageSection = form.querySelector('#course-editor-image');
            const uploadArea = imageSection?.querySelector('.file-upload-area');
            if (!imageSection || !uploadArea) return;
            let current = imageSection.querySelector('.course-editor__current-image');
            if (!current) {
                current = document.createElement('div');
                current.className = 'course-editor__current-image';
                const image = document.createElement('img');
                image.className = 'current-image';
                const status = document.createElement('div');
                status.className = 'course-editor__image-status';
                status.textContent = 'الصورة الحالية';
                current.append(image, status);
                uploadArea.before(current);
            }
            const image = current.querySelector('img');
            if (image) {
                image.src = course.image_url;
                image.alt = course.title;
            }
            const input = imageSection.querySelector('#image');
            if (input) input.value = '';
            imageSection.querySelector('#imagePreview')?.replaceChildren();
        };

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
                const body = core.authoringFormData(form, expectedVersion, 'PATCH');
                body.set('publishing_intent', intent);
                const response = await core.request(form.action, {
                    method: 'POST',
                    headers: core.mutationHeaders(form),
                    body,
                    timeout: 30000,
                });
                const nextVersion = core.requireMutation(response, expectedVersion);
                const course = response?.course;
                if (response?.saved !== true
                    || Number(course?.authoring_version) !== Number(nextVersion)
                    || !['draft', 'coming_soon', 'published', 'unlisted'].includes(course?.publishing_status)
                    || typeof course?.studio_url !== 'string' || course.studio_url === ''
                    || typeof course?.title !== 'string') {
                    throw core.invalid();
                }
                core.syncVersion(nextVersion);
                const issues = Array.isArray(response.issues) ? response.issues.filter(Boolean) : [];
                const message = issues[0] || response.warning || response.message || 'تم حفظ الكورس';
                const currentUrl = new URL(window.location.href);
                const destination = new URL(course.studio_url, window.location.href);
                const staysInDraft = intent === 'save'
                    && currentUrl.pathname === destination.pathname
                    && currentUrl.search === destination.search;
                if (staysInDraft) {
                    applySavedCourse(course);
                    core.showFeedback(feedback, message, Boolean(issues.length));
                    core.notify(message, Boolean(issues.length), 5000);
                    return;
                }
                try {
                    window.sessionStorage.setItem('rokn-course-studio-save-message', message);
                } catch (_) {}
                window.location.assign(destination.toString());
            }, {feedback, form});
        });
    });
})(window, document);
