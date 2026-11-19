<?php
// s3_gc.php — bersih-bersih objek yatim di S3.
//
// Hanya menghapus objek yang memenuhi semua syarat:
//   1. berada di prefix terkelola: <prefix>/uploads/ atau <prefix>/assessments/
//   2. tidak dirujuk data.json, authors.json, assessments.json, default.json,
//      maupun file statis di repo
//   3. LastModified lebih tua dari masa tenggang (default 7 hari)
//
// Pemakaian:
//   Web  : /s3_gc?k=<maintenance_key>                (dry-run)
//          /s3_gc?k=<maintenance_key>&run=1&days=7
//   CLI  : php s3_gc.php                              (dry-run)
//          php s3_gc.php --run --days=7
//
// Dry-run adalah default; tidak ada penghapusan tanpa run=1/--run.

require_once __DIR__ . '/s3_gc_helper.php';
require_once __DIR__ . '/maintenance_guard.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) maintenance_require_json();

@set_time_limit(0);

$run = $isCli ? in_array('--run', $argv, true) : (($_REQUEST['run'] ?? '') === '1');
if (!$isCli && $run && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'run_requires_post']);
    exit;
}
$daysRaw = $isCli
    ? (($p = array_values(array_filter($argv, fn($a) => strpos($a, '--days=') === 0))) ? substr($p[0], 7) : 7)
    : ($_REQUEST['days'] ?? 7);
$days = max(0, min(365, (int) $daysRaw));

$report = [
    'ok' => true,
    'dry_run' => !$run,
    'grace_days' => $days,
    'prefix' => s3_prefix(),
    'managed_prefixes' => s3_gc_managed_prefixes(),
    'references' => 0,
    'scanned' => 0,
    'referenced' => 0,
    'orphans' => 0,
    'skipped_recent' => 0,
    'deleted' => 0,
    'failed' => 0,
    'freed_bytes' => 0,
    'objects' => [],
];

if (!s3_available()) {
    $report['ok'] = false;
    $report['error'] = 's3_unavailable';
} else {
    $sources = [];
    $refs = s3_gc_current_refs($sources);
    $report['references'] = count($refs);
    $report['sources'] = $sources;

    if (!s3_gc_runtime_complete($sources)) {
        // Jangan pernah menganggap semua objek yatim hanya karena data gagal dibaca.
        $report['ok'] = false;
        $report['error'] = 'runtime_data_incomplete';
    } else {
        $list = s3_list_objects();
        if (empty($list['ok'])) {
            $report['ok'] = false;
            $report['error'] = 'list_failed';
            $report['detail'] = $list['error'] ?? null;
        } else {
            $now = time();
            $grace = $days * 86400;
            foreach ($list['objects'] as $obj) {
                $key = (string) ($obj['key'] ?? '');
                if ($key === '' || !s3_gc_is_managed($key)) continue;
                $report['scanned']++;

                if (isset($refs[$key])) {
                    $report['referenced']++;
                    continue;
                }

                $modified = (int) ($obj['last_modified'] ?? 0);
                if ($modified <= 0 || ($now - $modified) < $grace) {
                    $report['skipped_recent']++;
                    continue;
                }

                $report['orphans']++;
                $row = [
                    'key' => $key,
                    'size' => (int) ($obj['size'] ?? 0),
                    'age_days' => (int) floor(($now - $modified) / 86400),
                ];
                if ($run) {
                    if (s3_delete_object($key)) {
                        $report['deleted']++;
                        $report['freed_bytes'] += $row['size'];
                        $row['deleted'] = true;
                    } else {
                        $report['failed']++;
                        $row['deleted'] = false;
                    }
                }
                if (count($report['objects']) < 500) $report['objects'][] = $row;
            }

            if ($run && $report['deleted'] > 0) s3_gc_invalidate_usage_cache();
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
