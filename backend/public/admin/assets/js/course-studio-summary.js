'use strict';

(function (window, document) {
    window.RoknCourseStudio.register('summary', function (core) {
        const url = core.studio.dataset.summaryUrl;
        const courseId = Number(core.studio.dataset.courseId);
        const status = document.getElementById('courseStudioSummaryStatus');
        if (!url || !courseId || !status) return;
        const regions = ['course', 'instructor', 'readiness'];
        const message = status.querySelector('[data-studio-summary-message]');
        const retry = status.querySelector('[data-studio-summary-retry]');
        let latestRead = 0;
        let needsReload = false;

        const refresh = async () => {
            const read = ++latestRead;
            const version = core.authoringVersion;
            status.hidden = true;
            needsReload = false;
            try {
                const response = await window.RoknAdminRequest.latest(
                    'course-studio-summary', url, {timeout: 15000}
                );
                if (read !== latestRead || version !== core.authoringVersion) return;
                if (Number(response.course_id) !== courseId
                    || Number(response.authoring_version) !== version) {
                    needsReload = true;
                    throw core.invalid();
                }
                if (typeof response.html !== 'string') throw core.invalid();

                // These read-only fragments use the same Blade views as the page.
                // Keep every editor, selected file and unfinished input in place.
                const template = document.createElement('template');
                template.innerHTML = response.html;
                const replacements = regions.map(region => {
                    const selector = `[data-studio-summary="${region}"]`;
                    return [core.studio.querySelector(selector), template.content.querySelector(selector)];
                });
                if (replacements.some(([current, next]) => !current || !next)) throw core.invalid();
                replacements.forEach(([current, next]) => current.replaceWith(next));
                core.refreshBusy();
            } catch (error) {
                if (read !== latestRead || version !== core.authoringVersion || error?.code === 'cancelled') return;
                // A preview read failing cannot turn an acknowledged save into
                // a failed mutation or discard another unfinished editor.
                if (message) message.textContent = needsReload
                    ? 'حُفظ تعديلك وتغيّر الكورس في جلسة أخرى\nأعد تحميل الصفحة قبل متابعة التحرير'
                    : 'حُفظ التعديل ولم تتحدث المعاينة بعد';
                if (retry) retry.textContent = needsReload ? 'إعادة تحميل الصفحة' : 'تحديث المعاينة';
                status.hidden = false;
            }
        };

        core.studio.addEventListener('course-studio:saved', refresh);
        retry?.addEventListener('click', () => {
            if (needsReload) window.location.reload();
            else void refresh();
        });
    });
})(window, document);
