#!/usr/bin/env bash
#
# localhost.sh — Jalankan aplikasi untuk uji lokal memakai PHP built-in server.
#
# Pemakaian:
#   ./localhost.sh              # default http://127.0.0.1:8000/
#   PORT=8080 ./localhost.sh    # ganti port
#   HOST=0.0.0.0 ./localhost.sh # akses dari perangkat lain di LAN
#   bash localhost.sh           # jika belum chmod +x
#
# Catatan: server bawaan PHP tidak memproses .htaccess; rewrite ditangani
# oleh router.php (blog pretty URL, /admin/, sitemap.xml).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP tidak ditemukan di PATH. Pasang PHP 8+ dulu." >&2
  exit 1
fi

if [ ! -f "$DIR/data.json" ]; then
  echo "Peringatan: data.json tidak ada. Beberapa fitur butuh data (buat dari default.json bila perlu)." >&2
fi

echo "== Uji lokal UKMI Polmed =="
echo "  Home   : http://$HOST:$PORT/"
echo "  Blog   : http://$HOST:$PORT/blog.php  (pretty: /blog/<slug>, /blog/arsip)"
echo "  Admin  : http://$HOST:$PORT/admin.php  (pretty: /admin/)"
echo "  Sitemap: http://$HOST:$PORT/sitemap.php  (/sitemap.xml redirect)"
echo "  Stop   : Ctrl+C"
echo

exec php -S "$HOST:$PORT" -t "$DIR" "$DIR/router.php"
