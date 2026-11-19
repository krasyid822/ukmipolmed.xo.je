<?php
// migrate_s3.php — pindahkan SEMUA gambar base64 di data.json (rekursif) + avatar authors
// + ikon base64 index.php ke S3 (dengan optimasi WebP). Ganti jadi URL /foto.
// Proteksi ?k=. Membuat backup .bak.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/maintenance_guard.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/maintenance_guard.php';

maintenance_require_json();
if (!s3_available()) {
    echo json_encode(['ok' => false, 'reason' => 's3_unavailable']);
    exit;
}

$report = [
    'data_json' => ['migrated' => 0, 'failed' => 0],
    'authors' => ['migrated' => 0, 'failed' => 0],
    'index_php' => ['replaced' => 0, 'failed' => 0, 'bytes_before' => 0, 'bytes_after' => 0],
];

// Ganti setiap nilai string yang berupa data:image... dengan URL S3 (rekursif).
function migrate_walk(&$node, &$report)
{
    if (is_array($node)) {
        foreach ($node as $k => &$v) {
            if (is_string($v)) {
                if (stripos($v, 'data:image') === 0) {
                    $url = s3_put_data_uri($v);
                    if ($url) { $v = $url; $report['data_json']['migrated']++; }
                    else { $report['data_json']['failed']++; }
                }
            } elseif (is_array($v)) {
                migrate_walk($v, $report);
            }
        }
        unset($v);
    }
}

// ---- data.json (rekursif) ----
$dj = __DIR__ . '/data.json';
$cfg = store_read_json($dj);
if (is_array($cfg)) {
    migrate_walk($cfg, $report);
    if ($report['data_json']['migrated'] > 0) {
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $report['data_json']['bytes_before'] = strlen((string) $json);
        if (store_save_raw($dj, (string) $json)) {
            $report['data_json']['bytes_after'] = strlen((string) $json);
        } else {
            $report['data_json']['failed']++;
        }
    }
}

// ---- authors.json avatars ----
$aj = __DIR__ . '/authors.json';
$ach = false;
$a = store_read_json($aj);
if (is_array($a)) {
    if (!empty($a['authors']) && is_array($a['authors'])) {
        foreach ($a['authors'] as $i => $au) {
            $av = (string) ($au['avatar'] ?? '');
            if (stripos($av, 'data:') === 0) {
                $u = s3_put_data_uri($av);
                if ($u) { $a['authors'][$i]['avatar'] = $u; $report['authors']['migrated']++; $ach = true; }
                else { $report['authors']['failed']++; }
            }
        }
    }
    if ($ach) {
        store_save_json($aj, $a);
    }
}

// ---- index.php inline base64 images (jika ada) ----
$ix = __DIR__ . '/index.php';
if (is_readable($ix)) {
    $html = (string) file_get_contents($ix);
    $report['index_php']['bytes_before'] = strlen($html);
    $html2 = preg_replace_callback(
        '#data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]+#i',
        function ($m) use (&$report) {
            $url = s3_put_data_uri($m[0]);
            if ($url) { $report['index_php']['replaced']++; return $url; }
            $report['index_php']['failed']++;
            return $m[0];
        },
        $html
    );
    if (is_string($html2) && $html2 !== $html) {
        @copy($ix, $ix . '.bak');
        file_put_contents($ix, $html2);
        $report['index_php']['bytes_after'] = strlen($html2);
    }
}

echo json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
