![](https://ukmipolmed.my.id/foto?key=ukmipolmed%2Fassets%2Fdemo%2Fukmi-polmed.webp)

## Cara Uji Lokal

Jalankan aplikasi di komputer lokal memakai PHP built-in server:

```bash
cd /home/rasyidk/vscode/ukmipolmed.xo.je
bash localhost.sh              # default http://127.0.0.1:8000/
PORT=8080 bash localhost.sh    # ganti port
HOST=0.0.0.0 bash localhost.sh # akses dari perangkat lain di LAN
# chmod +x localhost.sh && ./localhost.sh  (opsional)
```

Buka:
- Home: `http://127.0.0.1:8000/`
- Blog: `http://127.0.0.1:8000/blog` — pretty URL `/blog/<slug>`, arsip `/blog/arsip`
- Admin: `http://127.0.0.1:8000/admin` (login `/login`)
- Sitemap: `http://127.0.0.1:8000/sitemap.xml`

Catatan:
- Server bawaan PHP **tidak** memproses `.htaccess`; rewrite ditangani `router.php`.
- Hentikan server dengan `Ctrl+C`.
- `data.json` harus ada di folder ini agar konten tampil (bila belum, salin dari `default.json`).

## Penyimpanan Data Runtime (S3)

- `insights.json`, `authors.json`, dan `assessments.json` di folder proyek hanya
  **pointer** kecil (`{"_store":"s3","_key":"...","size":...}`). Payload-nya di
  S3 pada prefix `ukmipolmed/data/`.
- `data.json` sudah dipecah ke folder **`ukmipolmed/data_json/`**:
  ```
  manifest.json
  meta.json          # user, key, session_version, fb_app_id, yel_yel
  site.json          # agenda, registration, docs, divisions, hero, shortcut
  faqs.json
  logs.json
  extra.json         # key top-level baru
  posts/index.json   # metadata ringan untuk listing
  posts/<sha1>.json  # isi lengkap satu artikel (tanpa komentar)
  comments/<sha1>.json
  ```
  `data.json` lokal menjadi pointer kecil dengan `"_sharded": true` dan
  `_key` ke `manifest.json`.
- Halaman publik hanya membaca pecahannya:
  - beranda: `meta.json` + `site.json`
  - `/blog`: `posts/index.json`; halaman detail: satu `posts/<sha1>.json` +
    `comments/<sha1>.json`
  - `/faq`: `faqs.json`
  - `/sitemap.xml` dan `og-image`: `posts/index.json`
  - admin/status yang butuh data lengkap tetap memakai `store_read_json()`
    (menggabungkan pecahan; hasilnya dicache per pecahan).
- Baca/tulis memakai `store.php` (`store_read_json`, `store_save_json`) dan
  `data_store.php` untuk pecahan. Semua cache runtime hosting dipusatkan di
  `seo-cache/` (TTL 6 jam, selalu diperbarui saat menulis).
- Isi `seo-cache/`: cache tiap pecahan `data_json_*.cache.json`, cache
  `insights`/`authors`/`assessments`, `s3-usage.json`, `s3-uploads.json`,
  `hosting-usage.json`, dan satu `store.lock`.
- Folder `seo-cache/` diblokir dari web (`.htaccess` -> 404), di-`Disallow` di
  `robots.txt`, dan tidak disinkronkan ke FTP/Git.
- Cache dibatasi (`default.json` -> `seo_cache`): total **8 MiB**, per file
  **2 MiB**, maksimum **512 file**, umur **90 hari**, `.tmp` dibersihkan
  setelah **15 menit**. Prioritas eviction:
  `usage -> comments -> posts -> logs/faqs/authors -> index/site/meta/manifest`
  lalu `insights -> data`. Cache inti (`index`, `site`, `meta`, `manifest`)
  dipertahankan paling lama.
- Payload per pecahan yang > 2 MiB disimpan sebagai `*.cache.json.gz` dan
  dibaca transparan. Kalau hasil kompresi masih > 2 MiB, cache pecahan itu
  dilewati; data tetap di S3. Karena data sudah dipecah, kondisi ini hanya
  terjadi pada satu artikel/komentar yang memang ekstrem.
- Batas total komentar mengikuti sisa kapasitas S3 (tanpa plafon statis);
  satu komentar dibatasi 120 byte nama dan 20.000 byte isi.
- `insights` dibatasi retensi **180 hari**, **2.000** nama event, dan **500**
  event per hari.
- Migrasi/rollback pecahan:
  ```
  php migrate_data_shards.php --migrate
  php migrate_data_shards.php --status
  php migrate_data_shards.php --rollback
  ```
  Versi web: `migrate_data_shards?k=<maintenance_key>&action=migrate`.
  Payload lama `data/data.json` tidak dihapus, jadi rollback aman.
- Migrasi file lokal penuh ke S3 (legacy): `migrate_store?k=<maintenance_key>`.
- Bersih-bersih inode: `cleanup_inodes?k=<maintenance_key>&run=1`
  (default dry-run; menghapus `.backups/`, `.store/`, cache root lama, `.tmp`,
  `.synup`, `.part`).
- Endpoint migrasi/cleanup memakai `maintenance_guard.php`: CLI selalu
  diizinkan; web hanya dengan sesi admin (`role=admin`) atau key dari
  `s3_config.php` -> `maintenance_key` (fallback env `UKMI_MAINTENANCE_KEY`).
  Tidak ada key default di repo. Contoh isi server-only `s3_config.php`:
  `'maintenance_key' => 'ganti-dengan-string-acak-panjang',`
- Pembersihan objek S3 yatim (gambar/files asesmen yang sudah tidak dirujuk):
  ```
  php s3_gc.php [--run] [--days=7]          # CLI (selalu diizinkan)
  POST /s3_gc?run=1&days=7                  # web: wajib sesi admin + POST
  GET  /s3_gc                               # web: dry-run, wajib sesi admin
  ```
  Hanya prefix `uploads/` dan `assessments/` yang diperiksa. Objek dianggap
  yatim bila tidak dirujuk `data.json`, `authors.json`, `assessments.json`,
  `default.json`, maupun file statis repo, dan `LastModified`-nya lebih tua
  dari masa tenggang. GC berhenti tanpa menghapus apa pun bila salah satu
  sumber data runtime gagal dibaca. Prefix `assets/`, `data/`, `data_json/`,
  dan `assessments_json/` tidak pernah disentuh. Ganti/hapus gambar atau file
  asesmen juga langsung memangkas objek lama setelah penyimpanan sukses,
  dengan cek referensi bersama (key `uploads/` berbasis hash konten).
  Jalankan berkala lewat cron eksternal atau CLI di server.
- Proxy publik `/foto` hanya melayani objek `uploads/` dan `assets/` dengan
  ekstensi gambar; payload `data/`, `data_json/`, dan `assessments_json/`
  tidak dapat diunduh lewat proxy. Data URI lama pada posting tetap dirender
  apa adanya sampai dimigrasikan sekali dengan `migrate_s3`.

## Pecahan Assessments

- `assessments.json` dipecah ke **`ukmipolmed/assessments_json/`**:
  ```
  manifest.json
  index.json                  # definisi asesmen tanpa responses + response_count
  responses/<sha1(id)>.json   # daftar respons satu asesmen
  ```
- `asesmen.php` hanya membaca satu definisi + satu file respons.
- Menambah/mengubah respons menulis satu file respons, bukan seluruh daftar asesmen.
- `assessments_for_post()` dan `assessments_by_author()` memakai `index.json`;
  `assessment_find()` memakai definisi + respons asesmen tersebut.
- Migrasi/rollback:
  ```
  php migrate_assessments_shards.php --migrate
  php migrate_assessments_shards.php --status
  php migrate_assessments_shards.php --rollback
  ```
  Payload lama `data/assessments.json` tetap ada untuk rollback.
- `insights.json` dan `authors.json` sengaja **tidak** dipecah: `insights` sudah
  dibatasi retensi 180 hari/2.000 event/500 event per hari, sedangkan `authors`
  tumbuh linear dan sangat kecil.

## Pemakaian Storage Hosting (InfinityFree)

InfinityFree tidak menyediakan API kuota, jadi pemakaian dihitung dengan scan
filesystem docroot (`hosting_storage_helper.php`). Angka tersedia publik di
`/status?view=json` pada `technical.hosting_storage`:

| Field | Arti |
| :--- | :--- |
| `scope` | Cakupan: seluruh akun hosting (di luar database MySQL & email) |
| `bytes_used` | Blok disk terpakai (dasar persentase, seperti panel hosting) |
| `bytes_apparent` | Jumlah ukuran file sebenarnya |
| `limit_bytes`, `percent_used`, `remaining_bytes` | Kuota dan sisa |
| `inodes_used`, `inode_limit`, `inode_percent_used` | File + folder vs batas inode |
| `cache` | Rincian `seo-cache/` (ukuran, jumlah file, dan daftar file cache) |
| `breakdown` | Pemakaian per folder/file tingkat teratas |
| `updated_at`, `age_seconds`, `stale`, `scan_ms`, `truncated`, `errors` | Metadata scan |

- Hasil scan di-cache di `seo-cache/hosting-usage.json` (TTL 6 jam). File ini
  tidak disinkronkan ke FTP/Git dan folder `seo-cache/` diblokir dari akses web.
  Cache lama otomatis diabaikan bila versi/cakupan scan berubah.
- Scan dimulai dari root akun (folder induk `htdocs`) agar mendekati angka panel
  InfinityFree. Database MySQL dan email tidak berada di filesystem akun sehingga
  tetap bisa membuat selisih; `bytes_apparent` dan `scope` menjelaskan cakupannya.
  Bandwidth dan hits tidak dihitung (tidak ada API dan tidak dapat dibaca dari filesystem).
- Semua batas hanya dibaca dari `default.json`; tidak ada fallback hardcoded. Bila
  blok `hosting` tidak ada, `limit_bytes`/`inode_limit` bernilai `0` dan persentase
  `null` (ditampilkan `n/a`).
- Refresh paksa hanya untuk maintenance:
  `/status?view=json&storage_refresh=1&k=<maintenance_key>`
- Batas kuota (terverifikasi dari sidebar InfinityFree: 5 GB / 80.000 inode) ada di
  `default.json` server:
  ```json
  "hosting": {
    "max_bytes": 5000000000,
    "max_inodes": 80000,
    "scan_root": ""
  }
  ```
  `scan_root` opsional (relatif docroot). Kosong = otomatis: folder induk bila docroot
  bernama `htdocs` (root akun FTP), selain itu docroot itu sendiri. `default.json` ikut
  di-push `syn.sh` sebagai konfigurasi default, tetapi tetap tidak masuk Git
  (`.gitignore`); `data.json` dan data runtime lain tidak ikut disinkronkan.


## Cara Sinkronisasi FTP + Git

```bash
./syn.sh            # push: lokal -> FTP + commit/push GitHub
./syn.sh --pull     # tarik FTP -> lokal, lalu commit/push GitHub
./syn.sh --dry-run  # pratinjau tanpa mengubah apa pun
```

## Informasi Header / Field API S3 (Blomp / Swift)

| Header / Field | Arti |
| :--- | :--- |
| `X-Account-Bytes-Used` | Total byte terpakai di akun |
| `X-Account-Object-Count` | Jumlah objek |
| `X-Account-Container-Count` | Jumlah container |
| `X-Container-Bytes-Used` | Pemakaian per container |
| `X-Container-Object-Count` | Objek per container |
| `bytes_used`, `count` (JSON) | Versi mudah-parse dari angka di atas |

### Catatan Penggunaan

- **Header `X-Account-*`** dikembalikan saat melakukan `HEAD` atau `GET` ke **root akun** (endpoint autentikasi Swift). Ini yang paling mendekati "total pemakaian akun".
- **Header `X-Container-*`** dikembalikan saat melakukan `HEAD` atau `GET` ke **container tertentu**, berguna untuk melihat pemakaian per folder/container.
- **Field JSON `bytes_used` dan `count`** biasanya muncul jika respons diminta dalam format JSON (misalnya `?format=json`), sehingga lebih mudah diparse oleh skrip dibandingkan membaca header mentah.
- Perlu diingat: header ini melaporkan **pemakaian (used)**, bukan **sisa kapasitas (available)**. Untuk menghitung sisa, Anda tetap memerlukan angka total kuota akun yang tidak selalu diekspos melalui header ini.

## Struktur folder S3

```text
ukmipolmed/
├── assessments/
├── assessments_json/
│   └── responses/
├── assets/
│   └── demo/
├── data/
├── data_json/
│   ├── comments/
│   └── posts/
└── uploads/
```

## Link Dasbor Cloudflare Worker

https://dash.cloudflare.com/3de0b671809fb85f3f3715028d168fb4/workers/services/view/laporan-mai/production