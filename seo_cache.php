<?php
// seo_cache.php — satu pintu untuk seluruh cache runtime sisi hosting.
//
// Cache ini adalah L1 (disk hosting) untuk data yang sumber kebenarannya di S3.
// Tujuannya menjaga TTFB/Core Web Vitals tetap baik saat S3 lambat, bukan
// menggantikan S3. Folder ini TIDAK boleh diakses dari web (lihat .htaccess).
//
// Yang disimpan di sini hanya cache/pointer kecil:
//   data.cache.json, insights.cache.json, authors.cache.json,
//   assessments.cache.json, s3-usage.json, s3-uploads.json, hosting-usage.json,
//   store.lock
//
// Yang tetap sepenuhnya di S3: payload data runtime dan seluruh media uploads.
// Yang dialihkan ke browser: aset statis + gambar /foto lewat header cache.

if (!defined('SEO_CACHE_DIR_NAME')) {
    define('SEO_CACHE_DIR_NAME', 'seo-cache');
}
if (!defined('SEO_CACHE_DIR')) {
    define('SEO_CACHE_DIR', __DIR__ . '/' . SEO_CACHE_DIR_NAME);
}

// Batas aman agar cache tidak pernah tumbuh tanpa batas. Nilai bisa
// ditimpa dari default.json -> "seo_cache" (max_bytes, max_file_bytes,
// max_age_days, tmp_ttl_seconds). Ini hanya pagar pengaman; target normal
// cache tetap < 100 KB.
function seo_cache_config()
{
    static $c = null;
    if ($c !== null) return $c;

    $c = [
        'max_bytes' => 8 * 1024 * 1024,
        'max_file_bytes' => 2 * 1024 * 1024,
        'max_files' => 512,
        'max_age_days' => 90,
        'tmp_ttl_seconds' => 900,
    ];
    $p = __DIR__ . '/default.json';
    if (is_readable($p)) {
        $cfg = json_decode((string) @file_get_contents($p), true);
        if (is_array($cfg) && isset($cfg['seo_cache']) && is_array($cfg['seo_cache'])) {
            foreach (['max_bytes', 'max_file_bytes', 'max_files', 'max_age_days', 'tmp_ttl_seconds'] as $k) {
                if (isset($cfg['seo_cache'][$k])) {
                    $c[$k] = max(0, (int) $cfg['seo_cache'][$k]);
                }
            }
        }
    }
    return $c;
}

function seo_cache_max_bytes()
{
    $c = seo_cache_config();
    return (int) $c['max_bytes'];
}

function seo_cache_max_file_bytes()
{
    $c = seo_cache_config();
    return (int) $c['max_file_bytes'];
}

// Folder cache aktif (dibuat otomatis, sekali migrasi dari lokasi lama).
function seo_cache_dir()
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $dir = SEO_CACHE_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    seo_cache_migrate_legacy($dir);
    return $dir;
}

// Path file di dalam seo-cache.
function seo_cache_path($name)
{
    return seo_cache_dir() . '/' . basename((string) $name);
}

// Garbage collector: buang .tmp basi, cache kedaluwarsa, lalu evict cache
// berprioritas rendah bila total melewati max_bytes. Hanya menyentuh file
// cache yang dikenal; file lain di folder ini tidak dihapus.
// $protect = nama file yang baru ditulis dan tidak boleh dihapus.
function seo_cache_gc($protect = null)
{
    $dir = seo_cache_dir();
    $cfg = seo_cache_config();
    $now = time();
    $maxAge = max(1, (int) $cfg['max_age_days']) * 86400;
    $tmpTtl = max(60, (int) $cfg['tmp_ttl_seconds']);
    $maxBytes = (int) $cfg['max_bytes'];
    $maxFiles = (int) $cfg['max_files'];

    $knownUsage = ['s3-usage.json', 's3-uploads.json', 'hosting-usage.json'];
    $files = [];
    $total = 0;
    $deleted = 0;
    $freed = 0;

    try {
        $it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
    } catch (Throwable $e) {
        return ['deleted' => 0, 'freed_bytes' => 0, 'total_bytes' => 0, 'files' => 0];
    }

    foreach ($it as $info) {
        if (!$info->isFile() || $info->isLink()) continue;
        $name = $info->getFilename();
        $size = max(0, (int) $info->getSize());
        $mtime = max(0, (int) $info->getMTime());

        if (substr($name, -4) === '.tmp') {
            if ($now - $mtime > $tmpTtl && $name !== $protect) {
                if (@unlink($info->getPathname())) {
                    $deleted++;
                    $freed += $size;
                }
            }
            continue;
        }

        if ($name === 'store.lock') continue;
        $isCache = substr($name, -11) === '.cache.json'
            || substr($name, -14) === '.cache.json.gz'
            || substr($name, -10) === '.rate.json'
            || strpos($name, 'sticker-rate-') === 0;
        if (!$isCache && !in_array($name, $knownUsage, true)) continue;
        if ($name === $protect) {
            $total += $size;
            continue;
        }
        if ($mtime > 0 && $now - $mtime > $maxAge) {
            if (@unlink($info->getPathname())) {
                $deleted++;
                $freed += $size;
                continue;
            }
        }
        $files[] = ['name' => $name, 'path' => $info->getPathname(), 'size' => $size, 'mtime' => $mtime];
        $total += $size;
    }

    if (($total > $maxBytes || ($maxFiles > 0 && count($files) > $maxFiles)) && $files) {
        $priority = function ($name) use ($knownUsage) {
            if (in_array($name, $knownUsage, true)) return 0;
            $base = preg_replace('/\.gz$/', '', $name);
            if ($base === 'insights.cache.json') return 5;
            if ($base === 'data.cache.json') return 6;
            if (strpos($base, 'data_json_comments_') === 0) return 1;
            if (strpos($base, 'assessments_json_responses_') === 0) return 1;
            if (strpos($base, 'data_json_posts_index_') === 0) return 4;
            if (strpos($base, 'data_json_posts_') === 0) return 2;
            if (strpos($base, 'data_json_manifest_') === 0) return 4;
            if (strpos($base, 'assessments_json_index_') === 0) return 4;
            if (strpos($base, 'assessments_json_manifest_') === 0) return 4;
            if (preg_match('/^data_json_(meta|site|faqs|logs|extra)_/', $base)) return 4;
            if ($base === 'authors.cache.json' || $base === 'assessments.cache.json') return 3;
            return 2;
        };
        usort($files, function ($a, $b) use ($priority) {
            $pa = $priority($a['name']);
            $pb = $priority($b['name']);
            if ($pa !== $pb) return $pa <=> $pb;
            return $a['mtime'] <=> $b['mtime'];
        });
        $remaining = count($files) + ($protect !== null ? 1 : 0);
        foreach ($files as $f) {
            $overBytes = $total > $maxBytes;
            $overFiles = $maxFiles > 0 && $remaining > $maxFiles;
            if (!$overBytes && !$overFiles) break;
            if (@unlink($f['path'])) {
                $total -= $f['size'];
                $remaining--;
                $deleted++;
                $freed += $f['size'];
            }
        }
    }

    return [
        'deleted' => $deleted,
        'freed_bytes' => $freed,
        'total_bytes' => max(0, $total),
        'files' => count($files),
        'max_bytes' => $maxBytes,
        'max_files' => $maxFiles,
    ];
}

// Migrasi satu kali dari .store/ dan file cache root versi lama.
// Aman dipanggil berulang; file tujuan yang sudah ada tidak ditimpa.
function seo_cache_migrate_legacy($dir)
{
    $legacyDir = __DIR__ . '/.store';
    if (is_dir($legacyDir)) {
        foreach ((array) glob($legacyDir . '/*.cache.json') as $src) {
            $dst = $dir . '/' . basename($src);
            if (!file_exists($dst)) {
                @rename($src, $dst);
            } else {
                @unlink($src);
            }
        }
        foreach ((array) glob($legacyDir . '/*.lock') as $src) {
            @unlink($src);
        }
        @rmdir($legacyDir);
    }

    $legacyFiles = [
        '.s3_usage.json' => 's3-usage.json',
        '.s3_uploads.json' => 's3-uploads.json',
        '.hosting_usage.json' => 'hosting-usage.json',
    ];
    foreach ($legacyFiles as $old => $new) {
        $src = __DIR__ . '/' . $old;
        $dst = $dir . '/' . $new;
        if (is_file($src) && !file_exists($dst)) {
            @rename($src, $dst);
        }
    }
}
