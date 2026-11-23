/**
 * public/assets/js/pwa-offline.js — Engine PWA Offline-First & Auto-Sync
 * Mendukung dua PWA terpisah:
 * - mode: 'laporan' (index.php)
 * - mode: 'daftar'  (daftar.php)
 */

(function () {
    'use strict';

    const DB_NAME = 'mai_pwa_offline_db';
    const DB_VERSION = 1;
    const STORE_LAPORAN = 'queue_laporan';
    const STORE_DAFTAR = 'queue_daftar';
    const STORE_TEAMS = 'cache_teams';

    const BIO_OFFLINE_KEY = 'mai_bio_offline_grant_v1';
    const LAPORAN_DRAFT_KEY = 'mai_laporan_draft_v1';

    function openDb() {
        return new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB tidak didukung browser ini.'));
                return;
            }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_LAPORAN)) {
                    db.createObjectStore(STORE_LAPORAN, { keyPath: 'id', autoIncrement: true });
                }
                if (!db.objectStoreNames.contains(STORE_DAFTAR)) {
                    db.createObjectStore(STORE_DAFTAR, { keyPath: 'id', autoIncrement: true });
                }
                if (!db.objectStoreNames.contains(STORE_TEAMS)) {
                    db.createObjectStore(STORE_TEAMS, { keyPath: 'id' });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async function dbGetAll(storeName) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(storeName, 'readonly');
            const store = tx.objectStore(storeName);
            const req = store.getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
    }

    async function dbAdd(storeName, item) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(storeName, 'readwrite');
            const store = tx.objectStore(storeName);
            const req = store.add(item);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async function dbPut(storeName, item) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(storeName, 'readwrite');
            const store = tx.objectStore(storeName);
            const req = store.put(item);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async function dbDelete(storeName, key) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(storeName, 'readwrite');
            const store = tx.objectStore(storeName);
            const req = store.delete(key);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
        });
    }

    async function dbGet(storeName, key) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(storeName, 'readonly');
            const store = tx.objectStore(storeName);
            const req = store.get(key);
            req.onsuccess = () => resolve(req.result || null);
            req.onerror = () => reject(req.error);
        });
    }

    function isIosDevice() {
        const ua = window.navigator.userAgent || '';
        const isAppleMobile = /iPad|iPhone|iPod/.test(ua);
        const isIpadOs = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
        return isAppleMobile || isIpadOs;
    }

    function isStandaloneMode() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    function saveBioOfflineGrant(data) {
        if (!data || !data.offline_token) return;
        try {
            localStorage.setItem(BIO_OFFLINE_KEY, JSON.stringify({
                offline_token: data.offline_token,
                entity_type: data.entity_type || '',
                entity_id: data.entity_id || '',
                entity_name: data.entity_name || '',
                role: data.role || '',
                allowed_team_ids: Array.isArray(data.allowed_team_ids) ? data.allowed_team_ids : null,
                saved_at: Date.now()
            }));
        } catch (e) {}
    }

    function getBioOfflineGrant() {
        try {
            const raw = localStorage.getItem(BIO_OFFLINE_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || !parsed.offline_token) return null;
            // Berlaku 30 hari
            if (Date.now() - (parsed.saved_at || 0) > 30 * 86400 * 1000) {
                return null;
            }
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function serializeFormToQueue(formEl, fileFieldName) {
        const fields = [];
        const fd = new FormData(formEl);
        let fileData = null;

        for (const [key, val] of fd.entries()) {
            if (val instanceof File) {
                if (key === fileFieldName && val.size > 0) {
                    fileData = {
                        name: val.name || 'upload.webp',
                        type: val.type || 'image/webp',
                        blob: val
                    };
                }
            } else {
                fields.push([key, String(val)]);
            }
        }
        return { fields, fileData };
    }

    function buildFormDataFromQueue(item, fileFieldName) {
        const fd = new FormData();
        (item.fields || []).forEach(([k, v]) => fd.append(k, v));
        fd.append('_ajax', '1');
        if (item.offline_token) {
            fd.append('biometric_offline_token', item.offline_token);
        }
        if (item.fileData && item.fileData.blob) {
            const f = new File([item.fileData.blob], item.fileData.name || 'image.webp', {
                type: item.fileData.type || 'image/webp'
            });
            fd.append(fileFieldName, f);
        }
        return fd;
    }

    async function registerSw(swPath) {
        if (!('serviceWorker' in navigator)) return null;
        try {
            const reg = await navigator.serviceWorker.register(swPath, { scope: './' });
            if ('periodicSync' in reg) {
                try {
                    const status = await navigator.permissions.query({ name: 'periodic-background-sync' });
                    if (status.state === 'granted') {
                        await reg.periodicSync.register('mai-periodic-sync', { minInterval: 12 * 60 * 60 * 1000 });
                    }
                } catch (e) {}
            }
            return reg;
        } catch (e) {
            console.warn('SW register failed:', e);
            return null;
        }
    }

    async function requestBackgroundSync(reg, tag) {
        if (reg && 'sync' in reg) {
            try {
                await reg.sync.register(tag);
            } catch (e) {}
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    window.MaiPwa = {
        init: async function (options) {
            const mode = options.mode === 'daftar' ? 'daftar' : 'laporan';
            const storeName = mode === 'daftar' ? STORE_DAFTAR : STORE_LAPORAN;
            const syncTag = mode === 'daftar' ? 'mai-sync-registrations' : 'mai-sync-reports';
            const submitUrl = options.submitUrl || (mode === 'daftar' ? 'daftar' : 'submit');
            const fileFieldName = mode === 'daftar' ? 'foto_profil' : 'dokumentasi';
            const formEl = document.getElementById(options.formId);

            const swReg = await registerSw(options.swUrl || './sw.js');

            // Simpan snapshot tim ke IndexedDB jika tersedia (untuk mode laporan offline)
            if (mode === 'laporan' && Array.isArray(options.initialTeams)) {
                try {
                    await dbPut(STORE_TEAMS, { id: 'all_teams', teams: options.initialTeams, updated_at: Date.now() });
                } catch (e) {}
            }

            // Referensi elemen UI
            const statusBar = document.getElementById('pwaStatusBar');
            const statusText = document.getElementById('pwaStatusText');
            const btnSyncNow = document.getElementById('pwaBtnSyncNow');
            const queuePanel = document.getElementById('offlineQueuePanel');
            const queueList = document.getElementById('offlineQueueList');
            const installBanner = document.getElementById('pwaInstallBanner');
            const btnInstall = document.getElementById('pwaBtnInstall');
            const btnDismissInstall = document.getElementById('pwaBtnDismissInstall');
            const iosGuide = document.getElementById('pwaIosGuide');
            const btnDismissIos = document.getElementById('pwaBtnDismissIos');

            let deferredPrompt = null;
            let isSyncing = false;

            function updateOnlineUi() {
                if (!statusBar || !statusText) return;
                statusBar.classList.remove('is-offline', 'is-syncing');
                if (isSyncing) {
                    statusBar.classList.add('is-syncing');
                    statusText.textContent = 'Menyinkronkan data ke server...';
                } else if (!navigator.onLine) {
                    statusBar.classList.add('is-offline');
                    statusText.textContent = 'Mode Offline — Anda tetap bisa mengisi & mengirim formulir';
                } else {
                    statusText.textContent = 'Online — Sinkronisasi otomatis aktif';
                }
            }

            async function renderQueue() {
                if (!queuePanel || !queueList) return;
                let items = [];
                try {
                    items = await dbGetAll(storeName);
                } catch (e) {
                    return;
                }

                if (items.length === 0) {
                    queuePanel.classList.remove('has-items');
                    if (btnSyncNow) btnSyncNow.style.display = 'none';
                    queueList.innerHTML = '';
                    return;
                }

                queuePanel.classList.add('has-items');
                if (btnSyncNow) {
                    btnSyncNow.style.display = navigator.onLine ? 'inline-block' : 'none';
                }

                queueList.innerHTML = '';
                items.forEach((item) => {
                    const row = document.createElement('div');
                    row.className = 'pwa-queue-item';
                    const title = item.label || (mode === 'daftar' ? 'Pendaftaran Offline' : 'Laporan Offline');
                    const timeStr = new Date(item.created_at || Date.now()).toLocaleString('id-ID');
                    const statusClass = item.last_error ? 'error' : '';
                    const statusLabel = item.last_error ? ('Gagal: ' + item.last_error) : 'Menunggu Koneksi';

                    row.innerHTML = `
                        <div class="pwa-queue-meta">
                            <div class="pwa-queue-name">${escapeHtml(title)}</div>
                            <div class="pwa-queue-sub">Disimpan: ${escapeHtml(timeStr)}</div>
                        </div>
                        <span class="pwa-queue-status ${statusClass}">${escapeHtml(statusLabel)}</span>
                        <button type="button" class="pwa-btn-mini danger" data-del-id="${item.id}">Hapus</button>
                    `;
                    row.querySelector('[data-del-id]').addEventListener('click', async () => {
                        await dbDelete(storeName, item.id);
                        await renderQueue();
                    });
                    queueList.appendChild(row);
                });
            }

            async function syncQueue() {
                if (isSyncing || !navigator.onLine) return;
                let items = [];
                try {
                    items = await dbGetAll(storeName);
                } catch (e) {
                    return;
                }
                if (items.length === 0) return;

                isSyncing = true;
                updateOnlineUi();

                let syncedCount = 0;
                let lastSuccessData = null;

                for (const item of items) {
                    try {
                        const fd = buildFormDataFromQueue(item, fileFieldName);
                        const res = await fetch(submitUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json' },
                            body: fd
                        });
                        const data = await res.json().catch(() => null);

                        if (res.ok && data && data.success) {
                            await dbDelete(storeName, item.id);
                            syncedCount++;
                            lastSuccessData = data;
                        } else {
                            item.last_error = (data && data.message) ? data.message : `HTTP ${res.status}`;
                            await dbPut(storeName, item);
                        }
                    } catch (err) {
                        // Koneksi terputus lagi saat sinkronisasi
                        break;
                    }
                }

                isSyncing = false;
                updateOnlineUi();
                await renderQueue();

                if (syncedCount > 0 && window.Modal) {
                    if (mode === 'laporan' && lastSuccessData && lastSuccessData.view_url) {
                        Modal.success(
                            `${syncedCount} laporan offline berhasil disinkronkan ke server dan grup WhatsApp.`,
                            'Sinkronisasi Berhasil'
                        );
                    } else if (mode === 'daftar' && lastSuccessData) {
                        Modal.success(
                            `${syncedCount} pendaftaran offline berhasil dikirim! Kode: ${lastSuccessData.registration_id || '-'}`,
                            'Sinkronisasi Berhasil'
                        );
                    }
                }
            }

            // Install Prompt (Android/Desktop) & iOS Manual Guide
            if (!isStandaloneMode()) {
                if (isIosDevice() && iosGuide) {
                    const dismissed = sessionStorage.getItem('mai_ios_guide_dismissed');
                    if (!dismissed) {
                        iosGuide.classList.add('active');
                    }
                    if (btnDismissIos) {
                        btnDismissIos.addEventListener('click', () => {
                            iosGuide.classList.remove('active');
                            sessionStorage.setItem('mai_ios_guide_dismissed', '1');
                        });
                    }
                }

                window.addEventListener('beforeinstallprompt', (e) => {
                    e.preventDefault();
                    deferredPrompt = e;
                    if (installBanner) installBanner.classList.add('active');
                });

                if (btnInstall) {
                    btnInstall.addEventListener('click', async () => {
                        if (!deferredPrompt) return;
                        deferredPrompt.prompt();
                        await deferredPrompt.userChoice;
                        deferredPrompt = null;
                        if (installBanner) installBanner.classList.remove('active');
                    });
                }

                if (btnDismissInstall && installBanner) {
                    btnDismissInstall.addEventListener('click', () => {
                        installBanner.classList.remove('active');
                    });
                }
            }

            // Intersepsi Form Submit agar bekerja Offline & saat koneksi putus mendadak
            if (formEl) {
                formEl.addEventListener('submit', async (e) => {
                    // Jika offline, langsung simpan ke IndexedDB
                    if (!navigator.onLine) {
                        e.preventDefault();
                        await queueCurrentForm();
                        return;
                    }

                    // Jika online, gunakan fetch AJAX agar bisa fallback ke IndexedDB jika jaringan gagal
                    e.preventDefault();
                    const submitBtn = formEl.querySelector('button[type="submit"]');
                    const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Mengirim...';
                    }
                    if (typeof window.ukmiShowLoadingOverlay === 'function') {
                        window.ukmiShowLoadingOverlay('Mengirim & menyimpan data, mohon tunggu...');
                    }

                    try {
                        const fd = new FormData(formEl);
                        fd.append('_ajax', '1');
                        const grant = getBioOfflineGrant();
                        if (mode === 'laporan' && grant && grant.offline_token) {
                            fd.append('biometric_offline_token', grant.offline_token);
                        }

                        const res = await fetch(submitUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json' },
                            body: fd
                        });
                        const data = await res.json().catch(() => null);

                        if (res.ok && data && data.success) {
                            try {
                                localStorage.removeItem(mode === 'daftar' ? 'mai_daftar_draft_v1' : LAPORAN_DRAFT_KEY);
                            } catch (err) {}
                            if (mode === 'laporan' && data.view_url) {
                                window.location.href = data.view_url;
                                return;
                            }
                            if (mode === 'daftar' && data.redirect_url) {
                                window.location.href = data.redirect_url;
                                return;
                            }
                        } else {
                            const msg = (data && data.message) ? data.message : 'Gagal mengirim data ke server.';
                            if (window.Modal) {
                                Modal.error(msg);
                            } else {
                                alert(msg);
                            }
                        }
                    } catch (networkErr) {
                        // Jaringan gagal di tengah jalan -> simpan ke antrean offline
                        await queueCurrentForm();
                    } finally {
                        if (typeof window.ukmiHideLoadingOverlay === 'function') {
                            window.ukmiHideLoadingOverlay();
                        }
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origBtnHtml;
                        }
                    }
                });
            }

            async function queueCurrentForm() {
                const { fields, fileData } = serializeFormToQueue(formEl, fileFieldName);
                const grant = getBioOfflineGrant();
                let label = mode === 'daftar' ? 'Pendaftaran Peserta' : 'Laporan Mentoring';
                if (mode === 'daftar') {
                    const nama = formEl.elements['nama_lengkap']?.value || '';
                    if (nama) label = `Pendaftaran: ${nama}`;
                } else {
                    const tema = formEl.elements['tema']?.value || '';
                    const timSel = formEl.elements['tim_id'];
                    const timText = timSel && timSel.selectedIndex >= 0 ? timSel.options[timSel.selectedIndex].text : '';
                    label = timText ? `${timText.split('•')[0].trim()}${tema ? ' — ' + tema : ''}` : (tema || label);
                }

                await dbAdd(storeName, {
                    label,
                    fields,
                    fileData,
                    offline_token: (mode === 'laporan' && grant) ? grant.offline_token : '',
                    created_at: Date.now(),
                    last_error: ''
                });

                await requestBackgroundSync(swReg, syncTag);
                await renderQueue();

                try {
                    localStorage.removeItem(mode === 'daftar' ? 'mai_daftar_draft_v1' : LAPORAN_DRAFT_KEY);
                } catch (e) {}
                formEl.reset();

                if (window.Modal) {
                    Modal.success(
                        'Data Anda telah disimpan di perangkat (Antrean Offline) dan akan otomatis dikirim ke server saat koneksi internet kembali.',
                        'Tersimpan Offline'
                    );
                }
            }

            // Event Listeners Koneksi & Service Worker
            window.addEventListener('online', () => {
                updateOnlineUi();
                syncQueue();
            });
            window.addEventListener('offline', () => {
                updateOnlineUi();
                renderQueue();
            });
            if (btnSyncNow) {
                btnSyncNow.addEventListener('click', () => syncQueue());
            }
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.addEventListener('message', (event) => {
                    if (event.data && event.data.type === 'PWA_SYNC_REQUEST') {
                        syncQueue();
                    }
                });
            }

            updateOnlineUi();
            await renderQueue();
            if (navigator.onLine) {
                syncQueue();
            }
        },

        saveBioOfflineGrant,
        getBioOfflineGrant,

        getCachedTeamById: async function (teamId) {
            try {
                const record = await dbGet(STORE_TEAMS, 'all_teams');
                if (!record || !Array.isArray(record.teams)) return null;
                return record.teams.find(t => t.id === teamId) || null;
            } catch (e) {
                return null;
            }
        }
    };
})();
