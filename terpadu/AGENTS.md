# AGENTS.md - folder /terpadu/

## Ruang Lingkup & Isolasi Proyek (Satu Arah)
Direktori ini (proyek terpadu) dan seluruh sub-folder di dalamnya menerapkan **isolasi satu arah** terhadap proyek utama (folder/repository di atasnya):

* **Arah Ke Atas (Dilarang):** Proyek di dalam folder ini tidak diizinkan mengakses, mengubah, atau mengutak-atik proyek di atasnya.
* **Arah Ke Bawah (Diizinkan):** Proyek utama di atas folder ini memiliki hak akses penuh dan diperbolehkan membaca, mengelola, atau memodifikasi isi di dalam direktori proyek terpadu ini.

Sebagai AI coding agent yang bekerja di dalam konteks direktori ini, Anda **WAJIB** mematuhi aturan berikut:
1. **Batas Konteks & Modifikasi:** Jangan membaca, merujuk, mengimpor, atau memodifikasi file, konfigurasi, skema database, maupun dependency yang berada di folder proyek di atas direktori ini tanpa instruksi eksplisit.
2. **Kemandirian Konfigurasi:** Gunakan file konfigurasi lokal (`.json`, dll.) yang ada di dalam sub-direktori proyek masing-masing. Jangan menimpa atau bergantung pada variabel lingkungan milik proyek di atasnya.
3. **Alur Kerja Terpisah:** Anggap setiap sub-proyek di bawah folder ini sebagai entitas mandiri yang tidak berbagi *state*, migrasi database, atau *build pipeline* dengan proyek di atasnya.

---

## Aturan Penyimpanan Objek (S3 / Object Storage)
Jika terdapat proyek di bawah folder ini yang mengimplementasikan penyimpanan berbasis S3 (blomp atau layanan S3-compatible lainnya):

* **Wajib Isolasi Prefix/Direktori:** Seluruh file atau objek yang diunggah **HARUS** ditempatkan di dalam direktori/prefix khusus berikut sebagai root bucket nya:
  ```text
  /ukmipolmed/terpadu/
  ```