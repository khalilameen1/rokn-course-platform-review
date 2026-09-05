'use strict';

(function (window, document) {
    const modules = new Map();
    let context = null;

    const parseJson = node => {
        try {
            return JSON.parse(node?.textContent || '{}');
        } catch (_) {
            return {};
        }
    };

    const createContext = () => {
        const studio = document.getElementById('courseStudio');
        if (!studio) return null;

        const toast = document.getElementById('courseStudioToast');
        const metaCsrf = document.querySelector('meta[name="csrf-token"]')?.content?.trim() || '';
        const graph = parseJson(document.getElementById('courseAuthoringGraph'));
        let authoringVersion = Number(studio.dataset.authoringVersion || graph.authoring_version);
        let busyCount = 0;
        const externalBusySources = new Set();
        const services = new Map();

        const reconciliationMessageKey = 'rokn-course-studio-reconciliation';
        const savedMessageKey = 'rokn-course-studio-save-message';
        const notify = (message, error = false, duration = 2800) => {
            if (!toast || !message) return;
            toast.textContent = message;
            toast.classList.toggle('is-error', error);
            toast.classList.add('is-visible');
            window.setTimeout(() => toast.classList.remove('is-visible'), duration);
        };

        const showFeedback = (element, message = '', error = Boolean(message)) => {
            if (!element) return;
            element.textContent = message;
            element.classList.toggle('is-error', Boolean(error));
            element.hidden = !message;
        };

        const csrfToken = form => {
            const tokenInput = form?.querySelector('input[name="_token"]')
                || document.querySelector('input[name="_token"]');
            const formToken = tokenInput?.value?.trim() || '';
            const token = metaCsrf || formToken;
            if (!token) {
                throw new window.RoknAdminRequest.AdminRequestError(
                    'تعذر تأكيد جلسة الدخول\nأعد تحميل الصفحة ثم حاول مرة أخرى',
                    419,
                    'csrf_token_missing'
                );
            }
            return token;
        };

        const mutationHeaders = (form = null, headers = {}) => ({
            ...headers,
            'X-CSRF-TOKEN': csrfToken(form),
        });

        const authoringFormData = (form, expectedVersion, method = null) => {
            const version = Number(expectedVersion);
            if (!Number.isSafeInteger(version) || version < 1) {
                throw new window.RoknAdminRequest.AdminRequestError(
                    'تعذر تحميل نسخة الحفظ\nأعد تحميل الصفحة ثم حاول مرة أخرى',
                    422,
                    'authoring_version_missing'
                );
            }
            const body = new FormData(form);
            body.set('_token', csrfToken(form));
            body.set('authoring_version', String(version));
            if (method) body.set('_method', method);
            return body;
        };

        const refreshBusy = () => {
            const externalBusy = Array.from(externalBusySources).some(source => source());
            const busy = busyCount > 0 || externalBusy;
            studio.classList.toggle('is-authoring-busy', busy);
            studio.inert = busyCount > 0;
            studio.querySelectorAll('button').forEach(control => {
                const uploadEscape = externalBusy && busyCount === 0
                    && ['bunny_upload_cancel', 'bunny_upload_retry'].includes(control.id);
                if (busy && !uploadEscape && !control.disabled) {
                    control.disabled = true;
                    control.dataset.studioBusyDisabled = '1';
                    return;
                }
                if ((!busy || uploadEscape) && control.dataset.studioBusyDisabled === '1') {
                    control.disabled = false;
                    delete control.dataset.studioBusyDisabled;
                }
            });
        };

        studio.addEventListener('click', event => {
            if (busyCount < 1) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);
        studio.addEventListener('submit', event => {
            if (busyCount < 1) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        const syncVersion = version => {
            const parsed = Number(version);
            if (!Number.isSafeInteger(parsed) || parsed < 1) {
                throw new window.RoknAdminRequest.AdminRequestError('', 200, 'invalid_authoring_response');
            }
            // A completed create receipt is immutable. If another tab has
            // advanced this course since that receipt was written, replaying
            // it must not move the live editor back to the older version.
            authoringVersion = Math.max(authoringVersion, parsed);
            studio.dataset.authoringVersion = String(authoringVersion);
            document.querySelectorAll('input[name="authoring_version"]').forEach(input => input.value = String(authoringVersion));
            return authoringVersion;
        };

        const requireMutation = (response, expectedVersion, requireAdvance = true) => {
            if (response?.success !== true) {
                throw new window.RoknAdminRequest.AdminRequestError(
                    response?.message || 'تعذر تأكيد الحفظ',
                    200,
                    'invalid_authoring_response'
                );
            }
            return window.RoknAdminRequest.requireAuthoringVersion(response, expectedVersion, requireAdvance);
        };

        const reconcile = error => {
            window.RoknAdminRequest.blockMutationsUntilReload();
            studio.querySelectorAll('button, input, select, textarea').forEach(control => control.disabled = true);
            const message = error?.message || 'لم يصل رد الحفظ كاملًا\nنعيد تحميل أحدث نسخة';
            try {
                window.sessionStorage.setItem(reconciliationMessageKey, message);
            } catch (_) {}
            notify(message, true, 7000);
            window.setTimeout(() => window.location.reload(), 1500);
        };

        const setFormBusy = (form, busy) => {
            if (!form) return;
            form.setAttribute('aria-busy', busy ? 'true' : 'false');
            form.querySelectorAll('button[type="submit"]').forEach(button => button.disabled = busy);
        };

        const mutate = (operation, options = {}) => {
            const {
                feedback = null,
                form = null,
                reloadOnError = false,
                recoverableConflictCodes = [],
                onReconcile = null,
            } = options;
            const versionBeforeSave = authoringVersion;
            showFeedback(feedback);
            setFormBusy(form, true);
            busyCount += 1;
            refreshBusy();

            return window.RoknAdminRequest.serializeMutation('course-studio-authoring', operation)
                .then(result => {
                    if (authoringVersion !== versionBeforeSave) {
                        studio.dispatchEvent(new Event('course-studio:saved'));
                    }
                    return result;
                })
                .catch(error => {
                    if (error?.code === 'cancelled') return null;
                    const recoverableConflict = error?.status === 409
                        && recoverableConflictCodes.includes(error?.code);
                    if (reloadOnError || (error?.status === 409 && !recoverableConflict)
                        || ['mutation_outcome_unknown', 'invalid_authoring_response'].includes(error?.code)) {
                        try {
                            onReconcile?.(error);
                        } catch (_) {}
                        reconcile(error);
                        return null;
                    }
                    const message = error?.message || 'تعذر حفظ التعديل';
                    if (feedback) showFeedback(feedback, message);
                    else notify(message, true);
                    return null;
                })
                .finally(() => {
                    setFormBusy(form, false);
                    busyCount = Math.max(0, busyCount - 1);
                    if (!window.RoknAdminRequest.mutationsAreBlocked()) refreshBusy();
                });
        };

        const uuid = () => {
            if (window.crypto?.randomUUID) return window.crypto.randomUUID();
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 15) | 64;
            bytes[8] = (bytes[8] & 63) | 128;
            const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
        };

        const activateTabs = () => {
            const tabs = Array.from(document.querySelectorAll('[data-studio-tab]'));
            const activate = button => {
                tabs.forEach(tab => {
                    const active = tab === button;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    tab.tabIndex = active ? 0 : -1;
                });
                document.querySelectorAll('[data-studio-panel]').forEach(panel => {
                    const active = panel.id === button.dataset.studioTab;
                    panel.classList.toggle('is-active', active);
                    panel.hidden = !active;
                });
            };
            tabs.forEach((button, index) => {
                button.addEventListener('click', () => activate(button));
                button.addEventListener('keydown', event => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
                        : (index + (event.key === 'ArrowLeft' ? 1 : -1) + tabs.length) % tabs.length;
                    tabs[next]?.focus();
                    if (tabs[next]) activate(tabs[next]);
                });
            });
            const requested = new URLSearchParams(window.location.search).get('tab') || window.location.hash.replace(/^#/, '');
            const requestedButton = tabs.find(button => button.dataset.studioTab === requested);
            if (requestedButton) activate(requestedButton);
        };

        return {
            studio,
            graph,
            canAuthor: studio.dataset.canAuthor === '1',
            get authoringVersion() { return authoringVersion; },
            notify,
            showFeedback,
            mutationHeaders,
            authoringFormData,
            syncVersion,
            requireMutation,
            reconcile,
            mutate,
            uuid,
            refreshBusy,
            registerBusySource(source) {
                externalBusySources.add(source);
                refreshBusy();
                return () => externalBusySources.delete(source);
            },
            provide(name, service) {
                if (services.has(name)) throw new Error(`Course Studio service already registered: ${name}`);
                services.set(name, service);
                return service;
            },
            use(name) {
                const service = services.get(name);
                if (!service) throw new Error(`Course Studio service is not ready: ${name}`);
                return service;
            },
            request(url, options) {
                return window.RoknAdminRequest.request(url, options);
            },
            invalid(message = '') {
                return new window.RoknAdminRequest.AdminRequestError(message, 200, 'invalid_authoring_response');
            },
            start() {
                activateTabs();
                try {
                    const reconciliationMessage = window.sessionStorage.getItem(reconciliationMessageKey);
                    const savedMessage = window.sessionStorage.getItem(savedMessageKey);
                    if (reconciliationMessage) {
                        window.sessionStorage.removeItem(reconciliationMessageKey);
                        notify(`${reconciliationMessage}\nهذه هي آخر نسخة محفوظة`, true, 8000);
                    } else if (savedMessage) {
                        window.sessionStorage.removeItem(savedMessageKey);
                        notify(savedMessage, false, 5000);
                    }
                } catch (_) {}
            },
        };
    };

    window.RoknCourseStudio = Object.freeze({
        register(name, initializer) {
            if (typeof name !== 'string' || typeof initializer !== 'function' || modules.has(name)) return;
            modules.set(name, initializer);
        },
        start() {
            if (context) return context;
            context = createContext();
            if (!context) return null;
            context.start();
            modules.forEach(initializer => initializer(context));
            return context;
        },
    });
})(window, document);
