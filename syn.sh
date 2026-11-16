#!/usr/bin/env bash
#
# syn.sh — Sinkronisasi DUA ARAH FTP (ukmipolmed.xo.je) <-> GitHub.
#
# Prinsip: FTP = sumber kebenaran data runtime. git-ftp hanya bisa push,
# sehingga arah "pull" (FTP -> lokal) dikerjakan via lftp mirror.
#
# Arah:
#   push (default) : lokal -> FTP (deploy)  +  lokal -> GitHub
#   pull           : FTP -> lokal (tarik)   +  lokal -> GitHub (commit & push)
#
# Penggunaan:
#   ./syn.sh                # push: deploy ke FTP + commit/push GitHub
#   ./syn.sh --pull         # pull: tarik dari FTP, lalu commit/push GitHub
#   ./syn.sh --push         # push eksplisit (default)
#   ./syn.sh --git-only     # hanya git (add/commit/push)
#   ./syn.sh --ftp-only     # hanya ftp
#   ./syn.sh --dry-run      # pratinjau (tidak mengubah apa pun)
#   ./syn.sh --delete       # mirror penuh: hapus file FTP yang tak ada di lokal (push)
#   ./syn.sh -h|--help      # bantuan
#
set -euo pipefail

# ── Konfigurasi ──
FTP_HOST="ftpupload.net"
FTP_ROOT="/ukmipolmed.xo.je/htdocs"     # ROOT FTP produksi (prinsip tetap)
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Opsi ──
MODE="push"
DO_GIT=1
DO_FTP=1
DRY_RUN=0
DELETE=0

usage() { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; }

for arg in "$@"; do
  case "$arg" in
    --pull)     MODE="pull" ;;
    --push)     MODE="push" ;;
    --git-only) DO_FTP=0 ;;
    --ftp-only) DO_GIT=0 ;;
    --dry-run)  DRY_RUN=1 ;;
    --delete)   DELETE=1 ;;
    -h|--help)  usage; exit 0 ;;
    *) echo "Opsi tidak dikenal: $arg" >&2; usage; exit 1 ;;
  esac
done

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()  { printf '\033[1;32m ✓\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m ✗ %s\033[0m\n' "$*" >&2; exit 1; }

cd "$REPO_DIR"

# File/folder repo & dev yang TIDAK ada di server FTP (jangan disentuh)
EXCLUDES=(
  ".git/" ".backups/" ".kilo/" ".gitignore" "syn.sh"
  ".my-idea.txt" "AGENTS.md" "TEMUAN.TXT" "LICENSE"
  "tools/" "assets/"
)

build_mirror_opts() {
  local reverse="$1"
  printf '%s' "$reverse"
  if [ "$DELETE" = 1 ]; then printf ' --delete'; fi
  printf ' --no-perms --only-newer'
  local ex
  for ex in "${EXCLUDES[@]}"; do
    printf ' --exclude-glob "%s"' "$ex"
  done
}

# ── Git ──
do_git() {
  log "Git: add / commit / push GitHub"
  if [ "$DRY_RUN" = 1 ]; then
    echo "  [dry-run] git add -A && git commit && git push origin main"
    return
  fi
  git add -A
  if git diff --cached --quiet; then
    ok "Tidak ada perubahan untuk di-commit"
  else
    git commit -m "Sync $(date '+%Y-%m-%d %H:%M')" >/dev/null
    ok "Commit dibuat"
  fi
  if git push origin main; then ok "Push GitHub berhasil"; else die "Push GitHub gagal"; fi
}

# ── FTP push (lokal -> FTP) ──
do_ftp_push() {
  log "FTP push: lokal -> $FTP_HOST:$FTP_ROOT"
  local opts script
  opts="$(build_mirror_opts '-R')"
  script="$(mktemp)"
  {
    echo "set net:timeout 300"
    echo "set xfer:timeout 300"
    echo "set xfer:clobber yes"
    echo "open $FTP_HOST"
    echo "mirror $opts \"$REPO_DIR\" \"$FTP_ROOT\""
    echo "bye"
  } > "$script"
  if [ "$DRY_RUN" = 1 ]; then
    printf '\033[1;33m ⚠\033[0m [dry-run] skrip lftp (push):\n'
    sed 's/^/    /' "$script"
    rm -f "$script"; return
  fi
  if lftp -f "$script"; then rm -f "$script"; ok "FTP push selesai"
  else rm -f "$script"; die "FTP push gagal"; fi
}

# ── FTP pull (FTP -> lokal) ──
do_ftp_pull() {
  log "FTP pull: $FTP_HOST:$FTP_ROOT -> lokal"
  local opts script
  opts="$(build_mirror_opts '')"
  script="$(mktemp)"
  {
    echo "set net:timeout 300"
    echo "set xfer:timeout 300"
    echo "set xfer:clobber yes"
    echo "open $FTP_HOST"
    echo "mirror $opts \"$FTP_ROOT\" \"$REPO_DIR\""
    echo "bye"
  } > "$script"
  if [ "$DRY_RUN" = 1 ]; then
    printf '\033[1;33m ⚠\033[0m [dry-run] skrip lftp (pull):\n'
    sed 's/^/    /' "$script"
    rm -f "$script"; return
  fi
  if lftp -f "$script"; then rm -f "$script"; ok "FTP pull selesai"
  else rm -f "$script"; die "FTP pull gagal"; fi
}

# ── Eksekusi ──
case "$MODE" in
  push)
    [ "$DO_FTP" = 1 ] && do_ftp_push
    [ "$DO_GIT" = 1 ] && do_git
    ;;
  pull)
    [ "$DO_FTP" = 1 ] && do_ftp_pull
    [ "$DO_GIT" = 1 ] && do_git
    ;;
esac

log "Selesai."