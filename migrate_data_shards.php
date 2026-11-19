<?php
// migrate_data_shards.php — migrasi data.json tunggal ke folder data_json/.
//
// Web : migrate_data_shards?k=<maintenance_key>&action=migrate
//       migrate_data_shards?k=<maintenance_key>&action=rollback
// CLI : php migrate_data_shards.php --migrate
//       php migrate_data_shards.php --rollback
//       php migrate_data_shards.php --status
//
// Migrasi idempoten. Payload lama di S3 data/data.json TIDAK dihapus, jadi
// rollback tidak kehilangan data.

require_once __DIR__ . '/data_store.php';
require_once __DIR__ . '/maintenance_guard.php';

$isCli = PHP_SAPI === 'cli';
maintenance_require_json();

$action = $isCli
    ? (in_array('--rollback', $argv, true) ? 'rollback' : (in_array('--status', $argv, true) ? 'status' : 'migrate'))
    : (($_REQUEST['action'] ?? '') === 'rollback' ? 'rollback' : (($_REQUEST['action'] ?? '') === 'status' ? 'status' : 'migrate'));

@set_time_limit(0);
$result = ['ok' => false, 'action' => $action];

if ($action === 'rollback') {
    $result = [
        'ok' => data_store_rollback(!empty($_REQUEST['delete_manifest']) || ($isCli && in_array('--delete-manifest', $argv, true))),
        'action' => 'rollback',
    ];
} elseif ($action === 'status') {
    $local = store_read_local(__DIR__ . '/data.json');
    $manifest = data_store_manifest_read();
    $result = [
        'ok' => true,
        'action' => 'status',
        'sharded' => data_store_is_sharded(),
        'pointer_sharded' => !empty($local['_sharded']),
        'manifest' => $manifest,
        'parts' => count(data_store_posts_index_read()),
    ];
} else {
    $res = data_store_migrate();
    $result = array_merge(['action' => 'migrate'], $res);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
