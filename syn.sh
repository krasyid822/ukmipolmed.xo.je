#!/usr/bin/env bash
#
# syn.sh — Sinkronisasi DUA ARAH FTP (ukmipolmed.xo.je) <-> GitHub.
#
# Prinsip: FTP = sumber kebenaran data runtime.
#
# Push (default) memakai "upload temp + rename atomik" — tahan throttling FTP,
# TANPA curl/gz:
#   1) deteksi file BERUBAH (bandingkan ukuran lokal vs remote, satu sesi lftp)
#   2) upload tiap file berubah ke  <file>.synup  (file live TIDAK disentuh)
#   3) verifikasi ukuran .synup == lokal
#   4) jika cocok -> rename (mv) <file>.synup -> <file> (atomik); jika tidak -> hapus .synup
# Jadi file live tidak pernah jadi 0-byte saat transfer gagal.
#
# Penggunaan:
#   ./syn.sh                # push: deploy ke FTP + commit/push GitHub
#   ./syn.sh --pull         # pull: tarik dari FTP, lalu commit/push GitHub
#   ./syn.sh --push         # push eksplisit (default)
#   ./syn.sh --git-only     # hanya git (add/commit/push)
#   ./syn.sh --ftp-only     # hanya ftp
#   ./syn.sh --dry-run      # pratinjau (tidak mengubah apa pun)
#   ./syn.sh -h|--help      # bantuan
#
set -euo pipefail

# ── Konfigurasi ──
FTP_HOST="ftpupload.net"
FTP_ROOT="/ukmipolmed.xo.je/htdocs"     # ROOT FTP produksi (prinsip tetap)
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MODE="push"; DO_GIT=1; DO_FTP=1; DRY_RUN=0

usage() { sed -n '2,23p' "$0" | sed 's/^# \{0,1\}//'; }

for arg in "$@"; do
  case "$arg" in
    --pull) MODE="pull" ;;
    --push) MODE="push" ;;
    --git-only) DO_FTP=0 ;;
    --ftp-only) DO_GIT=0 ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Opsi tidak dikenal: $arg" >&2; usage; exit 1 ;;
  esac
done

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m ⚠\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m ✗ %s\033[0m\n' "$*" >&2; exit 1; }

cd "$REPO_DIR"

RUNTIME_SKIP=( "data.json" "default.json" "insights.json" "authors.json" )
DEV_SKIP=( ".gitignore" "syn.sh" "router.php" "localhost.sh" ".my-idea.txt" "AGENTS.md" "TEMUAN.TXT" "LICENSE" )

in_list() { local n="$1"; shift; local x; for x in "$@"; do [ "$x" = "$n" ] && return 0; done; return 1; }

# ── Git ──
do_git() {
  log "Git: add / commit / push GitHub"
  if [ "$DRY_RUN" = 1 ]; then echo "  [dry-run] git add -A && git commit && git push origin main"; return; fi
  git add -A
  if git diff --cached --quiet; then ok "Tidak ada perubahan untuk di-commit"
  else git commit -m "Sync $(date '+%Y-%m-%d %H:%M')" >/dev/null; ok "Commit dibuat"; fi
  if git push origin main; then ok "Push GitHub berhasil"; else die "Push GitHub gagal"; fi
}

# Peta ukuran remote untuk sejumlah path (satu sesi lftp)
remote_sizes() {
  local paths=("$@")
  local out
  out="$(
    { echo "set net:timeout 60"; echo "set ftp:passive-mode on"; echo "open $FTP_HOST";
      local p; for p in "${paths[@]}"; do printf 'ls -la "%s"\n' "$p"; done;
      echo "bye"; } | timeout 120 lftp 2>/dev/null || true
  )"
  printf '%s\n' "$out" | awk 'NF>=9 && $1 ~ /^-/ {print $9, $5}'
}

# ── FTP push (upload temp + rename atomik) ──
do_ftp_push() {
  log "FTP push (temp+rename atomik): lokal -> $FTP_HOST:$FTP_ROOT"

  local files=() abs rel
  while IFS= read -r abs; do
    rel="${abs#$REPO_DIR/}"
    case "$rel" in *.gz|*.part|*.tmp|*.synup) continue ;; esac
    in_list "$rel" "${RUNTIME_SKIP[@]}" && continue
    in_list "$rel" "${DEV_SKIP[@]}" && continue
    files+=("$rel")
  done < <(find "$REPO_DIR" -mindepth 1 \
            \( -name .git -o -name .backups -o -name .kilo -o -name .uploads -o -name tools -o -name assets \) -prune -o \
            -type f -print)

  [ "${#files[@]}" -eq 0 ] && { ok "Tidak ada file untuk di-deploy"; return; }

  # Ukuran remote (untuk deteksi perubahan)
  local rempaths=()
  for rel in "${files[@]}"; do rempaths+=("$FTP_ROOT/$rel"); done
  declare -A RSIZE=()
  local p s
  while read -r p s; do [ -n "$p" ] && [ -n "$s" ] && RSIZE["$p"]="$s"; done < <(remote_sizes "${rempaths[@]}")

  local changed=() l r
  for rel in "${files[@]}"; do
    l="$(stat -c%s "$REPO_DIR/$rel" 2>/dev/null || echo -1)"
    r="${RSIZE["$FTP_ROOT/$rel"]:-}"
    if [ -z "$r" ] || [ "$r" != "$l" ]; then changed+=("$rel"); fi
  done

  if [ "${#changed[@]}" -eq 0 ]; then ok "Semua file sudah sinkron (ukuran sama)"; return; fi

  if [ "$DRY_RUN" = 1 ]; then
    warn "dry-run: ${#changed[@]} file berubah -> upload .synup -> verifikasi -> rename atomik"
    printf '  berubah:\n'; printf '    %s\n' "${changed[@]}"
    return
  fi

  local UP=".synup"

  # Fase 1: upload ke <file>.synup (live tidak disentuh)
  local s1; s1="$(mktemp)"
  {
    echo "set net:timeout 300"; echo "set xfer:timeout 300"
    echo "set ftp:passive-mode on"; echo "set net:max-retries 6"; echo "set net:reconnect-interval-base 3"
    echo "set xfer:clobber yes"; echo "open $FTP_HOST"
    for rel in "${changed[@]}"; do printf 'put "%s" -o "%s"\n' "$REPO_DIR/$rel" "$FTP_ROOT/$rel$UP"; done
    echo "bye"
  } > "$s1"
  log "Upload ${#changed[@]} file ke .synup ..."
  if ! timeout 400 lftp -f "$s1"; then rm -f "$s1"; die "Upload ke .synup gagal"; fi
  rm -f "$s1"

  # Fase 2: ukuran .synup
  local upaths=()
  for rel in "${changed[@]}"; do upaths+=("$FTP_ROOT/$rel$UP"); done
  declare -A TSIZE=()
  while read -r p s; do [ -n "$p" ] && [ -n "$s" ] && TSIZE["$p"]="$s"; done < <(remote_sizes "${upaths[@]}")

  # Fase 3: rename bila ukuran cocok, else hapus temp
  local s3; s3="$(mktemp)"; local okc=0 failc=0 lsz tsz
  {
    echo "set net:timeout 60"; echo "set ftp:passive-mode on"; echo "set net:max-retries 4"; echo "open $FTP_HOST"
  } > "$s3"
  for rel in "${changed[@]}"; do
    lsz="$(stat -c%s "$REPO_DIR/$rel" 2>/dev/null || echo -1)"
    tsz="${TSIZE["$FTP_ROOT/$rel$UP"]:-}"
    if [ -n "$tsz" ] && [ "$tsz" = "$lsz" ]; then
      printf 'mv "%s" "%s"\n' "$FTP_ROOT/$rel$UP" "$FTP_ROOT/$rel" >> "$s3"
      okc=$((okc+1))
    else
      printf 'rm -f "%s"\n' "$FTP_ROOT/$rel$UP" >> "$s3"
      warn "upload tak lengkap: $rel (temp ${tsz:-none} vs lokal $lsz) — dilewati"
      failc=$((failc+1))
    fi
  done
  echo "bye" >> "$s3"
  if ! timeout 180 lftp -f "$s3"; then rm -f "$s3"; die "Rename atomik gagal"; fi
  rm -f "$s3"

  if [ "$failc" -eq 0 ]; then ok "Deploy OK ($okc file di-rename atomik ke target)"
  else die "Deploy: $okc OK, $failc gagal (file live aman). Ulangi ./syn.sh untuk yang gagal."; fi
}

# ── FTP pull (FTP -> lokal) ──
do_ftp_pull() {
  log "FTP pull: $FTP_HOST:$FTP_ROOT -> lokal"
  local script; script="$(mktemp)"
  {
    echo "set net:timeout 300"; echo "set xfer:timeout 300"
    echo "set ftp:passive-mode on"; echo "set net:max-retries 5"; echo "set net:reconnect-interval-base 3"
    echo "set xfer:clobber yes"; echo "open $FTP_HOST"
    echo "mirror --no-perms --only-newer --exclude-glob \".uploads/\" --exclude-glob \".git/\" --exclude-glob \".backups/\" --exclude-glob \".kilo/\" \"$FTP_ROOT\" \"$REPO_DIR\""
    echo "bye"
  } > "$script"
  if [ "$DRY_RUN" = 1 ]; then warn "[dry-run] skrip lftp (pull):"; sed 's/^/    /' "$script"; rm -f "$script"; return; fi
  if timeout 300 lftp -f "$script"; then rm -f "$script"; ok "FTP pull selesai"; else rm -f "$script"; die "FTP pull gagal"; fi
}

case "$MODE" in
  push) [ "$DO_FTP" = 1 ] && do_ftp_push; [ "$DO_GIT" = 1 ] && do_git ;;
  pull) [ "$DO_FTP" = 1 ] && do_ftp_pull; [ "$DO_GIT" = 1 ] && do_git ;;
esac

log "Selesai."
