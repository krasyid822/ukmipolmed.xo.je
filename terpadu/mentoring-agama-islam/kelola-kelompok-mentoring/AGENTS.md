# AGENTS.md

Dokumen ini berisi panduan, aturan arsitektur, blueprint sistem, dan spesifikasi fitur yang harus dipatuhi oleh AI Agent maupun pengembang saat membangun atau memodifikasi sistem manajemen dan pelaporan mentoring ini.

## 1. Konfigurasi, Infrastruktur & Keamanan Penyimpanan (PHP + InfinityFree + Blomp S3)

Sistem dibangun berbasis PHP (menyesuaikan lingkungan hosting InfinityFree dan proyek utama) dengan pendekatan penyimpanan **Hybrid Local JSON + Blomp S3-Compatible Storage** yang terenkripsi, terkompresi, dan terproteksi.

### A. Base URL & Integrasi Konfigurasi (`default.json`)

* **Base URL Proyek:** `https://ukmipolmed.my.id/terpadu/mai/mentoring/`
* **Worker Redirector URL:** opsional. Bila diisi (mis. `https://laporan-mai.<subdomain-worker>.workers.dev/`), URL ini dipakai pada baris pertama pesan bot WhatsApp. Bila kosong, bot memakai URL laporan langsung (`<base_url>view?id=...`). Worker tetap disarankan untuk *link preview* karena anti-bot JS challenge InfinityFree.
* **Kredensial & Koneksi S3 (Blomp):** Digabung langsung ke `default.json` pada `storage.s3`, disinkronkan dari `s3_config.php` proyek utama (endpoint, region, bucket, access key, secret key, prefix). Tidak ada lagi `s3_config.local.php` terpisah.
* **Isolasi Sub-Prefix (Root Folder Proyek):** Key objek memakai `storage.s3.prefix` + `storage.sub_prefix` (mis. `ukmipolmed/terpadu/` + `mai/mentoring/`), sehingga seluruh data modul berada di `ukmipolmed/terpadu/mai/mentoring/`.
* **File Konfigurasi Lokal (`default.json`):** Pengganti `.env`; berada di `storage_secure/` (gitignored + diblokir HTTP).

```json
{
  "app": {
    "base_url": "https://ukmipolmed.my.id/terpadu/mai/mentoring/",
    "worker_redirector_url": "",
    "mode": "production"
  },
  "watobot": {
    "api_url": "https://api.watobot.xyz/api/message",
    "api_key": "***",
    "group_id": "120363271558091003@g.us"
  },
  "storage": {
    "sub_prefix": "mai/mentoring/",
    "s3": {
      "endpoint": "https://storage.blomp.com",
      "region": "us-east-1",
      "bucket": "***",
      "access_key": "***",
      "secret_key": "***",
      "path_style": true,
      "prefix": "ukmipolmed/terpadu/"
    },
    "folders": {
      "laporan_json": "laporan_json/",
      "master_json": "master_json/",
      "dokumentasi": "dokumentasi/",
      "og_meta": "og_meta/",
      "profil": "profil/"
    }
  }
}
```

### B. Proteksi File Lokal via `.htaccess`

* File konfigurasi (`default.json`), *pointer registry* lokal (`pointer_laporan.json`, `pointer_master.json`), serta state runtime (antrean bot, token biometrik, lock) wajib diletakkan di folder terlindungi (`/storage_secure/`) dan diblokir akses HTTP langsungnya via `.htaccess`:

```apache
<FilesMatch "\.(json|log|bak|php|lock|tmp)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>
```

### C. Arsitektur Penyimpanan Hybrid (Local Pointer + Blomp S3)

1. **Local Hosting (`pointer_laporan.json` & `pointer_master.json`):**
   * Menyimpan *pointer* path objek S3 serta **kunci enkripsi/dekripsi** (`key`, `iv`, `tag`) untuk setiap pecahan file JSON di Blomp.
   * Menyimpan indeks ringkas (ID, nama, waktu) untuk mempercepat pencarian dan *rendering* dropdown tanpa perlu mengunduh seluruh file dari Blomp.
2. **Blomp S3 Storage (di dalam `<prefix_utama>/mai/mentoring/`):**
   * `laporan_json/` *(Privat & Terenkripsi)*: Pecahan file JSON laporan mentoring terkompresi & terenkripsi.
   * `master_json/` *(Privat & Terenkripsi)*: Pecahan file JSON untuk CRUD Data Master (Tim, Mentor, Mentee) dengan teknik enkripsi & kompresi yang identik dengan `laporan_json/`.
   * `dokumentasi/`: File gambar dokumentasi hasil kompresi (`<report_id>.jpg`). Blomp menolak GET anonim, jadi disajikan lewat `media.php` (signed GET di server) untuk halaman laporan dan `og:image` Worker.
   * `og_meta/`: File JSON ringan (`<report_id>.json`) berisi `title` dan `description` singkat (tanpa data sensitif mentee) untuk dibaca Cloudflare Worker lewat `media.php`.
   * `profil/`: Foto profil mentor/mentee/kelompok, juga disajikan lewat `media.php`.
   * Yang boleh dilayani `media.php` hanya `dokumentasi/`, `og_meta/`, dan `profil/`. `laporan_json/` dan `master_json/` tetap privat.

### D. Pipeline Optimasi, Kompresi & Enkripsi (Wajib Sebelum Upload ke Blomp)

1. **Untuk Data JSON Privat (`laporan_json/` & `master_json/`):**
   * **Minifikasi** (`json_encode` tanpa `JSON_PRETTY_PRINT`) $\rightarrow$ **Kompresi** (`gzencode` level 9) $\rightarrow$ **Enkripsi** (`openssl_encrypt` dengan `aes-256-gcm`).
2. **Untuk File Gambar (`dokumentasi/`):**
   * **Optimasi & Kompresi:** Kompresi gambar di sisi client (Canvas API) maupun server (PHP GD / Imagick) sebelum upload, *resize* maksimal lebar `1280px` dengan format JPEG kualitas `75% - 80%`.

## 2. Solusi Anti-Bot InfinityFree & Cloudflare Worker Redirector

* **Latar Belakang:** Hosting InfinityFree memiliki sistem proteksi *JS bot challenge* (`aes.js` / cookie `__test`) yang tidak dapat dieksekusi oleh crawler *link preview* WhatsApp (`WhatsApp/2.x`).
* **Arsitektur Solusi (`*.workers.dev`):**
  1. **Baris Pertama Pesan Bot:** Menggunakan URL Cloudflare Worker (`https://laporan-mai.<subdomain>.workers.dev/?id=<id_laporan>`).
  2. **Deteksi Crawler (WhatsApp, Telegram, Facebook, Twitter):** Worker mendeteksi header `User-Agent`. Jika terdeteksi sebagai bot preview, Worker mengambil metadata dari `og_meta/<id_laporan>.json` di Blomp dan merender HTML statis dengan tag `<meta property="og:image" content=".../dokumentasi/<id_laporan>.jpg">`.
  3. **Pengunjung Manusia (Browser):** Jika diakses oleh pengguna biasa, Worker langsung melakukan HTTP `302 Redirect` menuju `https://ukmipolmed.my.id/terpadu/mai/mentoring/view.php?id=<id_laporan>`, di mana browser pengguna melewati JS challenge secara otomatis dan menampilkan halaman laporan lengkap.

## 3. Kerangka Data Master (`master_json/`)

Seluruh operasi CRUD untuk data master disimpan dalam pecahan file JSON terkompresi dan terenkripsi di folder `master_json/` pada Blomp S3. Tidak ada fallback data lokal/dummy: bila pointer atau objek gagal dibaca, penyimpanan dibatalkan.

### A. Kerangka Biodata Mentor (`mentors`)

Menampung profil lengkap pendamping/mentor (mengakomodasi mentor yang masih berkuliah maupun yang sudah bekerja/alumni):

* `id`: String/UUID unik mentor (contoh: `"mtr-001"`).
* `nama_lengkap`: Nama lengkap beserta gelar (contoh: `"Ahmad Fauzi, S.Kom."`).
* `gender`: `"Laki-laki"` | `"Perempuan"`.
* `tempat_lahir`: Kota/kabupaten kelahiran.
* `tanggal_lahir`: Format `YYYY-MM-DD`.
* `nomor_whatsapp`: Format standar internasional (`"6281234567890"`).
* `status_kesibukan`: `"Kuliah"` | `"Bekerja"` | `"Kuliah & Bekerja"`.
* `detail_kesibukan`:
  * Jika **Kuliah**: berisi `nim`, `kelas`, dan `jadwal_kuliah` (`"Pagi"` | `"Sore"` | `"Tidak Menentu"`).
  * Jika **Bekerja**: berisi `instansi_perusahaan`, `profesi_jabatan`, dan `jadwal_kerja` (atau `nim`/`kelas` diisi `null`).
* `alamat_sekarang`: Alamat domisili saat ini.
* `alamat_orang_tua`: Alamat asal/orang tua.
* `amanah_organisasi`: Jabatan/amanah di organisasi (atau `"Tidak Ada"`).
* `status`: `"Aktif"` | `"Non-aktif"`.

### B. Kerangka Data Tim Mentoring (`teams`)

* `id`: String/UUID unik tim (contoh: `"tim-001"`).
* `nama_tim`: Nama kelompok mentoring (contoh: `"Tim Al-Fatih"`).
* `mentor_id`: Referensi ke `id` Mentor yang mendampingi.
* `status`: `"Aktif"` | `"Non-aktif"`.

### C. Kerangka Biodata & Tracking Mentee (`mentees`)

* `id`: String/UUID unik mentee (contoh: `"mte-001"`).
* `nama_lengkap`: Nama lengkap mentee.
* `tempat_lahir`: Tempat lahir.
* `tanggal_lahir`: Format `YYYY-MM-DD`.
* `gender`: `"Laki-laki"` | `"Perempuan"`.
* `kelas`: Kelas perkuliahan.
* `nim`: Nomor Induk Mahasiswa.
* `nomor_whatsapp`: Nomor WhatsApp aktif (`"628xxx"`).
* `alamat_sekarang`: Alamat tinggal sekarang.
* `alamat_orang_tua`: Alamat orang tua.
* `mentor_id`: Referensi ke `id` Mentor pendamping.
* `tim_id`: Referensi ke `id` Tim Mentoring tempat mentee berada.
* `jadwal_kuliah`: `"Pagi"` | `"Sore"` | `"Tidak Menentu"`.
* `amanah_organisasi`: Jabatan/amanah di organisasi jika ada (contoh: `"Staff Kaderisasi BEM"` atau `"Tidak Ada"`).
* **Logika Kondisi Keaktifan (`status_keaktifan`):**
  1. **`Aktif`**: Status default; tidak pernah absen atau absen $< 3$ kali berturut-turut.
  2. **`Peringatan`**: Otomatis berubah menjadi *Peringatan* jika mentee tercatat **Absen (tanpa keterangan) sebanyak 3 kali berturut-turut** pada laporan timnya.
  3. **`Tidak Aktif`**: Berubah menjadi *Tidak Aktif* apabila mentor melaporkan/menandai di form bahwa mentee ini **"tidak jelas kabarnya"**.

### D. Ketahanan Penyimpanan Master Data (WAJIB)

* **Append-only:** setiap penyimpanan master membuat objek versi baru
  `master_json/master_<YYYYmmdd-HHMMSS>_<rand>.enc`. Versi lama TIDAK boleh
  ditimpa; `pointer_master.json` menyimpan `revision`, `history` (maks. 30
  versi, terbaru lebih dulu), dan kunci enkripsi tiap versi.
* **Fail-closed:** bila pointer/objek gagal dibaca atau gagal didekripsi,
  penyimpanan wajib dibatalkan (jangan pernah menulis dataset kosong/hasil
  fallback). `MasterData::loadAll()` hanya untuk tampilan; semua jalur tulis
  WAJIB lewat `MasterData::transaction()`.
* **Validasi skema:** `MasterSchema::validate()` dijalankan sebelum upload
  (enum, tanggal, nomor WA `62…`, ID unik, integritas relasi tim/mentor).
* **Atomik & terkunci:** berkas JSON lokal ditulis via `JsonFile::writeAtomic()`;
  baca-ubah-tulis master memakai `flock` pada `storage_secure/master.lock`.
* **Backup/restore:** `master/backup.php` mengunduh bundel JSON berisi pointer,
  kunci, dan seluruh objek versi. `master/restore.php` memulihkan bundel
  (verifikasi checksum + dekripsi) atau rollback ke versi riwayat; keduanya
  menulis sebagai versi baru.

## 4. Standar UI/UX Form Publik & Halaman Laporan Utuh

### A. Standar Komponen UI Form Input (`index.php`)

1. **Searchable Dropdown (Wajib untuk Semua Pilihan `<select>`):**
   * Setiap elemen dropdown pada form (seperti pemilihan **Nama Tim**, **Moderator**, **Petugas Tadabbur**, **Petugas Kultum**, **Petugas Do'a**, maupun filter pada CRUD Master) **wajib dilengkapi kolom pencarian (*searchable select*)** (menggunakan Tom Select atau komponen custom ringan) agar mudah dicari ketika daftar sudah panjang.
   * Saat **Nama Tim** dipilih, form otomatis memuat data **Mentor** dan daftar **Mentee** pada tim tersebut untuk diisi status kehadirannya (`Hadir`, `Izin`, `Absen`, serta opsi tanda *"Tidak jelas kabarnya"*).
2. **Image Upload dengan Live Preview & Client-Side Compression:**
   * Input unggah foto dokumentasi wajib menampilkan **kotak pratinjau (*live preview*) gambar** secara langsung sebelum form dikirim, sehingga mentor dapat memastikan foto dokumentasi yang akan diunggah ke S3 dan tampil di bagian atas halaman laporan sudah tepat.
   * Dilengkapi info perbandingan ukuran file sebelum dan sesudah kompresi.

### B. Halaman Web Laporan Utuh (`view.php?id=<id_laporan>`)

* **URL:** `https://ukmipolmed.my.id/terpadu/mai/mentoring/view.php?id=<id_laporan>`
* **Tata Letak:** Menampilkan **foto dokumentasi kegiatan di bagian paling atas** (diambil dari `dokumentasi/<id_laporan>.jpg` di Blomp S3), diikuti oleh kartu/tampilan teks **Laporan Mentoring yang sama persis** format dan rinciannya dengan pesan yang dikirimkan oleh bot WhatsApp, lengkap dengan tombol *"Salin Teks Laporan"*.

## 5. Fitur: Auto Reporting Bot (Laporan Mentoring)

### A. Spesifikasi Request API

* **Endpoint:** `POST https://api.watobot.xyz/api/message` (dari `default.json`)
* **Dasbor API Key:** `https://watobot.xyz/dashboard/`
* **Headers:** `Content-Type: application/json` dan `x-api-key` (dari `default.json`)
* **Target Group (`to`):** `120363271558091003@g.us` (dari `default.json`)

### B. Template & Aturan Format Pesan (`message`)

```text
https://laporan-mai.<subdomain-worker>.workers.dev/?id=<id_laporan>
Laporan Mentoring

Nama Tim: <isi dengan data nama tim>

Waktu: <nama-hari, tanggal bulan tahun> (<jam mulai> sd <jam selesai>)
Tempat: <nama tempat dilaksanakan kegiatan>

Tema: <inti pembahasan mentoring>

Mentor: <nama mentor yang mendampingi>
Moderator: <nama yang bertugas sebagai moderator>
Tilawah: <progres pembacaan al quran kelompok ini>
Tadabbur: <nama yang bertugas> [ <nomor dan ayat surah yang ditadaburkan> - <inti pembahasan tadabbur> ]
Kultum: <nama yang bertugas> [ <inti pembahasan atau judul kultum> ]
Do'a: <nama yang bertugas membawakan doa>

Hadir:
     <list nama yang berhadir, nama mentor tidak perlu ikut ditulis>

Izin:
     <list nama yang tidak berhadir dan sudah konfirmasi izin>

Absen:
     <list nama yang tidak berhadir tanpa keterangan>

~Pesan otomatis oleh bot.
```

#### Aturan Formatting Wajib:

1. **Baris Pertama:** URL menuju Cloudflare Worker Redirector (`https://laporan-mai.<subdomain-worker>.workers.dev/?id=<id_laporan>`).
2. **Format Waktu:** Hari dan bulan dalam Bahasa Indonesia, diikuti rentang jam dalam kurung. Contoh: `Kamis, 19 November 2026 (16.00 sd 17.30)`.
3. **Daftar Kehadiran (`Hadir`, `Izin`, `Absen`):**
   * Diawali tepat **5 spasi** (`     `) sebelum nomor urut atau tanda strip (`-`).
   * **Nama Mentor tidak boleh dimasukkan** ke dalam daftar `Hadir`.
   * Jika ada peserta, gunakan penomoran berurut: `     1. Nama Peserta`.
   * Jika tidak ada peserta pada kategori tersebut (misal tidak ada yang izin atau absen), isi dengan tanda strip berindentasi 5 spasi: `     -`.
4. **Penutup:** Selalu diakhiri dengan baris kosong diikuti teks persis `~Pesan otomatis oleh bot.`.

## 6. Alur Eksekusi End-to-End Saat Submit Form Laporan

1. **Validasi & Optimasi:** Backend PHP memvalidasi input form dan memastikan foto dokumentasi terkompresi (JPEG, max width `1280px`).
2. **Generate ID Laporan:** Membuat ID unik (contoh: `rep-20261119-xxxx`).
3. **Upload ke Blomp S3:**
   * Unggah foto terkompresi ke `mai/mentoring/dokumentasi/<id_laporan>.jpg` (privat; disajikan `media.php`).
   * Unggah file JSON metadata ringan ke `mai/mentoring/og_meta/<id_laporan>.json` (privat; dibaca Worker lewat `media.php`).
   * Minifikasi, kompres (`gzencode`), dan enkripsi (`aes-256-gcm`) data laporan lengkap, lalu unggah ke `mai/mentoring/laporan_json/<id_laporan>.enc`.
4. **Update Local Pointer & Status Mentee:**
   * Simpan path objek S3 serta kunci dekripsi (`key`, `iv`, `tag`) ke `storage_secure/pointer_laporan.json`.
   * Evaluasi riwayat absensi mentee pada tim terkait untuk memperbarui `status_keaktifan` (`Aktif` / `Peringatan` / `Tidak Aktif`).
5. **Kirim Laporan ke WhatsApp:** Susun teks pesan sesuai format pada Bagian 5B dan kirimkan melalui API Watobot (`curl` POST).
6. **Redirect ke Halaman Laporan:** Arahkan pengguna ke `view.php?id=<id_laporan>` sebagai konfirmasi bahwa laporan berhasil tersimpan dan terkirim.