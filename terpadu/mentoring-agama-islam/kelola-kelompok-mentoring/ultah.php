<?php
/**
 * ultah.php — Subhalaman Daftar Ulang Tahun Hari Ini & Pengaturan Tunda (Suspend)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Mentoring\BirthdayReminder;
use Mentoring\MasterData;

$from = (($_GET['from'] ?? $_POST['from'] ?? '') === 'master') ? 'master' : 'form';
$backUrl = $from === 'master' ? 'master/' : './';
$backLabel = $from === 'master' ? '← Kembali ke Data Master' : '← Kembali ke Form Pelaporan';

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $suffix = $from === 'master' ? '&from=master' : '';

    if ($action === 'suspend') {
        $durationValue = (int)($_POST['duration_value'] ?? 0);
        $durationUnit = (string)($_POST['duration_unit'] ?? 'hari');
        $seconds = $durationUnit === 'jam' ? $durationValue * 3600 : $durationValue * 86400;

        if ($durationValue < 1 || $seconds > BirthdayReminder::MAX_SUSPEND_SECONDS) {
            $errorMessage = 'Durasi tunda harus antara 1 jam hingga maksimal 7 hari.';
        } else {
            BirthdayReminder::setSuspend($seconds);
            header('Location: ultah?msg=suspended' . $suffix, true, 303);
            exit;
        }
    } elseif ($action === 'unsuspend') {
        BirthdayReminder::clearSuspend();
        header('Location: ultah?msg=unsuspended' . $suffix, true, 303);
        exit;
    }
}

$msg = (string)($_GET['msg'] ?? '');
$flashMessage = match ($msg) {
    'suspended' => 'Pengingat ulang tahun berhasil ditunda.',
    'unsuspended' => 'Pengingat ulang tahun berhasil diaktifkan kembali.',
    default => '',
};

$master = MasterData::loadAll();
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$todayBirthdays = BirthdayReminder::getTodayBirthdays($master, $today);
$suspendUntil = BirthdayReminder::getSuspendUntil();

$bulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$tanggalLabel = (int)$today->format('j') . ' ' . ($bulanIndo[(int)$today->format('n')] ?? $today->format('F')) . ' ' . $today->format('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ulang Tahun Hari Ini — Mentoring MAI</title>
    <link rel="icon" type="image/jpeg" href="public/assets/img/favicon.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/style.css?v=7">
</head>
<body>
<div class="container" style="max-width: 720px;">
    <header class="page-header">
        <h1 class="page-title">🎂 Ulang Tahun Hari Ini</h1>
        <p class="page-subtitle"><?= htmlspecialchars($tanggalLabel) ?> (WIB)</p>
        <div style="margin-top: 12px;">
            <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" style="color: #38bdf8; text-decoration: none; font-size: 0.9rem; font-weight: 600;">
                <?= htmlspecialchars($backLabel) ?>
            </a>
        </div>
    </header>

    <?php if ($flashMessage !== ''): ?>
        <div style="background: rgba(16, 185, 129, 0.18); border: 1px solid #10b981; color: #34d399; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($flashMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="card-title">Daftar yang Berulang Tahun Hari Ini (<?= count($todayBirthdays) ?>)</h2>
        <?php if ($todayBirthdays === []): ?>
            <p style="color: var(--text-muted); font-size: 0.92rem;">Tidak ada mentor atau mentee aktif yang berulang tahun hari ini.</p>
        <?php else: ?>
            <div class="birthday-list">
                <?php foreach ($todayBirthdays as $person): ?>
                    <div class="birthday-item">
                        <div class="birthday-person">
                            <span class="birthday-name"><?= htmlspecialchars($person['nama_lengkap']) ?></span>
                            <span class="birthday-meta">
                                <span class="birthday-role"><?= htmlspecialchars($person['role']) ?></span>
                                <?php if ($person['nama_tim'] !== ''): ?>
                                    <span class="birthday-team"><?= htmlspecialchars($person['nama_tim']) ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <span class="birthday-age">Usia <?= (int)$person['usia'] ?> tahun</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="card-title">Tunda (Suspend) Pengingat</h2>
        <?php if ($suspendUntil !== null): ?>
            <?php
            $untilDt = (new DateTimeImmutable('@' . $suspendUntil))->setTimezone(new DateTimeZone('Asia/Jakarta'));
            $untilLabel = $untilDt->format('d/m/Y H:i') . ' WIB';
            ?>
            <div style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.4); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <span style="font-size: 0.9rem; color: #fde68a;">
                    Pengingat sedang ditunda hingga <b><?= htmlspecialchars($untilLabel) ?></b>.
                </span>
                <form method="POST" action="ultah" style="margin: 0;">
                    <input type="hidden" name="action" value="unsuspend">
                    <input type="hidden" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" style="background: #10b981; color: #0f172a; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer;">
                        Aktifkan Kembali Pengingat
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <form method="POST" action="ultah">
            <input type="hidden" name="action" value="suspend">
            <input type="hidden" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="duration_value">Lama Tunda:</label>
                    <input type="number" id="duration_value" name="duration_value" class="form-control" value="1" min="1" max="7" required>
                </div>
                <div class="form-group">
                    <label for="duration_unit">Satuan:</label>
                    <select id="duration_unit" name="duration_unit" class="form-control">
                        <option value="hari">Hari</option>
                        <option value="jam">Jam</option>
                    </select>
                </div>
            </div>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 14px;">Maksimal 7 hari (168 jam). Selama ditunda, banner pengingat tidak akan ditampilkan di browser ini.</p>
            <button type="submit" class="btn-submit" style="width: 100%; background: var(--primary); color: #fff; border: none; padding: 12px 16px; border-radius: 10px; font-weight: 700; font-size: 0.95rem; cursor: pointer;">
                Tunda Pengingat
            </button>
        </form>
    </div>
</div>
<script>
(function () {
    var unitEl = document.getElementById('duration_unit');
    var valEl = document.getElementById('duration_value');
    if (!unitEl || !valEl) return;
    function syncMax() {
        var maxVal = unitEl.value === 'jam' ? 168 : 7;
        valEl.max = String(maxVal);
        if (parseInt(valEl.value || '1', 10) > maxVal) {
            valEl.value = String(maxVal);
        }
    }
    unitEl.addEventListener('change', syncMax);
    syncMax();
})();
</script>
</body>
</html>
