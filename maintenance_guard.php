<?php
// maintenance_guard.php — otorisasi endpoint maintenance (migrasi/cleanup).
//
// Urutan izin:
//   1. CLI selalu diizinkan.
//   2. Key maintenance dari server-only config: s3_config.php -> maintenance_key.
//   3. Fallback env UKMI_MAINTENANCE_KEY.
//   4. Sesi admin yang valid (role admin + session_version cocok).
//
// Tidak ada key default yang ditanam di repo.

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/security_helper.php';

function maintenance_key()
{
    $cfg = function_exists('s3_config') ? s3_config() : null;
    $key = is_array($cfg) ? trim((string) ($cfg['maintenance_key'] ?? '')) : '';
    if ($key === '') $key = trim((string) getenv('UKMI_MAINTENANCE_KEY'));
    return $key;
}

function maintenance_authorized()
{
    if (PHP_SAPI === 'cli') return true;
    ukmi_session_start();

    $key = maintenance_key();
    $provided = (string) ($_REQUEST['k'] ?? '');
    if ($key !== '' && $provided !== '' && hash_equals($key, $provided)) return true;

    $data = store_read_json(__DIR__ . '/data.json');
    $sessionVersion = is_array($data) ? (int) ($data['session_version'] ?? 1) : 1;
    return !empty($_SESSION['admin_logged_in'])
        && ($_SESSION['role'] ?? '') === 'admin'
        && (int) ($_SESSION['session_version'] ?? 0) === $sessionVersion;
}

function maintenance_require_json()
{
    if (maintenance_authorized()) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

