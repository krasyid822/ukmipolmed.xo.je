<?php
/**
 * master/export.php — Ekspor Data Mentee ke Excel
 * Parameter: scope=ikhwan|akhwat|semua
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\ExcelExport;
use Mentoring\MasterData;

AdminAuth::guard();

$scope = strtolower(trim((string)($_GET['scope'] ?? 'semua')));
if (!in_array($scope, ['ikhwan', 'akhwat', 'semua'], true)) {
    $scope = 'semua';
}

try {
    $binary = ExcelExport::buildMenteeWorkbook(MasterData::loadAll(), $scope);
} catch (\Throwable $e) {
    error_log('Ekspor Excel gagal: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ekspor Excel gagal diproses. Silakan coba lagi.';
    exit;
}

$fileName = ExcelExport::fileName($scope);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Content-Length: ' . strlen($binary));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo $binary;
exit;
