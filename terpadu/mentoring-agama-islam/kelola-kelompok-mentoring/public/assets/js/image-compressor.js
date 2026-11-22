/**
 * image-compressor.js — Client-Side Image Compression & Live Preview
 * Mengompres foto sebelum upload via Canvas API: Max width 1280px, JPEG 75-80%
 */

class ClientImageCompressor {
    constructor(inputElement, previewContainer, infoElement) {
        this.input = inputElement;
        this.previewContainer = previewContainer;
        this.infoElement = infoElement;
        this.bindEvents();
    }

    bindEvents() {
        if (!this.input) return;

        this.input.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;

            this.processImage(file);
        });
    }

    processImage(file) {
        const origSizeKB = (file.size / 1024).toFixed(1);
        const reader = new FileReader();

        reader.onload = (event) => {
            const img = new Image();
            img.onload = () => {
                const maxWidth = 1280;
                let width = img.width;
                let height = img.height;

                if (width > maxWidth) {
                    height = Math.round((height * maxWidth) / width);
                    width = maxWidth;
                }

                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;

                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, width, height);
                ctx.drawImage(img, 0, 0, width, height);

                // Export WebP quality 0.78
                canvas.toBlob((blob) => {
                    if (!blob) return;
                    const compSizeKB = (blob.size / 1024).toFixed(1);

                    // Buat file baru dari Blob untuk menggantikan input file
                    const compFile = new File([blob], file.name.replace(/\.[^/.]+$/, ".webp"), {
                        type: 'image/webp',
                        lastModified: Date.now()
                    });

                    // Update DataTransfer pada input element
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(compFile);
                    this.input.files = dataTransfer.files;

                    // Render preview
                    if (this.previewContainer) {
                        this.previewContainer.innerHTML = '';
                        const previewImg = document.createElement('img');
                        previewImg.src = URL.createObjectURL(blob);
                        previewImg.style.width = '100%';
                        previewImg.style.maxHeight = '300px';
                        previewImg.style.objectFit = 'cover';
                        previewImg.style.borderRadius = '10px';
                        this.previewContainer.appendChild(previewImg);
                        this.previewContainer.style.display = 'block';
                    }

                    // Tampilkan info ukuran sebelum vs sesudah
                    if (this.infoElement) {
                        this.infoElement.innerHTML = `
                            <span style="color:#10b981; font-weight:600;">✓ Foto berhasil dioptimasi:</span> 
                            Ukuran awal: <b>${origSizeKB} KB</b> &rarr; Terkompresi: <b>${compSizeKB} KB</b> (${width}x${height}px)
                        `;
                    }
                }, 'image/webp', 0.78);
            };
            img.src = event.target.result;
        };

        reader.readAsDataURL(file);
    }
}
