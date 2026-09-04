<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('sectionForm');
    const fileInput = document.getElementById('bunny_video');
    const claimInput = document.getElementById('bunny_video_claim');
    if (!form || !fileInput || !claimInput) return;

    const progressBox = document.getElementById('bunny_upload_progress');
    const progressBar = progressBox?.querySelector('.progress-bar');
    const statusText = document.getElementById('bunny_upload_status');
    const cancelButton = document.getElementById('bunny_upload_cancel');
    const retryButton = document.getElementById('bunny_upload_retry');
    const sectionType = document.getElementById('section_type');
    const csrf = form.querySelector('input[name="_token"]')?.value || '';
    const maxBytes = 5 * 1024 * 1024 * 1024;
    const allowedMimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
    const mimeByExtension = {
        mp4: 'video/mp4',
        mov: 'video/quicktime',
        avi: 'video/x-msvideo',
        webm: 'video/webm',
    };
    const chunkBytes = 20 * 1024 * 1024;
    const recordVersion = 3;
    const ownerId = @json((string) auth()->id());
    const courseId = String(form.dataset.courseId || '');
    const sectionId = () => String(form.dataset.sectionId || 'new');
    const currentAuthoringVersion = () => {
        const value = Number(form.querySelector('[name="authoring_version"]')?.value || 0);
        if (!Number.isSafeInteger(value) || value < 1) {
            throw Object.assign(new Error('تعذر تحديد نسخة المسودة\nأعد تحميل الصفحة'), {
                code: 'bunny_upload_claim_unavailable',
            });
        }
        return value;
    };
    const legacyStorageKey = () => `rokn:bunny-upload:${ownerId}:${courseId}:${sectionId()}`;
    const operationId = () => {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };
    const tabStorageKey = `rokn:bunny-upload-tab:${ownerId}`;
    let tabId = sessionStorage.getItem(tabStorageKey) || operationId();
    sessionStorage.setItem(tabStorageKey, tabId);
    const pageInstanceId = operationId();
    const tabChannel = typeof BroadcastChannel === 'function'
        ? new BroadcastChannel(`rokn:bunny-upload-tabs:${ownerId}`)
        : null;
    let tabSettled = false;
    const tabReady = new Promise(resolve => {
        if (!tabChannel) {
            tabSettled = true;
            return resolve();
        }
        let collision = false;
        tabChannel.onmessage = event => {
            const message = event.data || {};
            if (message.type === 'probe' && message.tabId === tabId && message.instance !== pageInstanceId) {
                tabChannel.postMessage({
                    type: 'occupied',
                    tabId,
                    target: message.instance,
                    owner: pageInstanceId,
                    settled: tabSettled,
                });
            }
            if (message.type === 'occupied' && message.tabId === tabId && message.target === pageInstanceId) {
                collision = collision || Boolean(message.settled)
                    || pageInstanceId > String(message.owner || '');
            }
        };
        tabChannel.postMessage({type: 'probe', tabId, instance: pageInstanceId});
        window.setTimeout(() => {
            if (collision) {
                tabId = operationId();
                sessionStorage.setItem(tabStorageKey, tabId);
            }
            tabSettled = true;
            resolve();
        }, 80);
    });
    const terminalCodes = new Set([
        'bunny_upload_claim_unavailable',
        'bunny_upload_operation_unavailable',
    ]);
    const serverRejectedClaim = @json($errors->has('bunny_video_claim_terminal'));
    let currentFile = null;
    let currentRecord = null;
    let currentRequest = null;
    let stopped = false;
    let uploading = false;
    let submittingAfterUpload = false;
    let lastSubmitter = null;
    let currentStorageKey = null;
    let reconciliationRequired = false;
    const submitControls = () => Array.from(new Set([
        ...form.querySelectorAll('button[type="submit"], input[type="submit"]'),
        ...document.querySelectorAll('[form="' + CSS.escape(form.id) + '"][type="submit"]'),
    ]));
    const setSubmissionBusy = busy => {
        submitControls().forEach(control => { control.disabled = busy; });
        if (busy) form.setAttribute('aria-busy', 'true');
        else form.removeAttribute('aria-busy');
    };
    const syncVideoRequired = required => {
        fileInput.dataset.videoRequired = required ? 'true' : 'false';
        fileInput.toggleAttribute('required', required);
        if (required) fileInput.setAttribute('data-required', 'true');
        else fileInput.removeAttribute('data-required');
    };

    const show = (message, percent, retry) => {
        progressBox?.classList.remove('is-hidden');
        if (statusText) statusText.textContent = message;
        if (progressBar && Number.isFinite(percent)) {
            const bounded = Math.max(0, Math.min(100, percent));
            progressBar.style.width = `${bounded}%`;
            progressBar.setAttribute('aria-valuenow', String(Math.round(bounded)));
        }
        retryButton?.classList.toggle('is-hidden', !retry);
    };

    const postJson = async (url, body) => {
        const data = await window.RoknAdminRequest.request(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        });
        return data.data || data;
    };

    const fingerprint = file => [
        file.name,
        file.size,
        file.lastModified,
        file.type,
    ].join(':');

    const fingerprintKey = file => {
        const bytes = new TextEncoder().encode(fingerprint(file));
        let binary = '';
        bytes.forEach(byte => { binary += String.fromCharCode(byte); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };
    const storageKeyFor = file => `rokn:bunny-upload:${ownerId}:${courseId}:${sectionId()}:${tabId}:${fingerprintKey(file)}`;

    const readRecord = file => {
        try {
            currentStorageKey = storageKeyFor(file);
            const currentRaw = localStorage.getItem(currentStorageKey);
            const legacyKey = legacyStorageKey();
            const legacyRaw = currentRaw === null ? localStorage.getItem(legacyKey) : null;
            const saved = JSON.parse(currentRaw || legacyRaw || 'null');
            const matchesContext = saved
                && Number(saved.version) === recordVersion
                && String(saved.ownerId) === ownerId
                && String(saved.courseId) === courseId
                && String(saved.sectionId) === sectionId()
                && Number(saved.authoringVersion) === currentAuthoringVersion()
                && saved.fingerprint === fingerprint(file);
            const claimExpiresAt = Date.parse(saved?.claimExpiresAt || '') || 0;
            const operationExpired = !saved?.claim
                && Number(saved?.savedAt || 0) < Date.now() - (15 * 60 * 1000);
            if (!matchesContext || operationExpired || (saved.claim && claimExpiresAt <= Date.now())) {
                localStorage.removeItem(currentStorageKey);
                if (legacyRaw !== null) localStorage.removeItem(legacyKey);
                return null;
            }
            // performance.now is process-local. A reloaded page renews the
            // short-lived authorization while keeping the resumable TUS URL.
            saved.headers = null;
            saved.authorizationDeadline = 0;
            if (legacyRaw !== null) {
                localStorage.setItem(currentStorageKey, JSON.stringify(saved));
                localStorage.removeItem(legacyKey);
            }
            return saved;
        } catch (_) {
            return null;
        }
    };

    const saveRecord = record => {
        currentRecord = Object.assign(record, {
            version: recordVersion,
            ownerId,
            courseId,
            sectionId: sectionId(),
            authoringVersion: Number(record.authoringVersion || currentAuthoringVersion()),
            savedAt: Date.now(),
        });
        if (!currentStorageKey) throw new Error('تعذر حفظ حالة الرفع');
        localStorage.setItem(currentStorageKey, JSON.stringify(currentRecord));
    };

    const clearRecord = () => {
        if (currentStorageKey) {
            localStorage.removeItem(currentStorageKey);
        } else {
            const prefix = `rokn:bunny-upload:${ownerId}:${courseId}:${sectionId()}:${tabId}:`;
            Object.keys(localStorage).filter(key => key.startsWith(prefix))
                .forEach(key => localStorage.removeItem(key));
        }
        currentStorageKey = null;
        currentRecord = null;
        claimInput.value = '';
        fileInput.disabled = false;
        syncVideoRequired(fileInput.dataset.videoRequired === 'true');
    };

    const resetRuntime = (discardCommittedRecord, nextSectionId = undefined) => {
        if (uploading || submittingAfterUpload) return false;
        currentRequest?.abort();
        currentRequest = null;
        if (discardCommittedRecord) clearRecord();
        else {
            currentStorageKey = null;
            currentRecord = null;
            claimInput.value = '';
            fileInput.disabled = false;
            syncVideoRequired(fileInput.dataset.videoRequired === 'true');
        }
        if (nextSectionId !== undefined) {
            form.dataset.sectionId = nextSectionId ? String(nextSectionId) : '';
        }
        currentFile = null;
        stopped = false;
        uploading = false;
        submittingAfterUpload = false;
        lastSubmitter = null;
        reconciliationRequired = false;
        fileInput.value = '';
        progressBox?.classList.add('is-hidden');
        retryButton?.classList.add('is-hidden');
        if (statusText) statusText.textContent = '';
        if (progressBar) {
            progressBar.style.width = '0%';
            progressBar.setAttribute('aria-valuenow', '0');
        }
        setSubmissionBusy(false);
        return true;
    };

    window.RoknCourseVideoUpload = Object.freeze({
        isBusy: () => uploading || submittingAfterUpload,
        resetAfterCommit: () => resetRuntime(true),
        setSectionContext: (nextSectionId, videoRequired = true) => {
            if (uploading || submittingAfterUpload) return false;
            syncVideoRequired(Boolean(videoRequired));
            return resetRuntime(false, nextSectionId);
        },
    });

    const applyAuthorization = (record, authorization) => {
        const ttlSeconds = Number(authorization.authorization_expires_in_seconds || 0);
        record.headers = authorization.headers;
        record.authorizationDeadline = Number.isFinite(ttlSeconds) && ttlSeconds > 0
            ? performance.now() + ttlSeconds * 1000
            : 0;
        record.authorizationExpiresAt = Date.parse(authorization.authorization_expires_at || '') || 0;
        if (authorization.claim_expires_at) {
            record.claimExpiresAt = authorization.claim_expires_at;
        }
        if (authorization.claim) {
            record.claim = authorization.claim;
            claimInput.value = authorization.claim;
        }
    };

    const freshAuthorization = async record => {
        if (Number(record.authoringVersion) !== currentAuthoringVersion()) {
            throw Object.assign(new Error('تغيّرت المسودة أثناء الرفع\nأعد تحميل الصفحة قبل المتابعة'), {
                code: 'bunny_upload_claim_unavailable',
            });
        }
        if (record.headers && Number(record.authorizationDeadline || 0) > performance.now() + 60000) {
            return record.headers;
        }
        const auth = await postJson(form.dataset.bunnyUploadRenew, {claim: record.claim});
        applyAuthorization(record, auth);
        saveRecord(record);
        return record.headers;
    };

    const metadataValue = value => btoa(unescape(encodeURIComponent(String(value))));

    const createTusUpload = async (file, record) => {
        const response = await fetch(record.endpoint, {
            method: 'POST',
            headers: {
                ...record.headers,
                'Tus-Resumable': '1.0.0',
                'Upload-Length': String(file.size),
                'Upload-Metadata': `filename ${metadataValue(file.name)},filetype ${metadataValue(file.type)}`,
            },
        });
        if (!response.ok) throw new Error('تعذر بدء رفع الفيديو');
        const location = response.headers.get('Location');
        if (!location) throw new Error('لم ترجع خدمة الفيديو رابط الرفع');
        record.uploadUrl = new URL(location, record.endpoint).toString();
        saveRecord(record);
    };

    const remoteOffset = async (record, totalSize) => {
        const headers = await freshAuthorization(record);
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(record.uploadUrl, {
                method: 'HEAD',
                headers: {...headers, 'Tus-Resumable': '1.0.0'},
                signal: controller.signal,
            });
            if (response.status === 404 || response.status === 410) return null;
            if (!response.ok) throw new Error('تعذر استئناف الرفع');
            const rawOffset = response.headers.get('Upload-Offset');
            const offset = rawOffset === null ? Number.NaN : Number(rawOffset);
            if (!Number.isSafeInteger(offset) || offset < 0 || offset > totalSize) {
                throw new Error('حالة الرفع غير صالحة');
            }
            return offset;
        } catch (error) {
            if (error?.name === 'AbortError') throw new Error('الاتصال بطيء جدًا');
            throw error;
        } finally {
            window.clearTimeout(timer);
        }
    };

    const patchChunk = (record, file, offset, headers) => new Promise((resolve, reject) => {
        const end = Math.min(file.size, offset + chunkBytes);
        const request = new XMLHttpRequest();
        currentRequest = request;
        request.open('PATCH', record.uploadUrl, true);
        request.timeout = 120000;
        Object.entries(headers).forEach(([name, value]) => request.setRequestHeader(name, value));
        request.setRequestHeader('Tus-Resumable', '1.0.0');
        request.setRequestHeader('Upload-Offset', String(offset));
        request.setRequestHeader('Content-Type', 'application/offset+octet-stream');
        request.upload.onprogress = event => {
            if (!event.lengthComputable) return;
            show(`جاري الرفع ${Math.floor(((offset + event.loaded) / file.size) * 100)}٪`, ((offset + event.loaded) / file.size) * 100, false);
        };
        request.onload = () => {
            if (currentRequest === request) currentRequest = null;
            if (request.status >= 200 && request.status < 300) {
                const nextOffset = Number(request.getResponseHeader('Upload-Offset'));
                if (!Number.isSafeInteger(nextOffset) || nextOffset !== end || nextOffset > file.size) {
                    reject(new Error('حالة الرفع غير صالحة'));
                    return;
                }
                resolve(nextOffset);
            } else {
                reject(Object.assign(new Error('تعذر متابعة الرفع'), {status: request.status}));
            }
        };
        const rejectRequest = error => {
            if (currentRequest === request) currentRequest = null;
            reject(error);
        };
        request.onerror = () => rejectRequest(new Error('انقطع الاتصال أثناء الرفع'));
        request.ontimeout = () => rejectRequest(new Error('الاتصال بطيء جدًا'));
        request.onabort = () => rejectRequest(Object.assign(new Error('تم إيقاف الرفع'), {cancelled: true}));
        request.send(file.slice(offset, end));
    });

    const upload = async (file, restartCount = 0) => {
        await tabReady;
        const extension = String(file.name || '').split('.').pop().toLowerCase();
        const declaredMime = file.type || mimeByExtension[extension] || '';
        if (!allowedMimes.includes(declaredMime) || mimeByExtension[extension] !== declaredMime) {
            throw new Error('صيغة الفيديو غير مدعومة');
        }
        if (file.size < 1 || file.size > maxBytes) throw new Error('حجم الفيديو يجب ألا يتجاوز 5GB');
        const title = (document.getElementById('title_ar')?.value || '').trim();
        if (!title) throw new Error('أضف عنوان المقطع أولًا');

        let record = readRecord(file);
        if (!record) {
            // Persist the operation identity before contacting our API. A lost
            // response can then be retried without allocating a second video.
            record = {
                fingerprint: fingerprint(file),
                idempotencyKey: operationId(),
                authoringVersion: currentAuthoringVersion(),
                endpoint: null,
                claim: null,
                headers: null,
                authorizationExpiresAt: 0,
                authorizationDeadline: 0,
                claimExpiresAt: null,
                uploadUrl: null,
            };
            saveRecord(record);
            show('جاري تجهيز الرفع', 0, false);
            const issued = await postJson(form.dataset.bunnyUploadInit, {
                title,
                size: file.size,
                mime: declaredMime,
                original_name: file.name,
                section_id: form.dataset.sectionId || null,
                idempotency_key: record.idempotencyKey,
                authoring_version: record.authoringVersion,
            });
            Object.assign(record, {
                endpoint: issued.upload_endpoint,
                claim: issued.claim,
                claimExpiresAt: issued.claim_expires_at,
            });
            applyAuthorization(record, issued);
            saveRecord(record);
            await createTusUpload(file, record);
        } else {
            if (!record.claim) {
                const issued = await postJson(form.dataset.bunnyUploadInit, {
                    title,
                    size: file.size,
                    mime: declaredMime,
                    original_name: file.name,
                    section_id: form.dataset.sectionId || null,
                    idempotency_key: record.idempotencyKey,
                    authoring_version: record.authoringVersion,
                });
                Object.assign(record, {
                    endpoint: issued.upload_endpoint,
                    claim: issued.claim,
                    claimExpiresAt: issued.claim_expires_at,
                });
                applyAuthorization(record, issued);
                saveRecord(record);
            }
            claimInput.value = record.claim;
            if (!record.uploadUrl) {
                await freshAuthorization(record);
                await createTusUpload(file, record);
            }
        }

        let offset = await remoteOffset(record, file.size);
        if (offset === null) {
            clearRecord();
            if (restartCount >= 1) {
                throw Object.assign(new Error('تعذر استئناف الرفع\nاختر الملف وحاول مرة أخرى'), {
                    code: 'bunny_remote_upload_unavailable',
                });
            }
            return upload(file, restartCount + 1);
        }
        if (!Number.isFinite(offset) || offset < 0 || offset > file.size) {
            throw new Error('حالة الرفع غير صالحة');
        }

        let failures = 0;
        while (offset < file.size) {
            if (stopped) throw Object.assign(new Error('تم إيقاف الرفع'), {cancelled: true});
            try {
                const headers = await freshAuthorization(record);
                offset = await patchChunk(record, file, offset, headers);
                failures = 0;
            } catch (error) {
                if (error.cancelled || stopped) throw error;
                if ([401, 403].includes(Number(error.status || 0))) {
                    record.authorizationExpiresAt = 0;
                    record.authorizationDeadline = 0;
                }
                failures += 1;
                if (failures > 5) throw error;
                await new Promise(resolve => setTimeout(resolve, [1000, 2000, 5000, 10000, 20000][failures - 1]));
                const resumed = await remoteOffset(record, file.size);
                if (resumed === null) {
                    clearRecord();
                    if (restartCount >= 1) {
                        throw Object.assign(new Error('تعذر استئناف الرفع\nاختر الملف وحاول مرة أخرى'), {
                            code: 'bunny_remote_upload_unavailable',
                        });
                    }
                    return upload(file, restartCount + 1);
                }
                offset = resumed;
            }
        }

        claimInput.value = record.claim;
        fileInput.removeAttribute('required');
        fileInput.removeAttribute('data-required');
        fileInput.disabled = true;
        show('اكتمل رفع الفيديو', 100, false);
    };

    const startUploadAndSubmit = async () => {
        if (!currentFile || uploading) return;
        uploading = true;
        setSubmissionBusy(true);
        stopped = false;
        retryButton?.classList.add('is-hidden');
        try {
            await upload(currentFile);
            uploading = false;
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            submittingAfterUpload = true;
            setSubmissionBusy(false);
            if (lastSubmitter) form.requestSubmit(lastSubmitter);
            else form.requestSubmit();
        } catch (error) {
            if (Number(error?.status || 0) === 409) {
                clearRecord();
                reconciliationRequired = true;
                window.RoknAdminRequest.blockMutationsUntilReload();
                show(error.message || 'تغيّرت المسودة\nنعيد تحميل أحدث نسخة', Number(progressBar?.getAttribute('aria-valuenow') || 0), false);
                window.setTimeout(() => window.location.reload(), 700);
                return;
            }
            if (terminalCodes.has(String(error?.code || ''))) clearRecord();
            show(error.message || 'تعذر رفع الفيديو', Number(progressBar?.getAttribute('aria-valuenow') || 0), true);
        } finally {
            uploading = false;
            if (!submittingAfterUpload && !reconciliationRequired) setSubmissionBusy(false);
        }
    };

    fileInput.addEventListener('change', function () {
        currentFile = this.files?.[0] || null;
        claimInput.value = '';
        this.disabled = false;
        if (this.dataset.videoRequired === 'true') this.setAttribute('data-required', 'true');
        if (!currentFile) return;
        void tabReady.then(() => {
            if (!currentFile) return;
            const saved = readRecord(currentFile);
            if (saved) {
                currentRecord = saved;
                claimInput.value = saved.claim;
                show('يمكن متابعة الرفع السابق', 0, true);
            } else {
                progressBox?.classList.add('is-hidden');
            }
        });
    });

    form.addEventListener('submit', function (event) {
        lastSubmitter = event.submitter || lastSubmitter;
        if (submittingAfterUpload) {
            Promise.resolve().then(() => {
                if (!event.defaultPrevented) return;
                submittingAfterUpload = false;
                setSubmissionBusy(false);
            });
            return;
        }
        if (sectionType?.value !== 'lesson') return;
        if (claimInput.value) {
            fileInput.disabled = true;
            return;
        }
        currentFile = fileInput.files?.[0] || null;
        if (!currentFile) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void startUploadAndSubmit();
    }, true);

    cancelButton?.addEventListener('click', function () {
        stopped = true;
        currentRequest?.abort();
        show('تم إيقاف الرفع ويمكنك متابعته لاحقًا', Number(progressBar?.getAttribute('aria-valuenow') || 0), true);
    });
    retryButton?.addEventListener('click', function () {
        stopped = false;
        void startUploadAndSubmit();
    });

    if (serverRejectedClaim) {
        void tabReady.then(clearRecord);
    } else if (claimInput.value) {
        fileInput.removeAttribute('required');
        fileInput.removeAttribute('data-required');
    }
    window.addEventListener('beforeunload', event => {
        if (!uploading || submittingAfterUpload) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
</script>
