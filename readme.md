![](assets/demo/ukmi-polmed.gif)

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
- Blog: `http://127.0.0.1:8000/blog.php` — pretty URL `/blog/<slug>`, arsip `/blog/arsip`
- Admin: `http://127.0.0.1:8000/admin.php` (atau pretty `/admin/`)
- Sitemap: `http://127.0.0.1:8000/sitemap.xml` (diarahkan ke `sitemap.php`)

Catatan:
- Server bawaan PHP **tidak** memproses `.htaccess`; rewrite ditangani `router.php`.
- Hentikan server dengan `Ctrl+C`.
- `data.json` harus ada di folder ini agar konten tampil (bila belum, salin dari `default.json`).

## Cara Sinkronisasi FTP + Git

```bash
./syn.sh            # push: lokal -> FTP + commit/push GitHub
./syn.sh --pull     # tarik FTP -> lokal, lalu commit/push GitHub
./syn.sh --dry-run  # pratinjau tanpa mengubah apa pun
```