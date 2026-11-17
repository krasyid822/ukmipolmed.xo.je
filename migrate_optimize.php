<?php
// migrate_optimize.php — optimasi objek S3 yang sudah ada (dibuat sebelum optimasi).
// GET (signed) -> optimize (WebP<=1400) -> PUT baru -> hapus lama -> perbarui URL.
// Memperbarui data.json (posts) dan index.php (ikon). Proteksi ?k=.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/s3_helper.php';

if (($_REQUEST['k'] ?? '') !== 'UKMIrestore2026') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
if (!s3_available()) {
    echo json_encode(['ok' => false, 'reason' => 's3_unavailable']);
    exit;
}

$report = [
    'data_json' => ['optimized' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_before' => 0, 'bytes_after' => 0],
    'index_php' => ['replaced' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_before' => 0, 'bytes_after' => 0],
];

$CACHE = [];
function opt_key($key, &$report, $bucketName)
{
    global $CACHE;
    if (isset($CACHE[$key])) return $CACHE[$key];
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { $CACHE[$key] = null; return null; }
    $g = s3_get_object($key);
    if (($g['code'] ?? 0) !== 200 || ($g['body'] ?? null) === null) { $report[$bucketName]['failed']++; $CACHE[$key] = null; return null; }
    $bin = $g['body'];
    $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
    list($ob, $omime) = s3_optimize_image($bin, $mime);
    if ($ob === $bin) { $report[$bucketName]['skipped']++; $CACHE[$key] = null; return null; }
    $newKey = s3_prefix() . 'uploads/' . sha1($ob) . '.' . s3_ext_for_mime($omime);
    if (!s3_put_binary($ob, $newKey, $omime)) { $report[$bucketName]['failed']++; $CACHE[$key] = null; return null; }
    s3_delete_object($key);
    $report[$bucketName]['optimized']++;
    $report[$bucketName]['bytes_before'] += strlen($bin);
    $report[$bucketName]['bytes_after'] += strlen($ob);
    $url = s3_photo_url($newKey);
    $CACHE[$key] = $url;
    return $url;
}

// ---- data.json ----
$dj = __DIR__ . '/data.json';
$changed = false;
if (is_readable($dj)) {
    $cfg = json_decode((string) file_get_contents($dj), true);
    if (is_array($cfg)) {
        foreach (['posts', 'posts_archived'] as $section) {
            if (!empty($cfg[$section]) && is_array($cfg[$section])) {
                foreach ($cfg[$section] as $i => $p) {
                    $img = (string) ($p['image'] ?? '');
                    if (preg_match('#foto\.php\?key=([^&"\s]+)#', $img, $mm)) {
                        $newUrl = opt_key(urldecode($mm[1]), $report, 'data_json');
                        if ($newUrl) { $cfg[$section][$i]['image'] = $newUrl; $changed = true; }
                    }
                }
            }
        }
        if ($changed) {
            @copy($dj, $dj . '.bak');
            file_put_contents($dj, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}

// ---- index.php ----
$ix = __DIR__ . '/index.php';
if (is_readable($ix)) {
    $html = (string) file_get_contents($ix);
    $report['index_php']['bytes_before'] = strlen($html);
    $html2 = preg_replace_callback('#foto\.php\?key=([A-Za-z0-9%._-]+)#', function ($m) use (&$report) {
        $url = opt_key(urldecode($m[1]), $report, 'index_php');
        return $url ? $url : $m[0];
    }, $html);
    if (is_string($html2) && $html2 !== $html) {
        @copy($ix, $ix . '.bak');
        file_put_contents($ix, $html2);
        $report['index_php']['bytes_after'] = strlen($html2);
        $report['index_php']['replaced'] = $report['index_php']['optimized'];
    }
}

echo json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
