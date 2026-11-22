<?php
/**
 * master/edit-tim.php — Subhalaman Ubah Data Tim Mentoring
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterDataException;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$teamId = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));

$master = MasterData::loadAll();
$index = null;
foreach ($master['teams'] ?? [] as $i => $team) {
    if (($team['id'] ?? '') === $teamId) {
        $index = $i;
        break;
    }
}

if ($index === null) {
    header('Location: ' . $basePath . '?error=notfound#tab-teams', true, 303);
    exit;
}

$team = $master['teams'][$index];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaTim = trim($_POST['nama_tim'] ?? '');
    $mentorId = trim($_POST['mentor_id'] ?? '');

    if ($namaTim === '' || $mentorId === '') {
        header('Location: ' . $basePath . 'edit-tim?id=' . urlencode($teamId) . '&error=wajib', true, 303);
        exit;
    }

    $result = MasterData::transaction(static function (array $master) use ($teamId, $namaTim, $mentorId): array {
        $index = null;
        foreach ($master['teams'] ?? [] as $i => $team) {
            if (($team['id'] ?? '') === $teamId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            throw new MasterDataException('Tim tidak ditemukan atau sudah dihapus.', 'notfound');
        }

        $team = $master['teams'][$index];
        $team['nama_tim'] = $namaTim;
        $team['mentor_id'] = $mentorId;
        $team['status'] = trim($_POST['status'] ?? 'Aktif');
        $master['teams'][$index] = $team;

        // Sinkronkan referensi mentor pada mentee tim ini (mentor mengikuti tim)
        foreach ($master['mentees'] ?? [] as $i => $mentee) {
            if (($mentee['tim_id'] ?? '') === $teamId) {
                $master['mentees'][$i]['mentor_id'] = $mentorId;
            }
        }

        return $master;
    }, 'edit-tim');

    if (!$result['ok']) {
        mentoring_redirect_master_error($basePath . 'edit-tim?id=' . urlencode($teamId), $result);
    }

    header('Location: ' . $basePath . '?msg=team_updated#tab-teams', true, 303);
    exit;
}

$errorKey = $_GET['error'] ?? '';
$formError = mentoring_form_error();
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
$mentorIds = array_column($master['mentors'] ?? [], 'nama_lengkap', 'id');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Tim — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Ubah Data Tim Mentoring</h1>
        <p class="page-subtitle">Perbarui nama tim, logo, dan mentor pendampingnya</p>
        <div class="subpage-back">
            <a href="<?= $h($basePath) ?>#tab-teams">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if ($errorKey === 'wajib'): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Nama tim dan mentor pendamping wajib diisi.
        </div>
    <?php elseif ($formError !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($formError) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= $h($teamId) ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Tim Mentoring: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nama_tim" class="form-control" required value="<?= $h($team['nama_tim'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Mentor Pendamping: <span style="color:#f87171">*</span></label>
                    <select name="mentor_id" class="form-control" required>
                        <option value="">-- Pilih Mentor --</option>
                        <?php foreach ($mentorIds as $id => $nama): ?>
                            <option value="<?= $h($id) ?>" <?= ($team['mentor_id'] ?? '') === $id ? 'selected' : '' ?>><?= $h($nama) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status Tim:</label>
                    <select name="status" class="form-control">
                        <?php foreach (['Aktif', 'Non-aktif'] as $status): ?>
                            <option value="<?= $h($status) ?>" <?= ($team['status'] ?? 'Aktif') === $status ? 'selected' : '' ?>><?= $h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Simpan Perubahan</button>
                <a href="<?= $h($basePath) ?>#tab-teams" class="btn-cancel">Batal</a>
            </div>
        </form>
    </div>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select.form-control').forEach(el => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(el);
    });
});
</script>
</body>
</html>
