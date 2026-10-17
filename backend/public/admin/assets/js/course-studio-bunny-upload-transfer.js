'use strict';

/*
 * Bunny Stream direct upload through tus-js-client (window.tus, shipped from
 * public/admin/assets/js/vendor/tus-js-client/tus.min.js). The tus.Upload
 * options follow Bunny's documented browser integration:
 * https://docs.bunny.net/reference/tus-resumable-uploads
 * (endpoint https://video.bunnycdn.com/tusupload, AuthorizationSignature /
 * AuthorizationExpire / VideoId / LibraryId headers, filetype + title metadata,
 * retryDelays [0, 3000, 5000, 10000, 20000, 60000, 60000]).
 *
 * Rokn owns only the parts tus does not know about: the server allocation and
 * signed claim, short-lived authorization renewal, the resume record scoped to
 * owner/course/section/tab/authoring revision, and Arabic messages.
 */
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
        const stallTimeoutMs = 120000;
        const tusRetryDelays = [0, 3000, 5000, 10000, 20000, 60000, 60000];
        const apiRetryDelays = [750, 1500, 3000];
        const resumablePreparationCodes = new Set([
            'mutation_outcome_unknown',
            'network_unavailable',
            'request_timeout',
            'bunny_upload_allocation_in_progress',
        ]);
        let currentWait = null;
        let currentUpload = null;
        let rejectCurrentUpload = null;
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

        const waitBeforeRetry = delay => new Promise((resolve, reject) => {
            throwIfStopped();
            const timer = window.setTimeout(() => {
                currentWait = null;
                resolve();
            }, delay);
            currentWait = () => {
                window.clearTimeout(timer);
                currentWait = null;
                reject(cancelledError());
            };
        });

        // Rokn API calls (allocation and authorization renewal), not TUS.
        const postJson = async (url, body) => {
            const requestBody = JSON.stringify(body);
            for (let attempt = 0; ; attempt += 1) {
                throwIfStopped();
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
                    if (error?.cancelled || stopped) throw cancelledError();
                    const status = Number(error?.status || 0);
                    if (resumablePreparationCodes.has(String(error?.code || ''))
                        || status === 408 || status === 425 || status === 429 || status >= 500) {
                        error.resumablePreparation = true;
                        if (attempt < apiRetryDelays.length) {
                            show("انقطع الاتصال أثناء تجهيز الرفع\nنحاول متابعة نفس العملية", progress);
                            await waitBeforeRetry(apiRetryDelays[attempt]);
                            continue;
                        }
                    }
                    throw error;
                }
            }
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

        const statusOf = error => Number(error?.originalResponse?.getStatus?.() || 0);
        // Provider (Bunny) failure only. Errors from our own hooks are handled
        // before this and never reach the provider-status branches.
        const transferError = error => {
            const status = error?.causingError?.authorizationRejected ? 403 : statusOf(error);
            return Object.assign(new Error(status
                ? 'تعذر متابعة الرفع'
                : 'تعذر الاتصال بخدمة الفيديو\nتحقق من الاتصال ثم حاول مرة أخرى'), {status, cause: error});
        };

        const sendWithTus = (file, mime, record, title) => new Promise((resolve, reject) => {
            throwIfStopped();
            const upload = new window.tus.Upload(file, {
                endpoint: record.endpoint,
                uploadUrl: record.uploadUrl || null,
                chunkSize: chunkBytes,
                retryDelays: tusRetryDelays,
                // Our records are the only resume store: they are scoped to the
                // owner, course, section, tab and authoring revision.
                storeFingerprintForResuming: false,
                metadata: {filename: file.name, filetype: mime, title},
                onBeforeRequest: async req => {
                    try {
                        const headers = await freshAuthorization(record);
                        Object.entries(headers || {}).forEach(([name, value]) => req.setHeader(name, String(value)));
                    } catch (error) {
                        throw Object.assign(error, {roknHook: true});
                    }
                    // tus-js-client's XHR stack has no timeout. A request that
                    // makes no progress for stallTimeoutMs becomes a normal network
                    // error so tus retries and resumes. Progress re-arms the timer,
                    // so slow but moving connections are never cut off.
                    const xhr = req.getUnderlyingObject?.();
                    if (xhr?.addEventListener) {
                        let timer = null;
                        const arm = () => {
                            window.clearTimeout(timer);
                            timer = window.setTimeout(() => {
                                xhr.abort();
                                xhr.onerror?.(new Error('stalled'));
                            }, stallTimeoutMs);
                        };
                        xhr.upload?.addEventListener?.('progress', arm);
                        xhr.addEventListener('loadend', () => window.clearTimeout(timer));
                        arm();
                    }
                },
                onAfterResponse: (_req, res) => {
                    // An expired signature must renew and retry, not make tus
                    // discard the resumable URL as an unknown upload.
                    if ([401, 403].includes(res.getStatus())) {
                        record.authorizationDeadline = 0;
                        throw Object.assign(new Error('انتهت صلاحية إذن الرفع'), {authorizationRejected: true});
                    }
                },
                // Bunny's recommended policy: stop on 4xx except 409/423. Network
                // failures (no response) and 5xx are retried with retryDelays.
                onShouldRetry: error => {
                    if (stopped) return false;
                    const cause = error?.causingError;
                    if (cause?.roknHook) return false;
                    const status = statusOf(error);
                    const retry = Boolean(cause?.authorizationRejected)
                        || status === 0 || status === 409 || status === 423 || status >= 500;
                    if (retry) show('انقطع الاتصال\nنحاول استئناف الرفع', progress);
                    return retry;
                },
                onUploadUrlAvailable: () => {
                    if (record.uploadUrl === upload.url) return;
                    record.uploadUrl = upload.url;
                    saveRecord(record);
                },
                onProgress: (sent, total) => {
                    const percent = total > 0 ? (sent / total) * 100 : 0;
                    show(`جاري الرفع ${Math.floor(percent)}٪`, percent);
                },
                onSuccess: () => resolve(),
                onError: error => reject(error),
            });
            currentUpload = upload;
            rejectCurrentUpload = reject;
            upload.start();
        }).finally(() => {
            currentUpload = null;
            rejectCurrentUpload = null;
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

            try {
                await sendWithTus(file, declaredMime, record, title);
            } catch (error) {
                if (error?.cancelled || stopped) throw cancelledError();
                // Renewal and revision checks run in our tus hooks. Surface
                // those errors unchanged (status/code from our API) so callers
                // branch on their codes; they are not provider failures.
                if (error?.causingError?.roknHook) throw error.causingError;
                const failure = transferError(error);
                // The provider no longer knows this video (deleted or expired):
                // allocate once more, never loop.
                if ([404, 410].includes(Number(failure.status || 0))) {
                    clearRecord();
                    if (restartCount >= 1) {
                        throw Object.assign(new Error('تعذر استئناف الرفع\nاختر الملف وحاول مرة أخرى'), {
                            code: 'bunny_remote_upload_unavailable',
                        });
                    }
                    return upload(file, title, restartCount + 1);
                }
                throw failure;
            }
            throwIfStopped();

            onClaim(record.claim);
            show('اكتمل رفع الفيديو', 100);
            return record.claim;
        };

        return Object.freeze({upload, assertActive: throwIfStopped,
            isResumablePreparation: error => Boolean(error?.resumablePreparation)
                || resumablePreparationCodes.has(String(error?.code || '')),
            stop: () => {
                stopped = true;
                currentWait?.();
                // abort(false) keeps the remote upload so "متابعة الرفع" resumes it.
                currentUpload?.abort(false);
                rejectCurrentUpload?.(cancelledError());
            },
            reset: () => { stopped = false; progress = 0; }});
    };

    window.RoknBunnyUploadTransfer = Object.freeze({create});
})(window);
