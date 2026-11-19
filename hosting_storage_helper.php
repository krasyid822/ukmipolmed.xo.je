<?php
// hosting_storage_helper.php — pemakaian storage sisi hosting (seluruh akun).
//
// InfinityFree tidak menyediakan API kuota, jadi angka dihitung dengan scan
// filesystem murni (tanpa exec/system). Bila docroot bernama "htdocs", scan
// dimulai dari folder induknya (root akun) agar mendekati angka panel; selain
// itu dipakai docroot itu sendiri. Database MySQL dan email tidak berada di
// filesystem akun, jadi tidak ikut terhitung.
//
// Semua batas (max_bytes, max_inodes) hanya dibaca dari default.json. Tidak ada
// nilai hardcoded: bila tidak tersedia, batas = 0 dan persentase = null.
//
// Cache hasil scan disimpan di seo-cache/hosting-usage.json (TTL 6 jam) agar
// polling /status tiap 8 detik tidak memicu scan berulang.

require_once __DIR__ . '/seo_cache.php';

define('HOSTING_STORAGE_TTL', 21600);
define('HOSTING_STORAGE_CACHE', seo_cache_path('hosting-usage.json'));
define('HOSTING_STORAGE_MAX_ENTRIES', 100000);
// Naikkan saat format/cakupan scan berubah agar cache lama otomatis diabaikan.
define('HOSTING_STORAGE_VERSION', 3);

// Konfigurasi hosting dari default.json. Nilai 0 berarti tidak diketahui.
function hosting_config()
{
    static $c = null;
    if ($c !== null) return $c;

    $maxBytes = 0;
    $maxInodes = 0;
    $scanRoot = '';
    $p = __DIR__ . '/default.json';
    if (is_readable($p)) {
        $cfg = json_decode((string) @file_get_contents($p), true);
        if (is_array($cfg) && isset($cfg['hosting']) && is_array($cfg['hosting'])) {
            $maxBytes = max(0, (int) ($cfg['hosting']['max_bytes'] ?? 0));
            $maxInodes = max(0, (int) ($cfg['hosting']['max_inodes'] ?? 0));
            $scanRoot = trim((string) ($cfg['hosting']['scan_root'] ?? ''));
        }
    }
    return $c = [
        'max_bytes' => $maxBytes,
        'max_inodes' => $maxInodes,
        'scan_root' => $scanRoot,
    ];
}

// Root scan: override "hosting.scan_root" (relatif terhadap docroot), atau
// otomatis folder induk saat docroot bernama "htdocs" (root akun hosting).
function hosting_scan_root()
{
    static $root = null;
    if ($root !== null) return $root;

    $cfg = hosting_config();
    $custom = (string) $cfg['scan_root'];
    if ($custom !== '') {
        $dir = $custom[0] === '/' ? rtrim($custom, '/') : realpath(__DIR__ . '/' . $custom);
        if ($dir !== false && @is_dir($dir) && hosting_dir_listable($dir)) return $root = $dir;
        return $root = __DIR__;
    }

    if (basename(__DIR__) === 'htdocs') {
        $parent = dirname(__DIR__);
        if (@is_dir($parent) && @is_readable($parent) && hosting_dir_listable($parent)) {
            return $root = $parent;
        }
    }
    return $root = __DIR__;
}

// Pastikan direktori bisa dibuka (open_basedir/izin) sebelum dipakai sebagai root.
function hosting_dir_listable($dir)
{
    try {
        $it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        $it->rewind();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// Blok disk aktual; fallback pembulatan 4 KiB bila stat tidak memberi blok.
function hosting_bytes_used($blocks, $size)
{
    $blocks = (int) $blocks;
    $size = max(0, (int) $size);
    if ($blocks > 0) return $blocks * 512;
    if ($size <= 0) return 0;
    return (int) (ceil($size / 4096) * 4096);
}

// Scan rekursif root akun/docroot. Return array statistik, atau null bila gagal.
function hosting_storage_scan()
{
    $root = hosting_scan_root();
    $accountWide = ($root !== __DIR__);
    $t0 = microtime(true);

    $bytesUsed = 0;
    $bytesApparent = 0;
    $files = 0;
    $directories = 0;
    $entries = 0;
    $truncated = false;
    $errors = [];
    $breakdown = [];
    $storeEntries = [];

    $segOf = function ($rel) {
        $pos = strpos($rel, '/');
        return $pos === false ? '(root files)' : substr($rel, 0, $pos);
    };

    try {
        $stack = [$root];
        while ($stack) {
            $dir = array_pop($stack);
            try {
                $items = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
            } catch (Throwable $e) {
                if (count($errors) < 50) {
                    $errors[] = ltrim(str_replace('\\', '/', substr($dir, strlen($root))), '/');
                }
                continue;
            }

            foreach ($items as $info) {
                if ($entries >= HOSTING_STORAGE_MAX_ENTRIES) {
                    $truncated = true;
                    break 2;
                }
                $path = $info->getPathname();
                $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
                if ($rel === '' || $rel === '.') continue;
                // Path relatif docroot: saat scan account-wide, prefix htdocs/ dibuang
                // agar breakdown tetap per folder situs (seo-cache, components, dst.).
                $docRel = $rel;
                if ($accountWide && $rel !== 'htdocs' && strpos($rel, 'htdocs/') === 0) {
                    $docRel = substr($rel, strlen('htdocs/'));
                }
                if (in_array($docRel, ['.hosting_usage.json', '.hosting_usage.json.tmp'], true)) continue;
                if ($info->isLink()) continue;

                $seg = (strpos($docRel, '/') === false && $info->isDir())
                    ? $docRel
                    : $segOf($docRel);
                if (!isset($breakdown[$seg])) {
                    $breakdown[$seg] = [
                        'name' => $seg,
                        'bytes_used' => 0,
                        'bytes_apparent' => 0,
                        'files' => 0,
                        'directories' => 0,
                    ];
                }

                if ($info->isDir()) {
                    $st = @stat($path);
                    if ($st === false) {
                        if (count($errors) < 50) $errors[] = $rel;
                        continue;
                    }
                    $directories++;
                    $entries++;
                    $used = hosting_bytes_used($st['blocks'] ?? 0, $st['size'] ?? 0);
                    $bytesUsed += $used;
                    $breakdown[$seg]['bytes_used'] += $used;
                    $breakdown[$seg]['directories']++;
                    $stack[] = $path;
                    continue;
                }

                if (!$info->isFile()) continue;
                $st = @stat($path);
                if ($st === false) {
                    if (count($errors) < 50) $errors[] = $rel;
                    continue;
                }

                $apparent = max(0, (int) ($st['size'] ?? 0));
                $used = hosting_bytes_used($st['blocks'] ?? 0, $apparent);
                $files++;
                $entries++;
                $bytesApparent += $apparent;
                $bytesUsed += $used;
                $breakdown[$seg]['bytes_used'] += $used;
                $breakdown[$seg]['bytes_apparent'] += $apparent;
                $breakdown[$seg]['files']++;

                if ($seg === SEO_CACHE_DIR_NAME) {
                    $storeEntries[] = [
                        'name' => substr($docRel, strlen(SEO_CACHE_DIR_NAME . '/')),
                        'bytes' => $apparent,
                        'updated_at' => date('c', (int) ($st['mtime'] ?? time())),
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'scan: ' . $e->getMessage();
    }

    usort($breakdown, function ($a, $b) {
        return $b['bytes_used'] <=> $a['bytes_used'];
    });

    $detail = function ($seg) use (&$breakdown, $storeEntries) {
        foreach ($breakdown as $row) {
            if ($row['name'] !== $seg) continue;
            $out = [
                'bytes_used' => (int) $row['bytes_used'],
                'bytes_apparent' => (int) $row['bytes_apparent'],
                'files' => (int) $row['files'],
            ];
            if ($seg === SEO_CACHE_DIR_NAME) {
                $list = $storeEntries;
                usort($list, function ($a, $b) {
                    return $b['bytes'] <=> $a['bytes'];
                });
                $list = array_slice($list, 0, 500);
                $out['entries'] = $list;
            }
            return $out;
        }
        return null;
    };

    return [
        'source' => 'hosting',
        'version' => HOSTING_STORAGE_VERSION,
        'root_mode' => $accountWide ? 'account' : 'docroot',
        'scope' => $accountWide
            ? 'account (all files under hosting account; excludes MySQL database and email)'
            : 'docroot (htdocs); excludes database, email, and other account files',
        'bytes_used' => $bytesUsed,
        'bytes_apparent' => $bytesApparent,
        'files' => $files,
        'directories' => $directories,
        'inodes' => $files + $directories,
        'cache' => $detail(SEO_CACHE_DIR_NAME),
        'breakdown' => $breakdown,
        'truncated' => $truncated,
        'errors' => $errors,
        'scan_ms' => round((microtime(true) - $t0) * 1000, 2),
        'updated_at' => date('c'),
        'at' => time(),
    ];
}

// Statistik dengan cache (default 6 jam). $force=true memaksa scan ulang.
// Bila scan gagal, cache lama dikembalikan dengan tanda stale=true.
function hosting_storage_stats($ttl = HOSTING_STORAGE_TTL, $force = false)
{
    $cacheFile = HOSTING_STORAGE_CACHE;
    $cached = null;
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (!is_array($cached) || !isset($cached['bytes_used'], $cached['at'])
            || (int) ($cached['version'] ?? 0) !== HOSTING_STORAGE_VERSION) {
            $cached = null;
        }
    }

    if (!$force && $cached !== null && (int) $cached['at'] + $ttl > time()) {
        $cached['stale'] = false;
        return $cached;
    }

    $fresh = hosting_storage_scan();
    if ($fresh === null) {
        if ($cached !== null) $cached['stale'] = true;
        return $cached;
    }

    $json = json_encode($fresh, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false) {
        $tmp = $cacheFile . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            if (!@rename($tmp, $cacheFile)) @unlink($tmp);
            else seo_cache_gc(basename($cacheFile));
        }
    }
    $fresh['stale'] = false;
    return $fresh;
}

// Gabungan statistik + kuota untuk endpoint /status.
// Default cache-only agar polling UI tidak memicu scan.
function hosting_storage_pool($force = false)
{
    static $memo = [];
    $key = $force ? 'force' : 'cache';
    if (array_key_exists($key, $memo)) return $memo[$key];

    $stats = hosting_storage_stats(HOSTING_STORAGE_TTL, $force);
    if (!is_array($stats) || !isset($stats['bytes_used'])) return $memo[$key] = null;

    $cfg = hosting_config();
    $limit = (int) $cfg['max_bytes'];
    $inodeLimit = (int) $cfg['max_inodes'];
    $used = max(0, (int) $stats['bytes_used']);
    $inodesUsed = max(0, (int) ($stats['inodes'] ?? 0));
    $at = (int) ($stats['at'] ?? 0);

    return $memo[$key] = [
        'source' => 'hosting',
        'root_mode' => $stats['root_mode'] ?? 'docroot',
        'scope' => $stats['scope'] ?? 'docroot (htdocs); excludes database, email, and other account files',
        'bytes_used' => $used,
        'bytes_apparent' => max(0, (int) ($stats['bytes_apparent'] ?? 0)),
        'limit_bytes' => $limit,
        'remaining_bytes' => $limit > 0 ? max(0, $limit - $used) : 0,
        'percent_used' => $limit > 0 ? round(($used / $limit) * 100, 2) : null,
        'inodes_used' => $inodesUsed,
        'inode_limit' => $inodeLimit,
        'inode_percent_used' => $inodeLimit > 0 ? round(($inodesUsed / $inodeLimit) * 100, 2) : null,
        'files' => max(0, (int) ($stats['files'] ?? 0)),
        'directories' => max(0, (int) ($stats['directories'] ?? 0)),
        'cache' => $stats['cache'] ?? null,
        'breakdown' => is_array($stats['breakdown'] ?? null) ? $stats['breakdown'] : [],
        'updated_at' => $stats['updated_at'] ?? null,
        'age_seconds' => $at > 0 ? max(0, time() - $at) : null,
        'stale' => !empty($stats['stale']),
        'scan_ms' => $stats['scan_ms'] ?? null,
        'truncated' => !empty($stats['truncated']),
        'errors' => is_array($stats['errors'] ?? null) ? $stats['errors'] : [],
    ];
}
