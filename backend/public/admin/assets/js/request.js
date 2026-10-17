(function (window) {
    'use strict';

    const latestControllers = new Map();
    const mutationTails = new Map();
    let mutationsBlockedUntilReload = false;

    class AdminRequestError extends Error {
        constructor(message, status, code) {
            super(message);
            this.name = 'AdminRequestError';
            this.status = Number(status || 0);
            this.code = String(code || 'request_failed');
        }
    }

    const fallbackMessage = status => {
        if (status === 401 || status === 419) return 'انتهت الجلسة\nأعد تحميل الصفحة وسجّل الدخول';
        if (status === 403) return 'هذا الإجراء غير متاح لحسابك';
        if (status === 404 || status === 410) return 'السجل المطلوب لم يعد متاحًا';
        if (status === 408) return 'استغرق الطلب وقتًا طويلًا\nتحقق من الاتصال ثم حاول مرة أخرى';
        if (status === 409) return 'عدّل شخص آخر هذه البيانات\nأعد تحميل الصفحة قبل المتابعة';
        if (status === 413) return 'حجم الملف أكبر من المسموح';
        if (status === 422) return 'راجع البيانات المطلوبة ثم حاول مرة أخرى';
        if (status === 429) return 'طلبات كثيرة في وقت قصير\nانتظر قليلًا ثم حاول مرة أخرى';
        if (status === 503) return 'الخدمة تحت تحديث قصير\nحاول بعد قليل';
        if (status >= 500) return 'تعذّر إكمال الطلب الآن\nحاول بعد قليل';
        if (status >= 200 && status < 300) return 'تعذّر إكمال الطلب';
        return 'انقطع الاتصال\nتحقق من الإنترنت ثم حاول مرة أخرى';
    };

    const firstValidationMessage = payload => {
        const errors = payload && typeof payload.errors === 'object' ? payload.errors : {};
        for (const value of Object.values(errors)) {
            const message = Array.isArray(value) ? value[0] : value;
            if (typeof message === 'string' && message.trim()) return message.trim();
        }
        return '';
    };

    const responsePayload = async response => {
        const type = String(response.headers.get('content-type') || '').toLowerCase();
        if (!type.includes('application/json')) return {};
        return response.json().catch(() => ({}));
    };

    const request = async (url, options) => {
        const settings = options || {};
        const {timeout, signal, ...fetchSettings} = settings;
        const method = String(fetchSettings.method || 'GET').toUpperCase();
        const mutationOutcomeMayBeUnknown = !['GET', 'HEAD', 'OPTIONS'].includes(method);
        const controller = new AbortController();
        const externalSignal = signal;
        let timedOut = false;
        const abortFromCaller = () => controller.abort();
        if (externalSignal) {
            if (externalSignal.aborted) controller.abort();
            else externalSignal.addEventListener('abort', abortFromCaller, {once: true});
        }
        const timer = window.setTimeout(() => {
            timedOut = true;
            controller.abort();
        }, Math.max(1000, Number(timeout || 15000)));

        try {
            const response = await window.fetch(url, {
                ...fetchSettings,
                credentials: settings.credentials || 'same-origin',
                headers: {'Accept': 'application/json', ...(settings.headers || {})},
                signal: controller.signal,
            });
            const payload = await responsePayload(response);
            const redirectedToLogin = response.redirected && /\/login(?:\?|$)/.test(response.url || '');
            if (!response.ok || redirectedToLogin || payload.success === false) {
                const status = redirectedToLogin ? 401 : response.status;
                const message = firstValidationMessage(payload) ||
                    (typeof payload.message === 'string' ? payload.message.trim() : '') ||
                    fallbackMessage(status);
                // Field keys let callers branch on machine-readable validation
                // markers (e.g. bunny_video_claim_terminal) instead of text.
                throw Object.assign(new AdminRequestError(message, status, payload.code), {
                    fields: payload && typeof payload.errors === 'object' && payload.errors
                        ? Object.keys(payload.errors) : [],
                });
            }
            return payload;
        } catch (error) {
            if (error instanceof AdminRequestError) throw error;
            if (controller.signal.aborted) {
                if (!timedOut) throw new AdminRequestError('', 0, 'cancelled');
                throw new AdminRequestError(
                    mutationOutcomeMayBeUnknown
                        ? 'انقطع الرد بعد إرسال التعديل\nنعيد تحميل أحدث حالة قبل المحاولة'
                        : fallbackMessage(408),
                    408,
                    mutationOutcomeMayBeUnknown ? 'mutation_outcome_unknown' : 'request_timeout'
                );
            }
            throw new AdminRequestError(
                mutationOutcomeMayBeUnknown
                    ? 'انقطع الرد بعد إرسال التعديل\nنعيد تحميل أحدث حالة قبل المحاولة'
                    : fallbackMessage(0),
                0,
                mutationOutcomeMayBeUnknown ? 'mutation_outcome_unknown' : 'network_unavailable'
            );
        } finally {
            window.clearTimeout(timer);
            externalSignal?.removeEventListener('abort', abortFromCaller);
        }
    };

    const latest = (key, url, options) => {
        latestControllers.get(key)?.abort();
        const controller = new AbortController();
        latestControllers.set(key, controller);
        return request(url, {...(options || {}), signal: controller.signal}).finally(() => {
            if (latestControllers.get(key) === controller) latestControllers.delete(key);
        });
    };

    const serializeMutation = (key, operation) => {
        const previous = mutationTails.get(key) || Promise.resolve();
        const current = previous.catch(() => undefined).then(() => {
            if (mutationsBlockedUntilReload) {
                throw new AdminRequestError('', 0, 'cancelled');
            }
            return operation();
        });
        mutationTails.set(key, current);
        return current.finally(() => {
            if (mutationTails.get(key) === current) mutationTails.delete(key);
        });
    };

    const requireAuthoringVersion = (payload, expectedVersion, requireAdvance) => {
        const expected = Number(expectedVersion);
        const received = Number(payload && payload.authoring_version);
        const invalid = !Number.isSafeInteger(expected) || expected < 1 ||
            !Number.isSafeInteger(received) || received < expected ||
            (requireAdvance === true && received <= expected);
        if (invalid) {
            throw new AdminRequestError(
                'وصل رد غير مكتمل بعد الحفظ\nنعيد تحميل أحدث نسخة قبل المتابعة',
                200,
                'invalid_authoring_response'
            );
        }
        return received;
    };

    const blockMutationsUntilReload = () => {
        mutationsBlockedUntilReload = true;
        document.documentElement.setAttribute('data-authoring-reconciliation', 'required');
    };

    const mutationsAreBlocked = () => mutationsBlockedUntilReload;

    window.RoknAdminRequest = Object.freeze({
        AdminRequestError,
        blockMutationsUntilReload,
        fallbackMessage,
        latest,
        mutationsAreBlocked,
        request,
        requireAuthoringVersion,
        serializeMutation,
    });
})(window);
