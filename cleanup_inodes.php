<?php
// cleanup_inodes.php — bersih-bersih hosting sekali jalan untuk menghemat inode.
//
// Target hanya artefak cache/backup/temp yang tidak dipakai lagi:
//   - .backups/ dan .store/ (cache lama, sudah dimigrasikan ke seo-cache/)
//   - .s3_usage.json, .s3_uploads.json, .hosting_usage.json (cache root lama)
//   - *.tmp, *.synup, *.part di root dan seo-cache/
//
// Default DRY-RUN. Untuk benar-benar menghapus:
//   CLI : php cleanup_inodes.php --run
//   Web : cleanup_inodes.php?k=<maintenance_key>&run=1
//
// Tidak menyentuh kode, data.json, media, .git, atau isi seo-cache yang aktif.

require_once __DIR__ . '/seo_cache.php';
require_once __DIR__ . '/maintenance_guard.php';

$isCli = PHP_SAPI === 'cli';
maintenance_require_json();

$run = $isCli ? in_array('--run', $argv, true) : (($_REQUEST['run'] ?? '') === '1');

function ci_measure($path)
{
    $out = ['files' => 0, 'directories' => 0, 'bytes' => 0];
    if (is_link($path) || !file_exists($path)) return $out;
    if (is_file($path)) {
        $out['files'] = 1;
        $out['bytes'] = (int) @filesize($path);
        return $out;
    }
    if (!is_dir($path)) return $out;

    $stack = [$path];
    while ($stack) {
        $dir = array_pop($stack);
        $out['directories']++;
        try {
            $it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($it as $info) {
            if ($info->isLink()) continue;
            if ($info->isDir()) {
                $stack[] = $info->getPathname();
            } elseif ($info->isFile()) {
                $out['files']++;
                $out['bytes'] += (int) $info->getSize();
            }
        }
    }
    return $out;
}

function ci_remove($path)
{
    if (is_link($path) || !file_exists($path)) return true;
    if (is_file($path)) return @unlink($path);
    if (!is_dir($path)) return true;

    try {
        $it = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    } catch (Throwable $e) {
        return false;
    }
    $ok = true;
    foreach ($it as $info) {
        if ($info->isLink()) {
            $ok = @unlink($info->getPathname()) && $ok;
        } elseif ($info->isDir()) {
            $ok = ci_remove($info->getPathname()) && $ok;
        } else {
            $ok = @unlink($info->getPathname()) && $ok;
        }
    }
    return @rmdir($path) && $ok;
}

// Migrasikan cache lama lebih dulu agar isi .store tidak hilang.
seo_cache_dir();

$targets = [
    __DIR__ . '/.backups',
    __DIR__ . '/.store',
    __DIR__ . '/.s3_usage.json',
    __DIR__ . '/.s3_uploads.json',
    __DIR__ . '/.hosting_usage.json',
];
foreach ((array) glob(__DIR__ . '/*.tmp') as $f) $targets[] = $f;
foreach ((array) glob(__DIR__ . '/*.synup') as $f) $targets[] = $f;
foreach ((array) glob(__DIR__ . '/*.part') as $f) $targets[] = $f;
foreach ((array) glob(seo_cache_dir() . '/*.tmp') as $f) $targets[] = $f;

$report = [];
$total = ['files' => 0, 'directories' => 0, 'bytes' => 0];
foreach (array_unique($targets) as $path) {
    if (!file_exists($path) && !is_link($path)) continue;
    $m = ci_measure($path);
    $item = [
        'path' => ltrim(str_replace('\\', '/', substr($path, strlen(__DIR__))), '/'),
        'files' => $m['files'],
        'directories' => $m['directories'],
        'bytes' => $m['bytes'],
        'removed' => false,
    ];
    if ($run) {
        $item['removed'] = ci_remove($path);
    }
    foreach ($total as $k => $v) $total[$k] += $m[$k];
    $report[] = $item;
}

$cacheGc = $run ? seo_cache_gc() : null;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'dry_run' => !$run,
    'cache_dir' => SEO_CACHE_DIR_NAME,
    'total' => $total,
    'targets' => $report,
    'cache_gc' => $cacheGc,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
