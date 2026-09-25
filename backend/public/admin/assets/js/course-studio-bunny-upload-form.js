'use strict';

(function (window) {
    // Form presentation and save handoff. Transport and resume records have separate owners.
    const create = ({ownerId, serverRejectedClaim}) => {
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
        const terminalCodes = new Set([
            'bunny_upload_claim_unavailable',
            'bunny_upload_operation_unavailable',
        ]);
        // Allocation provides a resumable identity before any video bytes arrive.
        // Only a completed transfer (or a completed claim returned by the form)
        // may bypass upload on Save.
        let completedClaim = serverRejectedClaim ? '' : String(claimInput.value || '');
        let currentFile = null;
        let uploading = false;
        let submittingAfterUpload = false;
        let lastSubmitter = null;
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

        const records = window.RoknBunnyUploadRecords.create({
            ownerId, courseId, sectionId, currentAuthoringVersion,
        });
        const resetClaim = () => {
            claimInput.value = '';
            completedClaim = '';
            fileInput.disabled = false;
            syncVideoRequired(fileInput.dataset.videoRequired === 'true');
        };
        const clearRecord = () => { records.clear(); resetClaim(); };
        const transfer = window.RoknBunnyUploadTransfer.create({
            records, currentAuthoringVersion, sectionId,
            initUrl: form.dataset.bunnyUploadInit,
            renewUrl: form.dataset.bunnyUploadRenew,
            csrf: form.querySelector('input[name="_token"]')?.value || '',
            apiRequest: (url, options) => window.RoknAdminRequest.request(url, options),
            onProgress: (message, percent) => show(message, percent, false),
            onClaim: claim => { if (claim) claimInput.value = claim; else resetClaim(); },
        });

        const resetRuntime = (discardCommittedRecord, nextSectionId = undefined) => {
            if (uploading || submittingAfterUpload) return false;
            transfer.stop();
            if (discardCommittedRecord) clearRecord();
            else {
                records.release();
                resetClaim();
            }
            if (nextSectionId !== undefined) {
                form.dataset.sectionId = nextSectionId ? String(nextSectionId) : '';
            }
            currentFile = null;
            transfer.reset();
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

        const startUploadAndSubmit = async () => {
            if (!currentFile || uploading) return;
            uploading = true;
            setSubmissionBusy(true);
            transfer.reset();
            retryButton?.classList.add('is-hidden');
            try {
                const title = (document.getElementById('title_ar')?.value || '').trim();
                const claim = await transfer.upload(currentFile, title);
                transfer.assertActive();
                completedClaim = claim;
                claimInput.value = completedClaim;
                fileInput.removeAttribute('required');
                fileInput.removeAttribute('data-required');
                fileInput.disabled = true;
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
                if (transfer.isResumablePreparation(error)) {
                    show(
                        "تعذر تأكيد تجهيز الرفع\nاضغط متابعة الرفع لنكمل نفس المحاولة",
                        Number(progressBar?.getAttribute('aria-valuenow') || 0),
                        true
                    );
                    return;
                }
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
            completedClaim = '';
            this.disabled = false;
            if (this.dataset.videoRequired === 'true') this.setAttribute('data-required', 'true');
            if (!currentFile) return;
            void records.ready.then(() => {
                if (!currentFile) return;
                const saved = records.read(currentFile);
                if (saved) {
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
            if (uploading) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
            if (claimInput.value && completedClaim === claimInput.value) {
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
            transfer.stop();
            show('تم إيقاف الرفع ويمكنك متابعته لاحقًا', Number(progressBar?.getAttribute('aria-valuenow') || 0), true);
        });
        retryButton?.addEventListener('click', function () {
            void startUploadAndSubmit();
        });

        if (serverRejectedClaim) {
            void records.ready.then(clearRecord);
        } else if (claimInput.value) {
            fileInput.removeAttribute('required');
            fileInput.removeAttribute('data-required');
        }
        window.addEventListener('beforeunload', event => {
            if (!uploading || submittingAfterUpload) return;
            event.preventDefault();
            event.returnValue = '';
        });
        return Object.freeze({isBusy: () => uploading || submittingAfterUpload});
    };

    window.RoknBunnyUploadForm = Object.freeze({create});
})(window);
