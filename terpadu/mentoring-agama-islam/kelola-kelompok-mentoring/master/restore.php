<?php
/**
 * master/restore.php — Pulihkan data master dari bundel backup atau rollback
 * ke versi sebelumnya pada riwayat pointer.
 *
 * Kedua jalur selalu menulis sebagai VERSI BARU; tidak ada versi lama yang
 * dihapus, sehingga pemulihan yang salah pun masih bisa dibatalkan.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\BackupService;
use Mentoring\MasterData;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$pageUrl = $basePath . 'restore';
$message = '';
$error = '';

$iniBytes = static function (string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int)$value;
    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
};

$uploadLimits = array_filter([
    $iniBytes((string)ini_get('upload_max_filesize')),
    $iniBytes((string)ini_get('post_max_size')),
    50 * 1024 * 1024,
]);
$maxUploadBytes = $uploadLimits === [] ? 50 * 1024 * 1024 : min($uploadLimits);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        @set_time_limit(0);

        if ($action === 'rollback') {
            $revision = trim((string)($_POST['revision'] ?? ''));
            if ($revision === '') {
                $error = 'Versi yang dipilih tidak valid.';
            } else {
                $result = MasterData::rollbackTo($revision);
                if ($result['ok']) {
                    header('Location: ' . $pageUrl . '?msg=rollback', true, 303);
                    exit;
                }
                $error = (string)($result['error'] ?? 'Rollback gagal.');
            }
        } elseif ($action === 'restore') {
            $file = $_FILES['bundle'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Pilih berkas backup (.json) terlebih dahulu.';
            } elseif ((int)$file['size'] > $maxUploadBytes) {
                $error = 'Berkas backup melebihi batas unggah server (' . number_format($maxUploadBytes / 1048576, 1) . ' MB).';
            } else {
                $raw = @file_get_contents((string)$file['tmp_name']);
                $bundle = is_string($raw) ? json_decode($raw, true) : null;
                unset($raw);

                if (!is_array($bundle)) {
                    $error = 'Berkas bukan JSON bundel backup yang valid.';
                } else {
                    $result = BackupService::restoreBundle($bundle, [
                        'replace_reports' => !empty($_POST['replace_reports']),
                        'restore_config' => !empty($_POST['restore_config']),
                    ]);
                    if ($result['ok']) {
                        $sum = $result['summary'] ?? [];
                        $qs = http_build_query([
                            'msg' => 'restored',
                            'm' => (int)($sum['master'] ?? 0),
                            'r' => (int)($sum['reports'] ?? 0),
                            'med' => (int)($sum['media'] ?? 0),
                            'loc' => (int)($sum['local_state'] ?? 0),
                        ]);
                        header('Location: ' . $pageUrl . '?' . $qs, true, 303);
                        exit;
                    }
                    $error = (string)($result['error'] ?? 'Pemulihan gagal.');
                }
            }
        } else {
            $error = 'Aksi tidak dikenali.';
        }
    } catch (\Throwable $e) {
        error_log('Restore master gagal: ' . $e->getMessage());
        $error = 'Pemulihan gagal: ' . $e->getMessage();
    }
}

$msgKey = (string)($_GET['msg'] ?? '');
if ($msgKey === 'restored') {
    $mCount = (int)($_GET['m'] ?? 0);
    $rCount = (int)($_GET['r'] ?? 0);
    $medCount = (int)($_GET['med'] ?? 0);
    $locCount = (int)($_GET['loc'] ?? 0);
    if ($rCount > 0 || $medCount > 0 || $locCount > 0) {
        $message = sprintf(
            'Pemulihan selesai: %d master, %d laporan, %d media, %d state lokal.',
            $mCount,
            $rCount,
            $medCount,
            $locCount
        );
    } else {
        $message = 'Data master berhasil dipulihkan dari berkas backup sebagai versi baru.';
    }
} elseif ($msgKey === 'rollback') {
    $message = 'Data master berhasil dikembalikan ke versi yang dipilih.';
}

try {
    $history = MasterData::history();
    $snapshot = MasterData::loadSnapshot();
    $loadError = $snapshot['ok'] ? '' : $snapshot['reason'];
} catch (\Throwable $e) {
    $history = [];
    $loadError = $e->getMessage();
}

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pulihkan Master — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Pulihkan Data Master</h1>
        <p class="page-subtitle">Rollback ke versi sebelumnya atau pulihkan dari berkas backup</p>
        <div class="subpage-back">
            <a href="<?= $h($basePath) ?>">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= $h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= $h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($loadError !== ''): ?>
        <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b; color: #fcd34d; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Master aktif tidak dapat dimuat: <?= $h($loadError) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 20px;">
        <h3 class="card-title">Pulihkan dari Berkas Backup</h3>
        <p style="color: #94a3b8; font-size: 0.88rem; line-height: 1.6;">
            Berkas <code>.json</code> hasil tombol &ldquo;Unduh Backup&rdquo;. Isi bundel diverifikasi
            checksum dan didekripsi terlebih dahulu; bila gagal, data aktif tidak disentuh.
        </p>
        <form method="POST" enctype="multipart/form-data" style="margin-top: 14px;">
            <input type="hidden" name="action" value="restore">
            <div class="form-group">
                <label>Berkas Backup:</label>
                <input type="file" name="bundle" accept="application/json,.json" class="form-control" required>
            </div>
            <div style="display: grid; gap: 8px; margin: 12px 0 16px; font-size: 0.88rem; color: #cbd5e1;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="replace_reports" value="1">
                    <span>Ganti seluruh daftar laporan (jika tidak dicentang, laporan digabung/merge secara aman)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #fcd34d;">
                    <input type="checkbox" name="restore_config" value="1">
                    <span>Pulihkan juga konfigurasi <code>default.json</code> (kredensial S3 &amp; API key) jika ada di bundel</span>
                </label>
            </div>
            <button type="submit" class="btn-action">Pulihkan Data dari Backup</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">Riwayat Versi (Rollback)</h3>
        <?php if ($history === []): ?>
            <p style="color: #94a3b8; font-size: 0.9rem;">Belum ada versi master yang tersimpan.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="attendance-table" style="width: 100%; min-width: 640px; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="text-align: left; color: #94a3b8;">
                            <th style="padding: 8px 6px; white-space: nowrap;">Waktu</th>
                            <th style="padding: 8px 6px; white-space: nowrap;">Sumber</th>
                            <th style="padding: 8px 6px; white-space: nowrap;">Ringkasan</th>
                            <th style="padding: 8px 6px; white-space: nowrap;">Status</th>
                            <th style="padding: 8px 6px; white-space: nowrap;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <?php
                            $summary = is_array($entry['summary'] ?? null) ? $entry['summary'] : [];
                            $summaryText = sprintf(
                                '%d mentor, %d tim, %d mentee, %d pendaftar',
                                (int)($summary['total_mentors'] ?? 0),
                                (int)($summary['total_teams'] ?? 0),
                                (int)($summary['total_mentees'] ?? 0),
                                (int)($summary['total_registrants'] ?? 0)
                            );
                            $isCurrent = !empty($entry['is_current']);
                            $revision = (string)($entry['revision'] ?? '');
                            ?>
                            <tr style="border-top: 1px solid rgba(148, 163, 184, 0.2);">
                                <td style="padding: 8px 6px; white-space: nowrap;"><?= $h($entry['updated_at'] ?? '-') ?></td>
                                <td style="padding: 8px 6px; white-space: nowrap;"><?= $h($entry['source'] ?? '-') ?></td>
                                <td style="padding: 8px 6px;"><?= $h($summaryText) ?></td>
                                <td style="padding: 8px 6px; white-space: nowrap;"><?= $isCurrent ? '<b style="color:#34d399">Aktif</b>' : 'Arsip' ?></td>
                                <td style="padding: 8px 6px; white-space: nowrap;">
                                    <?php if ($revision !== '' && !$isCurrent): ?>
                                        <form method="POST" data-confirm="Kembalikan data master ke versi ini? Versi saat ini tetap tersimpan." data-confirm-title="Rollback Data Master" style="margin: 0;">
                                            <input type="hidden" name="action" value="rollback">
                                            <input type="hidden" name="revision" value="<?= $h($revision) ?>">
                                            <button type="submit" class="btn-cancel">Rollback</button>
                                        </form>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/modal.js?v=3"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirmed === '1') {
                return;
            }
            event.preventDefault();
            const message = form.getAttribute('data-confirm') || 'Lanjutkan tindakan ini?';
            const title = form.getAttribute('data-confirm-title') || 'Konfirmasi';
            const proceed = () => {
                form.dataset.confirmed = '1';
                if (typeof window.ukmiShowLoadingOverlay === 'function') {
                    window.ukmiShowLoadingOverlay('Memproses data, mohon tunggu...');
                }
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            };
            if (window.Modal && typeof window.Modal.confirm === 'function') {
                window.Modal.confirm(message, title).then((ok) => {
                    if (ok) proceed();
                });
            } else if (window.confirm(message)) {
                proceed();
            }
        });
    });
});
</script>
</body>
</html>
