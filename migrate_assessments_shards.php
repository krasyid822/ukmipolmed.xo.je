<?php
// migrate_assessments_shards.php — pecah assessments.json ke assessments_json/.
//
// Web : migrate_assessments_shards?k=<maintenance_key>&action=migrate
// CLI : php migrate_assessments_shards.php --migrate
//       php migrate_assessments_shards.php --status
//       php migrate_assessments_shards.php --rollback
//
// Payload lama data/assessments.json tidak dihapus; rollback menulis kondisi
// terbaru ke sana lebih dulu.

require_once __DIR__ . '/assessments_store.php';
require_once __DIR__ . '/maintenance_guard.php';

$isCli = PHP_SAPI === 'cli';
maintenance_require_json();

$action = $isCli
    ? (in_array('--rollback', $argv, true) ? 'rollback' : (in_array('--status', $argv, true) ? 'status' : 'migrate'))
    : (($_REQUEST['action'] ?? '') === 'rollback' ? 'rollback' : (($_REQUEST['action'] ?? '') === 'status' ? 'status' : 'migrate'));

@set_time_limit(0);
if ($action === 'rollback') {
    $result = ['action' => 'rollback', 'ok' => assessments_store_rollback(!empty($_REQUEST['delete_manifest']) || ($isCli && in_array('--delete-manifest', $argv, true)))];
} elseif ($action === 'status') {
    $local = store_read_local(__DIR__ . '/assessments.json');
    $result = [
        'ok' => true,
        'action' => 'status',
        'sharded' => assessments_store_is_sharded(),
        'pointer_sharded' => !empty($local['_sharded']),
        'manifest' => assessments_store_manifest_read(),
        'assessments' => count(assessments_store_index_read()),
    ];
} else {
    $result = array_merge(['action' => 'migrate'], assessments_store_migrate());
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
