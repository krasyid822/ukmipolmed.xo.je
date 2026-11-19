<?php
// s3_gate.php — GATE: uji outbound + public-read ke Blomp S3.
// Akses web wajib ?k=UKMIrestore2026. CLI (uji lokal) dikecualikan.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/s3_helper.php';

if (PHP_SAPI !== 'cli' && (($_REQUEST['k'] ?? '') !== 'UKMIrestore2026')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$available = s3_available();
if (!$available) {
    echo json_encode(['ok' => false, 'reason' => 's3_not_available', 'curl' => function_exists('curl_init'), 'hmac' => function_exists('hash_hmac'), 'config' => (s3_config() !== null)]);
    exit;
}

$body = 'gate-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
$dbg = null;
$url = s3_put_binary($body, s3_prefix() . 'assets/_gate.txt', 'text/plain', $dbg);
if (!$url) {
    echo json_encode(['ok' => false, 'reason' => 'put_failed', 'put' => $dbg]);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$gerr = curl_error($ch);
curl_close($ch);

$sg = s3_get_object(s3_prefix() . 'assets/_gate.txt');
$storageOk = (($dbg['code'] ?? 0) >= 200 && ($dbg['code'] ?? 0) < 300 && ($sg['code'] ?? 0) === 200 && ($sg['body'] ?? null) === $body);

echo json_encode([
    'ok' => $storageOk,
    'storage_ok' => $storageOk,
    'url' => $url,
    'put' => $dbg,
    'signed_get_code' => $sg['code'],
    'signed_match' => ($sg['body'] === $body),
    'public_get_code' => $code,
    'public_note' => 'public GET anonim ditolak Blomp (diharapkan); gambar disajikan via /foto',
    'available' => $available,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
