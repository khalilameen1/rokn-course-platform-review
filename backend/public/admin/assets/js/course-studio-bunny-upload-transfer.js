'use strict';

(function (window) {
    // One resumable upload lifecycle. No DOM or browser storage access.
    const create = ({records, currentAuthoringVersion, sectionId, initUrl, renewUrl, csrf,
        apiRequest, onProgress, onClaim}) => {
        const maxBytes = 5 * 1024 * 1024 * 1024;
        const allowedMimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
        const mimeByExtension = {
            mp4: 'video/mp4',
            mov: 'video/quicktime',
            avi: 'video/x-msvideo',
            webm: 'video/webm',
        };
        const chunkBytes = 20 * 1024 * 1024;
        const transportRetryDelays = [750, 1500, 3000];
        const resumablePreparationCodes = new Set([
            'mutation_outcome_unknown',
            'network_unavailable',
            'request_timeout',
            'bunny_upload_allocation_in_progress',
        ]);
        let currentRequest = null;
        let stopped = false;
        let progress = 0;
        const show = (message, percent) => {
            progress = percent;
            onProgress(message, percent);
        };
        const clearRecord = () => { records.clear(); onClaim(''); };
        const saveRecord = record => records.save(record);
        const cancelledError = () => Object.assign(new Error('تم إيقاف الرفع'), {cancelled: true});
        const throwIfStopped = () => {
            if (stopped) throw cancelledError();
        };
        const retryableStatus = status => [401, 403, 408, 425, 429].includes(Number(status || 0))
            || Number(status || 0) >= 500;
        const responseError = (message, status) => Object.assign(new Error(message), {
            status,
            retryable: retryableStatus(status),
        });

        const waitBeforeRetry = delay => new Promise((resolve, reject) => {
            throwIfStopped();
            let settled = false;
            const request = {
                abort: () => {
                    if (settled) return;
                    settled = true;
                    window.clearTimeout(timer);
                    if (currentRequest === request) currentRequest = null;
                    reject(cancelledError());
                },
            };
            const timer = window.setTimeout(() => {
                if (settled) return;
                settled = true;
                if (currentRequest === request) currentRequest = null;
                resolve();
            }, delay);
            currentRequest = request;
            if (stopped) request.abort();
        });

        const bunnyFetch = async (url, options, timeout, timeoutMessage) => {
            throwIfStopped();
            const controller = new AbortController();
            const timer = window.setTimeout(() => controller.abort(), timeout);
            currentRequest = controller;
            try {
                return await fetch(url, {...options, signal: controller.signal});
            } catch (error) {
                if (stopped) throw cancelledError();
                if (error?.name === 'AbortError') {
                    throw Object.assign(new Error(timeoutMessage), {retryable: true});
                }
                if (error instanceof TypeError || /failed to fetch|networkerror|load failed/i.test(String(error?.message || ''))) {
                    throw Object.assign(new Error('تعذر الاتصال بخدمة الفيديو\nتحقق من الاتصال ثم حاول مرة أخرى'), {
                        retryable: true,
                    });
                }
                throw error;
            } finally {
                window.clearTimeout(timer);
                if (currentRequest === controller) currentRequest = null;
            }
        };

        const withTransportRetry = async (operation, retryMessage, onRetry = null) => {
            for (let attempt = 0; ; attempt += 1) {
                throwIfStopped();
                try {
                    return await operation();
                } catch (error) {
                    if (error?.cancelled || stopped) throw cancelledError();
                    if (!error?.retryable || attempt >= transportRetryDelays.length) throw error;
                    onRetry?.(error);
                    show(retryMessage, progress);
                    await waitBeforeRetry(transportRetryDelays[attempt]);
                }
            }
        };

        const postJson = async (url, body) => {
            const requestBody = JSON.stringify(body);
            return withTransportRetry(async () => {
                try {
                    const data = await apiRequest(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: requestBody,
                    });
                    return data.data || data;
                } catch (error) {
                    const status = Number(error?.status || 0);
                    if (resumablePreparationCodes.has(String(error?.code || ''))
                        || status === 408 || status === 425 || status === 429 || status >= 500) {
                        error.retryable = true;
                        error.resumablePreparation = true;
                    }
                    throw error;
                }
            }, "انقطع الاتصال أثناء تجهيز الرفع\nنحاول متابعة نفس العملية");
        };

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
                onClaim(authorization.claim);
            }
        };

        const freshAuthorization = async record => {
            throwIfStopped();
            if (Number(record.authoringVersion) !== currentAuthoringVersion()) {
                throw Object.assign(new Error('تغيّرت المسودة أثناء الرفع\nأعد تحميل الصفحة قبل المتابعة'), {
                    code: 'bunny_upload_claim_unavailable',
                });
            }
            if (record.headers && Number(record.authorizationDeadline || 0) > performance.now() + 60000) {
                return record.headers;
            }
            const auth = await postJson(renewUrl, {claim: record.claim});
            throwIfStopped();
            applyAuthorization(record, auth);
            saveRecord(record);
            return record.headers;
        };

        const metadataValue = value => btoa(unescape(encodeURIComponent(String(value))));

        const createTusUpload = async (file, record, title) => {
            await withTransportRetry(async () => {
                const headers = await freshAuthorization(record);
                const response = await bunnyFetch(record.endpoint, {
                    method: 'POST',
                    headers: {
                        ...headers,
                        'Tus-Resumable': '1.0.0',
                        'Upload-Length': String(file.size),
                        'Upload-Metadata': `filename ${metadataValue(file.name)},filetype ${metadataValue(file.type)},title ${metadataValue(title)}`,
                    },
                }, 20000, 'تعذر بدء الرفع بسبب بطء الاتصال');
                if (!response.ok) throw responseError('تعذر بدء رفع الفيديو', response.status);
                const location = response.headers.get('Location');
                if (!location) throw new Error('لم ترجع خدمة الفيديو رابط الرفع');
                record.uploadUrl = new URL(location, record.endpoint).toString();
                saveRecord(record);
            }, 'انقطع الاتصال\nنحاول بدء الرفع مرة أخرى', error => {
                if ([401, 403].includes(Number(error?.status || 0))) {
                    record.authorizationExpiresAt = 0;
                    record.authorizationDeadline = 0;
                }
            });
        };

        const remoteOffset = async (record, totalSize) => {
            return withTransportRetry(async () => {
                const headers = await freshAuthorization(record);
                const response = await bunnyFetch(record.uploadUrl, {
                    method: 'HEAD',
                    headers: {...headers, 'Tus-Resumable': '1.0.0'},
                }, 15000, 'الاتصال بخدمة الفيديو بطيء جدًا');
                if (response.status === 404 || response.status === 410) return null;
                if (!response.ok) throw responseError('تعذر استئناف الرفع', response.status);
                const rawOffset = response.headers.get('Upload-Offset');
                const offset = rawOffset === null ? Number.NaN : Number(rawOffset);
                if (!Number.isSafeInteger(offset) || offset < 0 || offset > totalSize) {
                    throw new Error('حالة الرفع غير صالحة');
                }
                return offset;
            }, 'انقطع الاتصال\nنحاول استئناف الرفع', error => {
                if ([401, 403].includes(Number(error?.status || 0))) {
                    record.authorizationExpiresAt = 0;
                    record.authorizationDeadline = 0;
                }
            });
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
                show(`جاري الرفع ${Math.floor(((offset + event.loaded) / file.size) * 100)}٪`, ((offset + event.loaded) / file.size) * 100);
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

        const upload = async (file, title, restartCount = 0) => {
            await records.ready;
            throwIfStopped();
            const extension = String(file.name || '').split('.').pop().toLowerCase();
            const declaredMime = file.type || mimeByExtension[extension] || '';
            if (!allowedMimes.includes(declaredMime) || mimeByExtension[extension] !== declaredMime) {
                throw new Error('صيغة الفيديو غير مدعومة');
            }
            if (file.size < 1 || file.size > maxBytes) throw new Error('حجم الفيديو يجب ألا يتجاوز 5GB');
            if (!title) throw new Error('أضف عنوان المقطع أولًا');

            let record = records.read(file);
            if (!record) {
                // Persist the operation identity before contacting our API. A lost
                // response can then be retried without allocating a second video.
                record = {
                    fingerprint: records.fingerprint(file),
                    idempotencyKey: records.newOperationId(),
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
            }
            if (!record.claim) {
                show('جاري تجهيز الرفع', 0);
                const issued = await postJson(initUrl, {
                    title,
                    size: file.size,
                    mime: declaredMime,
                    original_name: file.name,
                    section_id: sectionId() === 'new' ? null : sectionId(),
                    idempotency_key: record.idempotencyKey,
                    authoring_version: record.authoringVersion,
                });
                throwIfStopped();
                Object.assign(record, {
                    endpoint: issued.upload_endpoint,
                    claim: issued.claim,
                    claimExpiresAt: issued.claim_expires_at,
                });
                applyAuthorization(record, issued);
                saveRecord(record);
            }
            onClaim(record.claim);
            if (!record.uploadUrl) await createTusUpload(file, record, title);

            let offset = await remoteOffset(record, file.size);
            throwIfStopped();
            if (offset === null) {
                clearRecord();
                if (restartCount >= 1) {
                    throw Object.assign(new Error('تعذر استئناف الرفع\nاختر الملف وحاول مرة أخرى'), {
                        code: 'bunny_remote_upload_unavailable',
                    });
                }
                return upload(file, title, restartCount + 1);
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
                    await waitBeforeRetry([1000, 2000, 5000, 10000, 20000][failures - 1]);
                    const resumed = await remoteOffset(record, file.size);
                    if (resumed === null) {
                        clearRecord();
                        if (restartCount >= 1) {
                            throw Object.assign(new Error('تعذر استئناف الرفع\nاختر الملف وحاول مرة أخرى'), {
                                code: 'bunny_remote_upload_unavailable',
                            });
                        }
                        return upload(file, title, restartCount + 1);
                    }
                    offset = resumed;
                }
            }

            onClaim(record.claim);
            show('اكتمل رفع الفيديو', 100);
            return record.claim;
        };

        return Object.freeze({upload, assertActive: throwIfStopped,
            isResumablePreparation: error => Boolean(error?.resumablePreparation)
                || resumablePreparationCodes.has(String(error?.code || '')),
            stop: () => { stopped = true; currentRequest?.abort(); },
            reset: () => { stopped = false; progress = 0; }});
    };

    window.RoknBunnyUploadTransfer = Object.freeze({create});
})(window);
