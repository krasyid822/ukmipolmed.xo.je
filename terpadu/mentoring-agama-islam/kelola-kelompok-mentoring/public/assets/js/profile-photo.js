/**
 * profile-photo.js — Live Preview, Kompresi Persegi & Validasi Foto Selfie
 *
 * - Menampilkan pratinjau langsung sebelum upload.
 * - Memotong gambar menjadi persegi (1:1) 720x720 dan mengompres ke JPEG.
 * - Memeriksa keberadaan wajah (FaceDetector API) bila peramban mendukung.
 */

class ProfilePhotoPicker {
    constructor(inputElement, previewElement, infoElement, options = {}) {
        this.input = inputElement;
        this.preview = previewElement;
        this.info = infoElement;
        this.size = options.size || 512;
        this.quality = options.quality || 0.80;
        this.requireFace = options.requireFace !== false;
        this.crop = options.crop === 'contain' ? 'contain' : 'cover';
        this.maxBytes = options.maxBytes || 8 * 1024 * 1024;
        this.allowClipboard = Boolean(options.allowClipboard);
        this._isInternalChange = false;

        if (!this.input) return;

        this.input.addEventListener('change', (e) => {
            if (this._isInternalChange) return;
            const file = e.target.files && e.target.files[0];
            if (!file) {
                this.reset();
                return;
            }
            this.handleFile(file);
        });

        if (this.allowClipboard) {
            this.initClipboardSupport();
        }
    }

    initClipboardSupport() {
        const bar = document.createElement('div');
        bar.className = 'photo-clipboard-bar';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'photo-clipboard-btn';
        btn.textContent = '📋 Tempel Foto dari Clipboard';

        const hint = document.createElement('span');
        hint.className = 'photo-clipboard-hint';
        hint.textContent = 'atau tekan Ctrl+V / Cmd+V';

        bar.appendChild(btn);
        bar.appendChild(hint);
        this.input.insertAdjacentElement('afterend', bar);

        btn.addEventListener('click', async () => {
            if (!navigator.clipboard || typeof navigator.clipboard.read !== 'function') {
                this.setStatus('Peramban tidak mendukung tombol tempel. Gunakan shortcut <b>Ctrl+V / Cmd+V</b> di halaman ini.', '#fbbf24');
                return;
            }
            try {
                const items = await navigator.clipboard.read();
                for (const item of items) {
                    const imgType = item.types.find((t) => t.startsWith('image/'));
                    if (imgType) {
                        const blob = await item.getType(imgType);
                        const file = new File([blob], 'clipboard-image.png', {
                            type: imgType,
                            lastModified: Date.now()
                        });
                        await this.handleFile(file);
                        return;
                    }
                }
                this.setStatus('✕ Tidak ada gambar di clipboard.', '#f87171');
            } catch (err) {
                this.setStatus('Tidak ada gambar di clipboard atau izin ditolak. Anda juga bisa langsung menekan <b>Ctrl+V / Cmd+V</b> di halaman ini.', '#fbbf24');
            }
        });

        document.addEventListener('paste', (e) => {
            const clipboardData = e.clipboardData;
            if (!clipboardData) return;

            let imageFile = null;
            if (clipboardData.items) {
                for (const item of clipboardData.items) {
                    if (item.type && item.type.startsWith('image/')) {
                        imageFile = item.getAsFile();
                        break;
                    }
                }
            } else if (clipboardData.files) {
                for (const f of clipboardData.files) {
                    if (f.type && f.type.startsWith('image/')) {
                        imageFile = f;
                        break;
                    }
                }
            }

            if (imageFile) {
                e.preventDefault();
                this.handleFile(imageFile);
            }
        });
    }

    reset() {
        if (this.preview) {
            this.preview.innerHTML = '';
            this.preview.style.display = 'none';
        }
    }

    setStatus(html, color) {
        if (!this.info) return;
        this.info.innerHTML = html;
        this.info.style.color = color || '';
    }

    reject(message) {
        this.reset();
        this.setStatus('✕ ' + message, '#f87171');
        this.input.value = '';
    }

    async handleFile(file) {
        if (!file.type.startsWith('image/')) {
            this.reject('Berkas harus berupa gambar (JPG/PNG/WEBP).');
            return;
        }
        if (file.size > this.maxBytes) {
            this.reject('Ukuran foto maksimal 8 MB.');
            return;
        }

        let bitmap;
        try {
            bitmap = await createImageBitmap(file);
        } catch (err) {
            this.reject('Foto tidak dapat dibaca. Coba file lain.');
            return;
        }

        // Pemeriksaan wajah (opsional, hanya jika peramban mendukung)
        let faceNote = '';
        if (this.requireFace && 'FaceDetector' in window) {
            try {
                const detector = new FaceDetector({ fastMode: true, maxDetectedFaces: 3 });
                const faces = await detector.detect(bitmap);
                if (!faces || faces.length === 0) {
                    this.reject('Wajah tidak terdeteksi. Unggah foto selfie yang memperlihatkan wajah dengan jelas.');
                    return;
                }
                faceNote = ` • Wajah terdeteksi: ${faces.length}`;
            } catch (err) {
                // Abaikan kegagalan deteksi, tetap lanjutkan upload
            }
        }

        const origSizeKB = (file.size / 1024).toFixed(1);

        const canvas = document.createElement('canvas');
        canvas.width = this.size;
        canvas.height = this.size;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, this.size, this.size);

        if (this.crop === 'contain') {
            // Logo: seluruh bagian gambar ditampilkan (tidak terpotong)
            const scale = Math.min(this.size / bitmap.width, this.size / bitmap.height);
            const w = Math.max(1, Math.round(bitmap.width * scale));
            const h = Math.max(1, Math.round(bitmap.height * scale));
            ctx.drawImage(bitmap, Math.floor((this.size - w) / 2), Math.floor((this.size - h) / 2), w, h);
        } else {
            // Foto selfie: potong persegi di tengah
            const side = Math.min(bitmap.width, bitmap.height);
            const sx = Math.floor((bitmap.width - side) / 2);
            const sy = Math.floor((bitmap.height - side) / 2);
            ctx.drawImage(bitmap, sx, sy, side, side, 0, 0, this.size, this.size);
        }

        canvas.toBlob((blob) => {
            if (!blob) {
                this.reject('Gagal mengompres foto.');
                return;
            }

            const compSizeKB = (blob.size / 1024).toFixed(1);
            const compFile = new File([blob], 'foto-profil.webp', {
                type: 'image/webp',
                lastModified: Date.now()
            });

            const dt = new DataTransfer();
            dt.items.add(compFile);
            this.input.files = dt.files;
            this._isInternalChange = true;
            this.input.dispatchEvent(new Event('change', { bubbles: true }));
            this._isInternalChange = false;

            if (this.preview) {
                this.preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = URL.createObjectURL(blob);
                img.alt = 'Pratinjau foto profil';
                this.preview.appendChild(img);
                this.preview.style.display = 'block';
            }

            this.setStatus(
                `<span style="color:#34d399;font-weight:600;">✓ Foto siap diunggah:</span> ` +
                `${origSizeKB} KB → <b>${compSizeKB} KB</b> (${this.size}×${this.size}px)${faceNote}`,
                ''
            );
        }, 'image/webp', this.quality);
    }
}

function initProfilePhotoPickers() {
    document.querySelectorAll('[data-profile-photo]').forEach((input) => {
        const preview = document.getElementById(input.dataset.preview || '');
        const info = document.getElementById(input.dataset.info || '');
        new ProfilePhotoPicker(input, preview, info, {
            requireFace: input.dataset.requireFace !== 'false',
            crop: input.dataset.crop === 'contain' ? 'contain' : 'cover',
            allowClipboard: input.dataset.allowClipboard === 'true'
        });
    });
}

document.addEventListener('DOMContentLoaded', initProfilePhotoPickers);
