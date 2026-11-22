/**
 * public/assets/js/modal.js — Modal UI Utility
 * Replaces window.alert/confirm with styled modal dialogs
 */

(function () {
    'use strict';

    let modalOverlay = null;
    let modalResolve = null;

    function ensureModal() {
        if (modalOverlay) return modalOverlay;

        modalOverlay = document.createElement('div');
        modalOverlay.className = 'modal-overlay';
        modalOverlay.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
                <div class="modal-icon" id="modal-icon"></div>
                <h3 class="modal-title" id="modal-title"></h3>
                <p class="modal-message" id="modal-message"></p>
                <div class="modal-actions" id="modal-actions"></div>
            </div>
        `;
        document.body.appendChild(modalOverlay);

        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) {
                closeModal('cancel');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
                closeModal('cancel');
            }
        });

        return modalOverlay;
    }

    function showModal(options) {
        if (typeof window.ukmiHideLoadingOverlay === 'function') {
            window.ukmiHideLoadingOverlay();
        }
        const overlay = ensureModal();
        const iconEl = overlay.querySelector('#modal-icon');
        const titleEl = overlay.querySelector('#modal-title');
        const messageEl = overlay.querySelector('#modal-message');
        const actionsEl = overlay.querySelector('#modal-actions');

        const type = options.type || 'info';
        const iconMap = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ',
            confirm: '?'
        };
        const classMap = {
            success: 'success',
            error: 'error',
            warning: 'warning',
            info: 'info',
            confirm: 'info'
        };

        iconEl.textContent = iconMap[type] || 'ℹ';
        iconEl.className = 'modal-icon ' + classMap[type];
        titleEl.textContent = options.title || 'Pemberitahuan';
        messageEl.textContent = options.message || '';

        actionsEl.innerHTML = '';

        const buttons = options.buttons || [
            { text: 'OK', type: 'primary', value: true }
        ];

        buttons.forEach(btn => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'modal-btn ' + (btn.type || 'primary');
            button.textContent = btn.text;
            button.addEventListener('click', () => closeModal(btn.value));
            actionsEl.appendChild(button);
        });

        overlay.classList.add('active');

        return new Promise((resolve) => {
            modalResolve = resolve;
        });
    }

    function closeModal(value) {
        if (!modalOverlay) return;
        modalOverlay.classList.remove('active');
        if (modalResolve) {
            modalResolve(value);
            modalResolve = null;
        }
    }

    window.Modal = {
        alert: function (message, title) {
            return showModal({
                type: 'info',
                title: title || 'Pemberitahuan',
                message: message,
                buttons: [{ text: 'OK', type: 'primary', value: true }]
            });
        },

        success: function (message, title) {
            return showModal({
                type: 'success',
                title: title || 'Berhasil',
                message: message,
                buttons: [{ text: 'OK', type: 'primary', value: true }]
            });
        },

        error: function (message, title) {
            return showModal({
                type: 'error',
                title: title || 'Kesalahan',
                message: message,
                buttons: [{ text: 'OK', type: 'danger', value: true }]
            });
        },

        warning: function (message, title) {
            return showModal({
                type: 'warning',
                title: title || 'Peringatan',
                message: message,
                buttons: [{ text: 'OK', type: 'primary', value: true }]
            });
        },

        confirm: function (message, title) {
            return showModal({
                type: 'confirm',
                title: title || 'Konfirmasi',
                message: message,
                buttons: [
                    { text: 'Batal', type: 'secondary', value: false },
                    { text: 'Ya', type: 'primary', value: true }
                ]
            });
        },

        withLoading: async function (promiseOrFn, loadingText) {
            if (typeof window.ukmiShowLoadingOverlay === 'function') {
                window.ukmiShowLoadingOverlay(loadingText || 'Memproses permintaan, mohon tunggu...');
            }
            try {
                return typeof promiseOrFn === 'function' ? await promiseOrFn() : await promiseOrFn;
            } finally {
                if (typeof window.ukmiHideLoadingOverlay === 'function') {
                    window.ukmiHideLoadingOverlay();
                }
            }
        }
    };
})();