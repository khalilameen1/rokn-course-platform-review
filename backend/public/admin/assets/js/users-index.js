(function () {
    'use strict';

    var modal = document.getElementById('addNoteModal');
    if (!modal) return;

    var form = modal.querySelector('[data-student-note-form]');
    var label = modal.querySelector('[data-student-note-label]');
    var textarea = modal.querySelector('textarea[name="note"]');

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-note-action]');
        if (!trigger || !form) return;

        var action = trigger.getAttribute('data-note-action') || '';
        var studentName = trigger.getAttribute('data-student-name') || '';
        form.setAttribute('action', action);
        if (label) label.textContent = studentName ? 'ملاحظة للطالب ' + studentName : 'الملاحظة';
        if (textarea) textarea.value = '';
    });
}());
