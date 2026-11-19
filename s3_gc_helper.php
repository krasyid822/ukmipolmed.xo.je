<?php
// s3_gc_helper.php — referensi objek S3 dan pembersihan objek yatim.
//
// Objek yang dikelola (boleh dibersihkan) hanya dua prefix:
//   <prefix>/uploads/       gambar sampul, avatar, task image, komentar
//   <prefix>/assessments/   file Excel asesmen
// Prefix lain (assets/, data/, data_json/, assessments_json/) tidak disentuh.

require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/store.php';

function s3_gc_managed_prefixes()
{
    $p = s3_prefix();
    return [$p . 'uploads/', $p . 'assessments/'];
}

function s3_gc_is_managed($key)
{
    $key = (string) $key;
    foreach (s3_gc_managed_prefixes() as $prefix) {
        if ($prefix !== '' && strpos($key, $prefix) === 0) return true;
    }
    return false;
}

// Ambil key objek dari nilai apa pun: key mentah, URL /foto?key=..., atau URL
// absolut. Nilai non-S3 (http eksternal, data URI) menghasilkan null.
function s3_gc_extract_key($value)
{
    if (!is_string($value) || $value === '') return null;
    $prefix = s3_prefix();
    if ($prefix === '') return null;
    $pattern = '#' . preg_quote($prefix, '#') . '(?:uploads|assessments)/[A-Za-z0-9._-]+#';
    foreach ([$value, rawurldecode($value), rawurldecode(rawurldecode($value))] as $hay) {
        if (preg_match($pattern, $hay, $m)) return $m[0];
    }
    return null;
}

// Kumpulkan semua key S3 yang dirujuk dari nilai JSON/teks (rekursif).
function s3_gc_collect_refs($value, array &$refs)
{
    if (is_array($value)) {
        foreach ($value as $v) s3_gc_collect_refs($v, $refs);
        return;
    }
    if (!is_string($value) || $value === '') return;
    $prefix = s3_prefix();
    if ($prefix === '') return;
    $pattern = '#' . preg_quote($prefix, '#') . '(?:uploads|assessments)/[A-Za-z0-9._-]+#';
    foreach ([$value, rawurldecode($value), rawurldecode(rawurldecode($value))] as $hay) {
        if (preg_match_all($pattern, $hay, $m)) {
            foreach ($m[0] as $key) $refs[$key] = true;
        }
    }
}

// Referensi statis di repo (default.json, komponen, readme) ikut dilindungi
// agar objek contoh/bawaan tidak dianggap yatim. seo-cache/ sengaja dilewati
// karena isinya bisa basi.
function s3_gc_scan_static_refs(array &$refs)
{
    $files = [];
    foreach (['*.php', '*.json', '*.md', '*.txt'] as $pattern) {
        $files = array_merge($files, (array) glob(__DIR__ . '/' . $pattern));
    }
    $files = array_merge($files, (array) glob(__DIR__ . '/components/*.php'));
    $files = array_merge($files, (array) glob(__DIR__ . '/lib/*.php'));
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $size = @filesize($file);
        if ($size === false || $size > 2097152) continue;
        $text = @file_get_contents($file);
        if (is_string($text) && $text !== '') s3_gc_collect_refs($text, $refs);
    }
}

// Seluruh key S3 yang masih dirujuk oleh data runtime (+ opsional file statis).
// $sources melaporkan sumber mana yang benar-benar berhasil dibaca. Pemanggil
// yang akan menghapus objek WAJIB memeriksa ini: kegagalan baca data runtime
// tidak boleh dianggap "tidak ada referensi".
function s3_gc_current_refs(&$sources = null, bool $scanStatic = true)
{
    $refs = [];
    $sources = ['data' => false, 'authors' => false, 'assessments' => false, 'default' => false];
    $map = [
        'data.json' => 'data',
        'authors.json' => 'authors',
        'assessments.json' => 'assessments',
        'default.json' => 'default',
    ];
    foreach ($map as $name => $source) {
        $path = __DIR__ . '/' . $name;
        if (!is_file($path)) continue;
        $data = store_read_json($path);
        if (!is_array($data)) continue;
        // Mode pecahan tetap mengembalikan array parsial saat satu shard gagal
        // dibaca; jangan pernah menganggapnya lengkap (bisa menghapus objek
        // yang masih dirujuk).
        if (function_exists('store_read_status_complete') && !store_read_status_complete($path)) continue;
        $sources[$source] = true;
        s3_gc_collect_refs($data, $refs);
    }
    if ($scanStatic) s3_gc_scan_static_refs($refs);
    return $refs;
}

// Data runtime minimal yang harus terbaca sebelum objek boleh dihapus.
function s3_gc_runtime_complete(array $sources)
{
    return !empty($sources['data']) && !empty($sources['authors']) && !empty($sources['assessments']);
}

function s3_gc_invalidate_usage_cache()
{
    @unlink(seo_cache_path('s3-usage.json'));
    if (function_exists('s3_uploads_cache_invalidate')) s3_uploads_cache_invalidate();
}

// Hapus key yang sudah tidak dirujuk lagi. Dipanggil setelah penyimpanan
// sukses (ganti/hapus gambar atau file asesmen). Key yang masih dipakai di
// tempat lain — misalnya gambar sama dipakai dua postingan — tetap dipertahankan
// karena key uploads/ berbasis hash konten.
function s3_gc_prune_keys(array $keys)
{
    $report = ['checked' => 0, 'deleted' => 0, 'kept' => 0, 'failed' => 0];
    $candidates = [];
    foreach ($keys as $value) {
        $key = s3_gc_extract_key($value);
        if ($key === null || !s3_gc_is_managed($key)) continue;
        $candidates[$key] = true;
    }
    if (empty($candidates)) return $report;

    // Tanpa data runtime yang utuh, jangan hapus apa pun: referensi bisa hilang
    // karena kegagalan baca sementara, bukan karena objek benar-benar yatim.
    $sources = [];
    $refs = s3_gc_current_refs($sources, false);
    if (!s3_gc_runtime_complete($sources)) {
        $report['skipped'] = 'runtime_data_incomplete';
        $report['sources'] = $sources;
        return $report;
    }
    foreach (array_keys($candidates) as $key) {
        $report['checked']++;
        if (isset($refs[$key])) {
            $report['kept']++;
            continue;
        }
        if (s3_delete_object($key)) $report['deleted']++;
        else $report['failed']++;
    }
    if ($report['deleted'] > 0) s3_gc_invalidate_usage_cache();
    return $report;
}
