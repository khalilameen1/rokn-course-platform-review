{{--
    Pre-send review for broadcast campaigns, following the Mailchimp/Klaviyo
    flow: show the current recipient count for the chosen audience, require an
    explicit confirmation tied to that count, and offer a single test send.
    Without JavaScript the form still requires the confirmation checkbox and
    the test button posts normally.
--}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById(@json($formId));
    const review = form && form.querySelector('[data-recipient-preview]');
    if (!form || !review) return;
    const audience = form.querySelector('[name="audience"]');
    const course = form.querySelector('[name="course_id"]');
    const kind = form.querySelector('[name="notification_kind"]');
    const countLine = review.querySelector('[data-recipient-count]');
    const confirmBox = review.querySelector('[name="confirm_send"]');
    const confirmLabel = review.querySelector('[data-confirm-send-label]');
    const numberFormat = new Intl.NumberFormat('ar-EG');
    let previewed = null;
    let requestSerial = 0;

    function setConfirmation(count) {
        previewed = count;
        confirmBox.checked = false;
        confirmLabel.textContent = count === null
            ? 'راجعت الجمهور والنص وأؤكد الإرسال'
            : 'أؤكد إرسال هذا الإشعار إلى ' + numberFormat.format(count) + ' طالب';
    }

    function refresh() {
        const serial = ++requestSerial;
        if (!audience.value) {
            countLine.textContent = 'اختر الجمهور لعرض عدد المستلمين';
            setConfirmation(null);
            return;
        }
        if (audience.value !== 'all' && !course.value) {
            countLine.textContent = 'اختر الكورس لهذا الجمهور أولًا';
            setConfirmation(null);
            return;
        }
        countLine.textContent = 'جارٍ حساب عدد المستلمين…';
        setConfirmation(null);
        const query = new URLSearchParams({ audience: audience.value });
        if (course.value) query.set('course_id', course.value);
        if (kind && kind.value) query.set('notification_kind', kind.value);
        fetch(review.dataset.previewUrl + '?' + query.toString(), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) throw new Error('preview_failed');
            return response.json();
        }).then(function (body) {
            if (serial !== requestSerial) return;
            const count = Number(body.recipients) || 0;
            countLine.textContent = count === 0
                ? 'لا يوجد طلاب مؤهلون لهذا الجمهور الآن'
                : 'سيصل الإشعار الآن إلى ' + numberFormat.format(count) + ' طالب';
            setConfirmation(count);
        }).catch(function () {
            if (serial !== requestSerial) return;
            countLine.textContent = 'تعذر حساب العدد الآن، يمكنك المتابعة بعد مراجعة الجمهور';
        });
    }

    [audience, course, kind].forEach(function (field) {
        if (field) field.addEventListener('change', refresh);
    });
    refresh();

    form.addEventListener('submit', function (event) {
        const submitter = event.submitter;
        if (submitter && submitter.hasAttribute('data-notification-test-send')) return;
        if (previewed === 0 && !window.confirm('لا يوجد مستلمون مؤهلون الآن. هل تريد حفظ الحملة رغم ذلك؟')) {
            event.preventDefault();
        }
    });

    const testButton = form.querySelector('[data-notification-test-send]');
    const testStatus = form.querySelector('[data-notification-test-status]');
    if (!testButton || !window.fetch || !window.FormData) return;
    testButton.addEventListener('click', function (event) {
        event.preventDefault();
        const body = new FormData(form);
        body.delete('confirm_send');
        testButton.disabled = true;
        testStatus.className = 'small mt-1 text-muted';
        testStatus.textContent = 'جارٍ إرسال التجربة…';
        fetch(testButton.getAttribute('formaction'), {
            method: 'POST',
            body: body,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                return { ok: response.ok, payload: payload };
            });
        }).then(function (result) {
            const errors = result.payload && result.payload.errors ? Object.values(result.payload.errors) : [];
            testStatus.className = 'small mt-1 ' + (result.ok ? 'text-success' : 'text-danger');
            testStatus.textContent = result.ok
                ? result.payload.message
                : ((errors[0] && errors[0][0]) || 'تعذر إرسال التجربة');
        }).catch(function () {
            testStatus.className = 'small mt-1 text-danger';
            testStatus.textContent = 'تعذر إرسال التجربة';
        }).finally(function () {
            testButton.disabled = false;
        });
    });
});
</script>
