<?php
/**
 * master/backup.php — Unduh backup menyeluruh sistem mentoring
 *
 * Bundel berisi pointer & objek terenkripsi data master, laporan mentoring,
 * metadata OG, media (dokumentasi & foto profil), serta state lokal.
 * Bundel ini bersifat SANGAT sensitif: jangan dibagikan ke pihak lain.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\BackupService;

AdminAuth::guardSuperAdmin();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));

if (isset($_GET['estimate'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    try {
        $hasFlag = isset($_GET['master']) || isset($_GET['reports']) || isset($_GET['media']) || isset($_GET['local']) || isset($_GET['config']);
        $options = [
            'include_master' => $hasFlag ? !empty($_GET['master']) : true,
            'include_reports' => $hasFlag ? !empty($_GET['reports']) : true,
            'include_media' => $hasFlag ? !empty($_GET['media']) : true,
            'include_local_state' => $hasFlag ? !empty($_GET['local']) : true,
            'include_config' => !empty($_GET['config']),
        ];
        echo json_encode(BackupService::estimateSize($options), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['download'])) {
    try {
        $hasFlag = isset($_GET['master']) || isset($_GET['reports']) || isset($_GET['media']) || isset($_GET['local']) || isset($_GET['config']);
        $options = [
            'include_master' => $hasFlag ? !empty($_GET['master']) : true,
            'include_reports' => $hasFlag ? !empty($_GET['reports']) : true,
            'include_media' => $hasFlag ? !empty($_GET['media']) : true,
            'include_local_state' => $hasFlag ? !empty($_GET['local']) : true,
            'include_config' => !empty($_GET['config']),
        ];

        BackupService::streamFullBackup($options);
    } catch (\Throwable $e) {
        error_log('Backup sistem gagal: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$summary = BackupService::summary();
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Sistem — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 900px;">
    <header class="page-header">
        <h1 class="page-title">Backup Menyeluruh Sistem</h1>
        <p class="page-subtitle">Unduh data master, laporan mentoring, media S3, dan state lokal dalam satu berkas</p>
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
        <h3 class="card-title">Ringkasan Data Tersimpan</h3>
        <p style="color: #94a3b8; font-size: 0.9rem; line-height: 1.7;">
            Versi Master: <b><?= (int)$summary['master_versions'] ?></b>
            (pembaruan terakhir: <b><?= $summary['master_updated_at'] !== '' ? $h($summary['master_updated_at']) : 'belum ada' ?></b>)<br>
            Laporan Mentoring: <b><?= (int)$summary['total_reports'] ?></b> laporan<br>
            Media S3: <b><?= (int)$summary['total_dokumentasi'] ?></b> dokumentasi laporan,
            <b><?= (int)$summary['total_profil'] ?></b> foto profil<br>
            State Lokal:
            biometrik (<b><?= $summary['has_biometric'] ? 'ada' : 'kosong' ?></b>),
            antrean bot (<b><?= $summary['has_bot_queue'] ? 'ada' : 'kosong' ?></b>),
            konfigurasi (<b><?= $summary['has_config'] ? 'ada' : 'kosong' ?></b>)
        </p>

        <form method="GET" action="<?= $h($basePath) ?>backup" style="margin-top: 18px;">
            <input type="hidden" name="download" value="1">
            <div style="display: grid; gap: 10px; margin-bottom: 18px; font-size: 0.9rem; color: #e2e8f0;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="master" value="1" checked>
                    <span>Data Master &amp; Riwayat Versi (mentor, tim, mentee, pendaftar)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="reports" value="1" checked>
                    <span>Laporan Mentoring &amp; Metadata OG</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="media" value="1" checked>
                    <span>Media S3 (foto dokumentasi laporan &amp; foto profil)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="local" value="1" checked>
                    <span>State Lokal (kredensial biometrik &amp; antrean bot)</span>
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #fcd34d;">
                    <input type="checkbox" name="config" value="1">
                    <span>Sertakan <code>default.json</code> (berisi kredensial S3 &amp; API key Watobot)</span>
                </label>
            </div>

            <div id="backup-size-box" style="margin-bottom: 16px; padding: 12px 14px; border-radius: 8px; background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.3); font-size: 0.88rem; color: #e2e8f0;">
                <div>Perkiraan ukuran backup: <strong id="backup-size-value">Menghitung...</strong></div>
                <div id="backup-size-detail" style="margin-top: 4px; font-size: 0.8rem; color: #94a3b8;"></div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="submit" class="btn-action">Unduh Backup Menyeluruh (.json)</button>
                <a href="<?= $h($basePath) ?>restore" class="btn-cancel">Pulihkan / Rollback</a>
            </div>
        </form>

        <div style="margin-top: 20px; padding: 14px; border-radius: 8px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.4); color: #fcd34d; font-size: 0.85rem; line-height: 1.6;">
            <b>Simpan berkas backup di tempat aman.</b> Bundel memuat kunci dekripsi data master dan laporan.
            Jangan mengunggahnya ke layanan publik atau membagikannya lewat chat.
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[action$="backup"]');
    const valueEl = document.getElementById('backup-size-value');
    const detailEl = document.getElementById('backup-size-detail');
    if (!form || !valueEl || !detailEl) return;

    const names = ['master', 'reports', 'media', 'local', 'config'];
    let controller = null;
    let timer = null;

    const updateEstimate = () => {
        if (controller) controller.abort();
        controller = new AbortController();

        const params = new URLSearchParams({ estimate: '1' });
        names.forEach((name) => {
            const cb = form.querySelector(`input[name="${name}"]`);
            params.set(name, cb && cb.checked ? '1' : '0');
        });

        valueEl.textContent = 'Menghitung...';
        detailEl.textContent = '';

        fetch(form.getAttribute('action') + '?' + params.toString(), { signal: controller.signal })
            .then((res) => res.json())
            .then((data) => {
                if (!data || !data.ok) {
                    valueEl.textContent = 'Tidak dapat dihitung';
                    detailEl.textContent = (data && data.error) ? data.error : '';
                    return;
                }
                valueEl.textContent = data.total_formatted || '0 B';
                const parts = [`${data.object_count || 0} objek S3 terukur`];
                if ((data.missing_count || 0) > 0) {
                    parts.push(`${data.missing_count} objek tidak ditemukan/dilewati`);
                }
                detailEl.textContent = parts.join(' • ');
            })
            .catch((err) => {
                if (err && err.name === 'AbortError') return;
                valueEl.textContent = 'Gagal menghitung';
            });
    };

    names.forEach((name) => {
        const cb = form.querySelector(`input[name="${name}"]`);
        if (cb) {
            cb.addEventListener('change', () => {
                clearTimeout(timer);
                timer = setTimeout(updateEstimate, 200);
            });
        }
    });

    updateEstimate();
});
</script>

</body>
</html>
