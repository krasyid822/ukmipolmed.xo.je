<?php
/**
 * master/backup.php — Unduh backup penuh data master
 *
 * Bundel berisi pointer master (termasuk kunci enkripsi) dan seluruh objek
 * versi terenkripsi. Bundel ini bersifat SANGAT sensitif:
 * siapa pun yang memegangnya dapat membaca seluruh data master.
 *
 * Objek versi ditulis secara streaming satu per satu agar backup riwayat
 * panjang tidak menghabiskan memori pada hosting bersama.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\Config;
use Mentoring\JsonFile;
use Mentoring\MasterData;
use Mentoring\MasterSchema;
use Mentoring\S3Client;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));

if (isset($_GET['download'])) {
    try {
        @set_time_limit(0);

        $pointer = MasterData::currentPointer();
        if ($pointer === null) {
            throw new \RuntimeException('Belum ada data master yang tersimpan untuk dibackup.');
        }

        $currentPath = (string)($pointer['s3_path'] ?? '');
        if ($currentPath === '') {
            throw new \RuntimeException('Pointer master tidak memuat lokasi objek terbaru.');
        }

        $s3 = new S3Client();
        $currentRaw = $s3->getObject($currentPath);
        if ($currentRaw === null) {
            throw new \RuntimeException('Objek master terbaru tidak dapat diunduh; backup dibatalkan.');
        }

        $history = MasterData::history();

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $encode = static fn($value): string => (string)json_encode($value, $flags);

        $filename = 'master-backup-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, private');

        echo '{"format":"mentoring-master-backup","format_version":1';
        echo ',"created_at":' . $encode(date('c'));
        echo ',"schema_version":' . MasterSchema::VERSION;
        echo ',"app":"kelola-kelompok-mentoring"';
        echo ',"pointer":' . $encode($pointer);
        echo ',"history":' . $encode($history);
        echo ',"notes":' . $encode(
            'Bundel ini memuat pointer beserta kunci enkripsi dan ciphertext master. '
            . 'Jangan dibagikan. Pulihkan lewat master/restore.php.'
        );

        echo ',"objects":{';
        $checksums = [];
        $missing = [];
        $first = true;

        foreach ($history as $entry) {
            $path = (string)($entry['s3_path'] ?? '');
            if ($path === '' || isset($checksums[$path])) {
                continue;
            }

            $raw = $path === $currentPath ? $currentRaw : $s3->getObject($path);
            if ($raw === null) {
                $missing[] = $path;
                continue;
            }

            $checksums[$path] = hash('sha256', $raw);
            echo ($first ? '' : ',') . $encode($path) . ':' . $encode(base64_encode($raw));
            $first = false;
        }

        echo '}';
        echo ',"checksums":' . $encode((object)$checksums);
        echo ',"missing_objects":' . $encode($missing);
        echo '}';
        exit;
    } catch (\Throwable $e) {
        error_log('Backup master gagal: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

try {
    $history = MasterData::history();
} catch (\Throwable $e) {
    $history = [];
}

$lastUpdated = $history[0]['updated_at'] ?? '';
$totalVersions = count($history);
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Master — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 900px;">
    <header class="page-header">
        <h1 class="page-title">Backup Penuh Data Master</h1>
        <p class="page-subtitle">Unduh pointer, kunci enkripsi, dan seluruh versi data master dalam satu berkas</p>
        <div class="subpage-back">
            <a href="<?= $h($basePath) ?>">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if (isset($error)): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= $h($error) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3 class="card-title">Ringkasan Penyimpanan</h3>
        <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.6;">
            Versi tersimpan: <b><?= (int)$totalVersions ?></b><br>
            Pembaruan terakhir: <b><?= $lastUpdated !== '' ? $h($lastUpdated) : 'belum ada' ?></b>
        </p>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px;">
            <a href="<?= $h($basePath) ?>backup?download=1" class="btn-action">Unduh Backup (.json)</a>
            <a href="<?= $h($basePath) ?>restore" class="btn-cancel">Pulihkan / Rollback</a>
        </div>

        <div style="margin-top: 20px; padding: 14px; border-radius: 8px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.4); color: #fcd34d; font-size: 0.85rem; line-height: 1.6;">
            <b>Simpan berkas backup di tempat aman.</b> Bundel memuat kunci dekripsi master data.
            Jangan mengunggahnya ke layanan publik atau membagikannya lewat chat.
        </div>
    </div>
</div>

</body>
</html>
