#!/usr/bin/env bash
#
# syn.sh — Sinkronisasi DUA ARAH FTP (ukmipolmed.xo.je) <-> GitHub.
#
# Deploy push memakai MANIFEST SHA1 lokal (.syn_manifest):
#   - Bandingkan sha1 tiap file lokal vs manifest (hasil deploy terakhir).
#   - Hanya file yang BERUBAH yang di-upload (tanpa `ls` remote yang lambat/flaky).
#   - Upload ke <file>.synup, verifikasi ukuran, lalu rename atomik ke target.
#   - File besar yang gagal: fallback gzip+upload -> gz_restore (auto verifikasi/hapus).
#
# Penggunaan:
#   ./syn.sh                  # push: deploy(file berubah) + commit/push GitHub
#   ./syn.sh --fetch          # sinkronkan file APP (kode) dari FTP -> lokal (tanpa data runtime/.uploads)
#   ./syn.sh --init-manifest  # tandai semua file lokal sebagai "sudah ter-deploy"
#   ./syn.sh --push           # push eksplisit (default)
#   ./syn.sh --git-only       # hanya git (add/commit/push)
#   ./syn.sh --ftp-only       # hanya ftp
#   ./syn.sh --dry-run        # pratinjau (tidak mengubah apa pun)
#   ./syn.sh -h|--help        # bantuan
#
set -euo pipefail

FTP_HOST="ftpupload.net"
FTP_ROOT="/ukmipolmed.xo.je/htdocs"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST="$REPO_DIR/.syn_manifest"
RESTORE_URL="https://ukmipolmed.my.id/gz_restore"
RESTORE_KEY="UKMIrestore2026"

MODE="push"; DO_GIT=1; DO_FTP=1; DRY_RUN=0; INIT_MANIFEST=0
usage() { sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; }
for arg in "$@"; do
  case "$arg" in
    --push) MODE="push" ;;
    --fetch) MODE="fetch" ;;
    --init-manifest) INIT_MANIFEST=1 ;;
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

RUNTIME_SKIP=( "data.json" "default.json" "insights.json" "authors.json" "assessments.json" "s3_config.php" ".s3_usage.json" ".s3_uploads.json" ".hosting_usage.json" )
DEV_SKIP=( ".gitignore" "syn.sh" "router.php" "localhost.sh" ".my-idea.txt" "AGENTS.md" "TEMUAN.TXT" "readme.md" "LICENSE" ".syn_manifest" "slimrepo.sh" )
in_list() { local n="$1"; shift; local x; for x in "$@"; do [ "$x" = "$n" ] && return 0; done; return 1; }

if command -v sha1sum >/dev/null 2>&1; then SHACMD="sha1sum"; else SHACMD="shasum -a 1"; fi

list_files() {
  local abs rel
  while IFS= read -r abs; do
    rel="${abs#$REPO_DIR/}"
    case "$rel" in *.gz|*.part|*.tmp|*.synup) continue ;; esac
    in_list "$rel" "${RUNTIME_SKIP[@]}" && continue
    in_list "$rel" "${DEV_SKIP[@]}" && continue
    printf '%s\n' "$rel"
  done < <(find "$REPO_DIR" -mindepth 1 \
            \( -name .git -o -name .backups -o -name .store -o -name seo-cache -o -name .kilo -o -name .uploads -o -name tools -o -name assets -o -path "$REPO_DIR/terpadu" -o -path "$REPO_DIR/.crush" -o -path "$REPO_DIR/.freebuff" \) -prune -o \
            -type f -print)
}

do_git() {
  log "Git: add / commit / push GitHub"
  if [ "$DRY_RUN" = 1 ]; then echo "  [dry-run] git add -A && git commit && git push origin main"; return; fi
  git add -A
  if git diff --cached --quiet; then ok "Tidak ada perubahan untuk di-commit"
  else git commit -m "Sync $(date '+%Y-%m-%d %H:%M')" >/dev/null; ok "Commit dibuat"; fi
  if git push origin main; then ok "Push GitHub berhasil"; else die "Push GitHub gagal"; fi
}

gz_fallback() {
  local rels=("$@")
  [ "${#rels[@]}" -eq 0 ] && return 0
  log "Fallback gz+restore untuk ${#rels[@]} file ..."
  local s gzdir targets="" rel name d skipped=0
  local dirs=(); declare -A seen=()
  for rel in "${rels[@]}"; do
    if [ ! -f "$REPO_DIR/$rel" ]; then warn "Lewati (file lokal hilang): $rel"; skipped=$((skipped+1)); continue; fi
    d="$(dirname "$rel")"
    if [ "$d" != "." ] && [ -z "${seen[$d]:-}" ]; then seen["$d"]=1; dirs+=("$d"); fi
  done
  [ "$skipped" -eq "${#rels[@]}" ] && { warn "Tidak ada file valid untuk fallback"; return 1; }
  s="$(mktemp)"; gzdir="$(mktemp -d)"
  { echo "set net:timeout 120"; echo "set ftp:passive-mode on"; echo "set net:max-retries 6"; echo "set net:reconnect-interval-base 3"; echo "set xfer:clobber yes"; echo "open $FTP_HOST"; } > "$s"
  for d in "${dirs[@]}"; do printf 'mkdir -p "%s"\n' "$FTP_ROOT/$d" >> "$s"; done
  for rel in "${rels[@]}"; do
    [ -f "$REPO_DIR/$rel" ] || continue
    name="$(printf '%s' "$rel" | tr '/' '_')"
    gzip -9 -c "$REPO_DIR/$rel" > "$gzdir/$name.gz"
    printf 'put "%s" -o "%s"\n' "$gzdir/$name.gz" "$FTP_ROOT/$rel.gz" >> "$s"
    targets+="${targets:+,}$rel"
  done
  echo "bye" >> "$s"
  if ! timeout 240 lftp -f "$s"; then rm -rf "$gzdir" "$s"; warn "Upload .gz gagal"; return 1; fi
  rm -f "$s"
  local resp
  resp="$(curl -sS --max-time 180 -G "$RESTORE_URL" --data-urlencode "k=$RESTORE_KEY" --data-urlencode "targets=$targets" 2>/dev/null)" || resp=""
  rm -rf "$gzdir"
  if [ -z "$resp" ]; then
    warn "Endpoint restore tak terjangkau dari sini."
    warn "Buka manual: $RESTORE_URL?k=$RESTORE_KEY  (targets=$targets)"
    return 1
  fi
  local okc=0 failc=0 line
  while IFS= read -r line; do
    [ -z "$line" ] && continue
    case "$line" in "OK "*) okc=$((okc+1)) ;; *) failc=$((failc+1)); warn "$line" ;; esac
  done <<< "$resp"
  if [ "$failc" -eq 0 ]; then ok "Fallback gz+restore OK ($okc file)"; return 0; fi
  warn "Fallback: $okc OK, $failc gagal"; return 1
}

do_ftp_push() {
  log "FTP push (manifest sha1 + temp/rename atomik)"
  local FILES=(); mapfile -t FILES < <(list_files)
  [ "${#FILES[@]}" -eq 0 ] && { ok "Tidak ada file"; return; }

  declare -A H=() M=()
  local rel
  for rel in "${FILES[@]}"; do H["$rel"]="$($SHACMD "$REPO_DIR/$rel" | awk '{print $1}')"; done

  if [ "$INIT_MANIFEST" = 1 ]; then
    : > "$MANIFEST"
    for rel in "${FILES[@]}"; do printf '%s\t%s\n' "$rel" "${H[$rel]}" >> "$MANIFEST"; done
    ok "Manifest diinisialisasi (${#FILES[@]} file)"; return
  fi

  if [ -f "$MANIFEST" ]; then
    while IFS=$'\t' read -r r s; do [ -n "${r:-}" ] && M["$r"]="$s"; done < "$MANIFEST"
  fi

  local changed=()
  for rel in "${FILES[@]}"; do
    if [ "${M[$rel]:-}" != "${H[$rel]}" ]; then changed+=("$rel"); fi
  done

  if [ "${#changed[@]}" -eq 0 ]; then ok "Semua file sinkron (manifest)"; return; fi

  if [ "$DRY_RUN" = 1 ]; then
    warn "dry-run: ${#changed[@]} file berubah:"; printf '    %s\n' "${changed[@]}"; return
  fi

  local UP=".synup"
  local dirs=() d; declare -A seen=()
  for rel in "${changed[@]}"; do
    d="$(dirname "$rel")"
    if [ "$d" != "." ] && [ -z "${seen[$d]:-}" ]; then seen["$d"]=1; dirs+=("$d"); fi
  done
  local s1; s1="$(mktemp)"
  {
    echo "set net:timeout 300"; echo "set xfer:timeout 300"; echo "set ftp:passive-mode on"
    echo "set net:max-retries 6"; echo "set net:reconnect-interval-base 3"; echo "set xfer:clobber yes"; echo "open $FTP_HOST"
    for d in "${dirs[@]}"; do printf 'mkdir -p "%s"\n' "$FTP_ROOT/$d"; done
    for rel in "${changed[@]}"; do printf 'put "%s" -o "%s"\n' "$REPO_DIR/$rel" "$FTP_ROOT/$rel$UP"; done
    echo "bye"
  } > "$s1"
  log "Upload ${#changed[@]} file ke .synup ..."
  timeout 400 lftp -f "$s1" || warn "Sebagian upload .synup gagal; lanjut verifikasi/fallback"
  rm -f "$s1"

  local upaths=()
  for rel in "${changed[@]}"; do upaths+=("$FTP_ROOT/$rel$UP"); done
  local lsl
  lsl="$( { echo "set net:timeout 60"; echo "set ftp:passive-mode on"; echo "open $FTP_HOST"; for p in "${upaths[@]}"; do printf 'ls -la "%s"\n' "$p"; done; echo "bye"; } | timeout 150 lftp 2>/dev/null || true )"
  declare -A TS=()
  local p sz
  while read -r p sz; do [ -n "$p" ] && [ -n "$sz" ] && TS["$p"]="$sz"; done < <(printf '%s\n' "$lsl" | awk 'NF>=9 && $1 ~ /^-/ {print $9, $5}')

  local s3; s3="$(mktemp)"; local okc=0 lsz tsz; local failed=(); local renamed=()
  { echo "set net:timeout 60"; echo "set ftp:passive-mode on"; echo "set net:max-retries 4"; echo "open $FTP_HOST"; } > "$s3"
  for rel in "${changed[@]}"; do
    lsz="$(stat -c%s "$REPO_DIR/$rel" 2>/dev/null || echo -1)"
    tsz="${TS["$FTP_ROOT/$rel$UP"]:-}"
    if [ -n "$tsz" ] && [ "$tsz" = "$lsz" ]; then
      printf 'mv "%s" "%s"\n' "$FTP_ROOT/$rel$UP" "$FTP_ROOT/$rel" >> "$s3"
      renamed+=("$rel")
    else
      printf 'rm -f "%s"\n' "$FTP_ROOT/$rel$UP" >> "$s3"
      failed+=("$rel")
    fi
  done
  echo "bye" >> "$s3"
  if ! timeout 180 lftp -f "$s3"; then
    warn "Rename atomik gagal; ${#renamed[@]} file dialihkan ke fallback gz"
    failed+=("${renamed[@]}")
    renamed=()
  fi
  rm -f "$s3"
  for rel in "${renamed[@]}"; do M["$rel"]="${H[$rel]}"; okc=$((okc+1)); done

  if [ "${#failed[@]}" -gt 0 ]; then
    if gz_fallback "${failed[@]}"; then
      for rel in "${failed[@]}"; do M["$rel"]="${H[$rel]}"; done
      ok "Deploy OK ($okc temp-rename + ${#failed[@]} gz-restore)"
    else
      warn "Sebagian gagal; manifest tidak diperbarui untuk: ${failed[*]}"
    fi
  else
    ok "Deploy OK ($okc file)"
  fi

  : > "$MANIFEST"
  for rel in "${FILES[@]}"; do
    if [ -n "${M[$rel]:-}" ]; then printf '%s\t%s\n' "$rel" "${M[$rel]}" >> "$MANIFEST"; fi
  done
  ok "Manifest diperbarui"
}

do_ftp_fetch() {
  log "FTP fetch (kode/app) FTP -> lokal (tanpa data runtime/.uploads)"
  local script; script="$(mktemp)"
  {
    echo "set net:timeout 300"; echo "set xfer:timeout 300"; echo "set ftp:passive-mode on"
    echo "set net:max-retries 5"; echo "set xfer:clobber yes"; echo "open $FTP_HOST"
    echo "mirror --no-perms --only-newer --exclude-glob data.json --exclude-glob default.json --exclude-glob insights.json --exclude-glob authors.json --exclude-glob assessments.json --exclude-glob s3_config.php --exclude-glob .s3_usage.json --exclude-glob .s3_uploads.json --exclude-glob \".uploads/\" --exclude-glob \".git/\" --exclude-glob \".backups/\" --exclude-glob \".store/\" --exclude-glob \"seo-cache/\" --exclude-glob \".kilo/\" --exclude-glob \"terpadu/\" --exclude-glob \".crush/\" --exclude-glob \".freebuff/\" \"$FTP_ROOT\" \"$REPO_DIR\""
    echo "bye"
  } > "$script"
  if [ "$DRY_RUN" = 1 ]; then warn "[dry-run] skrip lftp (fetch):"; sed 's/^/    /' "$script"; rm -f "$script"; return; fi
  if timeout 300 lftp -f "$script"; then rm -f "$script"; ok "Fetch selesai"; else rm -f "$script"; die "Fetch gagal"; fi
}

case "$MODE" in
  push) [ "$DO_FTP" = 1 ] && do_ftp_push; [ "$DO_GIT" = 1 ] && do_git ;;
  fetch) do_ftp_fetch ;;
esac

log "Selesai."
