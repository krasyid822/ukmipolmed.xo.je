#!/usr/bin/env bash
# ==============================================================================
# deploy.sh — Atomic Deploy Script untuk Kelola Kelompok Mentoring
# ==============================================================================
# Target kanonik (disamakan dengan app.base_url dan Cloudflare Worker):
#   https://ukmipolmed.my.id/terpadu/mai/mentoring/
#   ftp://ftpupload.net/ukmipolmed.xo.je/htdocs/terpadu/mai/mentoring/
#
# storage_secure/ ikut dikirim HANYA untuk berkas konfigurasi (.json dan
# .htaccess). State runtime (pointer, antrean bot, token biometrik, lock)
# dikecualikan agar deploy berikutnya tidak menimpa data produksi.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

FTP_HOST="ftpupload.net"
FTP_USER="${FTP_USER:-}"
FTP_PASS="${FTP_PASS:-}"

NETRC_FILE=""
if [ -f "$SCRIPT_DIR/.netrc" ]; then
    NETRC_FILE="$SCRIPT_DIR/.netrc"
elif [ -f "$HOME/.netrc" ]; then
    NETRC_FILE="$HOME/.netrc"
fi

if [ -n "$NETRC_FILE" ]; then
    if [ -z "$FTP_USER" ]; then
        FTP_USER=$(awk -v host="$FTP_HOST" '$2 == host { print $4 }' "$NETRC_FILE" || true)
    fi
    if [ -z "$FTP_PASS" ]; then
        FTP_PASS=$(awk -v host="$FTP_HOST" '$2 == host { print $6 }' "$NETRC_FILE" || true)
    fi
fi

FTP_USER="${FTP_USER:-epiz_32661559}"
if [ "$FTP_PASS" = "ISI_PASSWORD_FTP_ANDA_DI_SINI" ]; then
    FTP_PASS=""
fi

REMOTE_DOCROOT="/ukmipolmed.xo.je/htdocs/terpadu/mai/mentoring"

echo "=== Memulai Deploy Kelola Kelompok Mentoring ==="
echo "Target: ftp://${FTP_HOST}${REMOTE_DOCROOT}/"

# 1. Daftar berkas yang boleh dideploy (whitelist + exclude state runtime)
FILES_TO_DEPLOY=()
while IFS= read -r file; do
    FILES_TO_DEPLOY+=("$file")
done < <(find . -type f \
    ! -path "./.git/*" \
    ! -path "./.deploy*" \
    ! -path "./storage_secure/*.lock" \
    ! -path "./storage_secure/keystore/*" \
    ! -name "*.keystore" \
    ! -name "*.jks" \
    ! -path "./storage_secure/offline_secret.key" \
    ! -path "./storage_secure/pointer_laporan.json" \
    ! -path "./storage_secure/pointer_master.json" \
    ! -path "./storage_secure/bot_queue.json" \
    ! -path "./storage_secure/bot_state.json" \
    ! -path "./storage_secure/biometric_setup.json" \
    ! -name "deploy.sh" \
    ! -name "*.md" \
    ! -name "PROGRESS.txt" \
    ! -name ".my-idea.txt" \
    ! -name "clear-cache.php" \
    ! -name "run-local.sh" \
    ! -name "router.php" \
    ! -name ".netrc" \
    ! -name ".gitignore" \
    ! -name "cloudflare_worker_redirector.js" \
    ! -name "manifest-*.webmanifest" \
    ! -path "./public/assets/pwa/*" \
    | sed 's|^\./||')

echo "Total berkas yang akan diverifikasi: ${#FILES_TO_DEPLOY[@]}"

if [ "${1:-}" == "--dry-run" ]; then
    echo "Mode Dry Run: Berkas yang akan diunggah:"
    for f in "${FILES_TO_DEPLOY[@]}"; do
        echo "  - $f"
    done
    exit 0
fi

if [ -z "$FTP_PASS" ]; then
    echo "PERINGATAN: Kredensial FTP_PASS belum diset."
    echo "Isi password pada file .netrc di folder proyek, atau jalankan: FTP_PASS='password_anda' ./deploy.sh"
    echo "Gunakan './deploy.sh --dry-run' untuk melihat daftar berkas yang disiapkan."
    exit 1
fi

# 2. Lakukan upload atomik menggunakan lftp
lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" <<EOF
set ftp:ssl-allow yes
set ftp:ssl-protect-data no
set ssl:verify-certificate yes
set net:timeout 30
set net:max-retries 5

mkdir -p $REMOTE_DOCROOT
cd $REMOTE_DOCROOT

mirror -R --parallel=1 \
    --exclude-glob ".git/*" \
    --exclude-glob ".deploy*" \
    --exclude-glob "storage_secure/*.lock" \
    --exclude-glob "storage_secure/keystore/*" \
    --exclude-glob "*.keystore" \
    --exclude-glob "*.jks" \
    --exclude-glob "storage_secure/offline_secret.key" \
    --exclude-glob "storage_secure/pointer_laporan.json" \
    --exclude-glob "storage_secure/pointer_master.json" \
    --exclude-glob "storage_secure/bot_queue.json" \
    --exclude-glob "storage_secure/bot_state.json" \
    --exclude-glob "storage_secure/biometric_setup.json" \
    --exclude-glob "deploy.sh" \
    --exclude-glob "*.md" \
    --exclude-glob "PROGRESS.txt" \
    --exclude-glob ".my-idea.txt" \
    --exclude-glob "clear-cache.php" \
    --exclude-glob "run-local.sh" \
    --exclude-glob "router.php" \
    --exclude-glob ".netrc" \
    --exclude-glob ".gitignore" \
    --exclude-glob "cloudflare_worker_redirector.js" \
    --exclude-glob "manifest-*.webmanifest" \
    --exclude-glob "public/assets/pwa/*" \
    --exclude-glob "public/assets/pwa" \
    --exclude-glob "public/assets/libs/*" \
    --exclude-glob "public/assets/libs" \
    ./ $REMOTE_DOCROOT/

bye
EOF

echo "=== Deploy Berhasil Selesai! ==="
