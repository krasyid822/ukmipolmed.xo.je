/**
 * master-draft.js — Auto-save & auto-restore progres form Tambah Data Master
 */
(function () {
    'use strict';

    window.initMasterFormDraft = function initMasterFormDraft(options) {
        const opts = options || {};
        const key = opts.key;
        if (!key) return;

        const formEl = document.querySelector(opts.formSelector || 'form[method="POST"]');
        if (!formEl) return;

        const bannerEl = document.getElementById(opts.bannerId || 'draftRestoreBanner');
        const resetBtnEl = document.getElementById(opts.resetBtnId || 'btnResetDraft');
        const photoPreviewId = opts.photoPreviewId || '';
        const photoInfoId = opts.photoInfoId || '';

        const SKIP_TYPES = new Set(['file', 'submit', 'button', 'reset', 'hidden']);

        function readDraft() {
            try {
                const raw = localStorage.getItem(key);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        }

        function writeDraft(extra) {
            const current = readDraft() || {};
            Array.from(formEl.elements).forEach((el) => {
                if (!el.name || SKIP_TYPES.has(el.type)) return;
                if (el.type === 'checkbox') {
                    current[el.name] = el.checked;
                } else if (el.type === 'radio') {
                    if (el.checked) current[el.name] = el.value;
                } else {
                    current[el.name] = el.value;
                }
            });
            if (extra && typeof extra === 'object') {
                Object.assign(current, extra);
            }
            try {
                localStorage.setItem(key, JSON.stringify(current));
            } catch (e) {}
        }

        function restoreDraft() {
            const draft = readDraft();
            if (!draft) return;

            let hasData = false;

            Array.from(formEl.elements).forEach((el) => {
                if (!el.name || SKIP_TYPES.has(el.type)) return;
                const val = draft[el.name];
                if (val === undefined || val === null || val === '') return;

                if (el.type === 'checkbox') {
                    el.checked = Boolean(val);
                    hasData = true;
                    return;
                }
                if (el.type === 'radio') {
                    if (el.value === String(val)) {
                        el.checked = true;
                        hasData = true;
                    }
                    return;
                }

                if (el.tagName === 'SELECT') {
                    const hasOpt = Array.from(el.options).some((opt) => opt.value === String(val));
                    if (!hasOpt) return;
                    el.value = String(val);
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                    const wrapper = el.nextElementSibling;
                    if (wrapper && wrapper.classList.contains('searchable-select-wrapper')) {
                        const txt = wrapper.querySelector('.trigger-text');
                        if (txt && el.options[el.selectedIndex]) {
                            txt.textContent = el.options[el.selectedIndex].text;
                        }
                    }
                    hasData = true;
                    return;
                }

                el.value = String(val);
                hasData = true;
            });

            const errParam = new URLSearchParams(window.location.search).get('error') || '';
            const skipPhotoRestore = errParam === 'foto' || errParam === 'logo_duplikat';

            if (!skipPhotoRestore && typeof draft.foto_data_url === 'string' && draft.foto_data_url.startsWith('data:image/')) {
                fetch(draft.foto_data_url)
                    .then((res) => res.blob())
                    .then((blob) => {
                        const file = new File([blob], 'foto-profil.webp', {
                            type: blob.type || 'image/webp',
                            lastModified: Date.now()
                        });
                        const dt = new DataTransfer();
                        dt.items.add(file);
                        const fileInput = formEl.querySelector('input[type="file"][name="foto_profil"]');
                        if (fileInput) fileInput.files = dt.files;

                        if (photoPreviewId) {
                            const preview = document.getElementById(photoPreviewId);
                            if (preview) {
                                preview.innerHTML = '';
                                const img = document.createElement('img');
                                img.src = draft.foto_data_url;
                                img.alt = 'Pratinjau foto profil';
                                preview.appendChild(img);
                                preview.style.display = 'block';
                            }
                        }
                        if (photoInfoId) {
                            const info = document.getElementById(photoInfoId);
                            if (info) {
                                info.innerHTML = '<span style="color:#34d399;font-weight:600;">&#10003; Foto sebelumnya dipulihkan.</span>';
                            }
                        }
                    })
                    .catch(() => {});
                hasData = true;
            }

            if (hasData && bannerEl) {
                bannerEl.style.display = 'flex';
            }
        }

        restoreDraft();

        function normalizePhoneValue(raw) {
            let digits = String(raw || '').replace(/\D+/g, '');
            if (!digits) return '';
            if (digits.startsWith('00')) digits = digits.slice(2);
            if (digits.startsWith('62')) {
                const rest = digits.slice(2).replace(/^0+/, '');
                return rest ? '62' + rest : '62';
            }
            if (digits.startsWith('0')) return '62' + digits.slice(1);
            if (digits.startsWith('8')) return '62' + digits;
            return digits;
        }

        function normalizeClassValue(raw) {
            const val = String(raw || '').trim().replace(/\s+/g, '').toUpperCase();
            if (!val) return '';
            let m = val.match(/^([A-Z]+)-(\d*)([A-Z][A-Z0-9]*)$/);
            if (m) return m[1] + '-' + m[3];
            m = val.match(/^([A-Z]+)(\d+)([A-Z][A-Z0-9]*)$/);
            if (m) return m[1] + '-' + m[3];
            return val;
        }

        function autocorrectField(el, isBlur) {
            if (!el || !el.name || SKIP_TYPES.has(el.type) || el.tagName === 'SELECT') return false;
            const prev = el.value;
            let next = prev;

            if (el.name === 'nim') {
                next = prev.replace(/\D+/g, '').slice(0, 15);
            } else if (el.name === 'nomor_whatsapp') {
                next = isBlur ? normalizePhoneValue(prev) : prev.replace(/[^\d+\-\s()]/g, '');
            } else if (el.name === 'kelas' && isBlur) {
                next = normalizeClassValue(prev);
            } else if (isBlur && ['nama_lengkap', 'nama_tim', 'tempat_lahir', 'alamat_sekarang', 'alamat_orang_tua', 'amanah_organisasi'].includes(el.name)) {
                next = prev.replace(/\s+/g, ' ').trim();
            }

            if (next !== prev) {
                el.value = next;
                return true;
            }
            return false;
        }

        formEl.addEventListener('input', (e) => {
            if (e.target && e.target.type === 'file') return;
            autocorrectField(e.target, false);
            writeDraft();
        });

        formEl.addEventListener('blur', (e) => {
            if (autocorrectField(e.target, true)) {
                writeDraft();
            }
        }, true);

        const submitBtn = formEl.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.addEventListener('click', () => {
                let changed = false;
                Array.from(formEl.elements).forEach((el) => {
                    if (autocorrectField(el, true)) changed = true;
                });
                if (changed) writeDraft();
            });
        }

        formEl.addEventListener('change', (e) => {
            if (e.target && e.target.type === 'file' && e.target.name === 'foto_profil') {
                const fileInput = e.target;
                setTimeout(() => {
                    const file = fileInput.files && fileInput.files[0];
                    if (!file) {
                        writeDraft({ foto_data_url: '' });
                        return;
                    }
                    if (file.size > 1500000) {
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = () => {
                        if (typeof reader.result === 'string') {
                            writeDraft({ foto_data_url: reader.result });
                        }
                    };
                    reader.readAsDataURL(file);
                }, 400);
                return;
            }
            writeDraft();
        });

        if (resetBtnEl) {
            resetBtnEl.addEventListener('click', () => {
                try { localStorage.removeItem(key); } catch (e) {}
                window.location.href = window.location.pathname;
            });
        }
    };
})();
