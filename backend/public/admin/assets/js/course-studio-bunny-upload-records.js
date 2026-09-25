'use strict';

(function (window) {
    // Durable resume identity, scoped to one owner, course, section, tab and file.
    const create = ({ownerId, courseId, sectionId, currentAuthoringVersion}) => {
        const recordVersion = 3;
        let currentStorageKey = null;
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
            Object.assign(record, {
                version: recordVersion,
                ownerId,
                courseId,
                sectionId: sectionId(),
                authoringVersion: Number(record.authoringVersion || currentAuthoringVersion()),
                savedAt: Date.now(),
            });
            if (!currentStorageKey) throw new Error('تعذر حفظ حالة الرفع');
            localStorage.setItem(currentStorageKey, JSON.stringify(record));
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
        };

        return Object.freeze({ready: tabReady, read: readRecord, save: saveRecord, clear: clearRecord,
            release: () => { currentStorageKey = null; }, newOperationId: operationId, fingerprint});
    };

    window.RoknBunnyUploadRecords = Object.freeze({create});
})(window);
