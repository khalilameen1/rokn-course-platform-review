(() => {
    'use strict';

    const form = document.getElementById('reward-rule-create');
    if (!form) return;

    const event = form.elements.event_key;
    const interval = form.querySelector('[data-reward-field="interval"]');
    const daily = form.querySelector('[data-reward-field="daily"]');
    const rolling = form.querySelector('[data-reward-field="rolling"]');

    function showField(field, visible) {
        field.hidden = !visible;
        field.querySelector('input').disabled = !visible;
    }

    function updateFields() {
        const streak = event.value === 'streak_milestone';
        const study = event.value === 'study_session';
        const firstProject = event.value === 'first_project_passed';
        showField(interval, streak || study);
        showField(daily, study);
        showField(rolling, event.value !== '' && event.value !== 'welcome_bonus');

        const intervalInput = interval.querySelector('input');
        interval.querySelector('label').textContent = streak
            ? 'مدة الاستمرارية بالأيام'
            : 'مدة الدراسة بالدقائق';
        intervalInput.min = streak ? '2' : '1';
        if (streak && intervalInput.value === '1') intervalInput.value = '2';
        rolling.querySelector('label').textContent = firstProject
            ? 'سقف مكافأة أول مشروع'
            : 'الحد خلال 30 يومًا';
        rolling.querySelector('input').required = !firstProject && event.value !== 'welcome_bonus';
    }

    event.addEventListener('change', updateFields);
    updateFields();
})();
