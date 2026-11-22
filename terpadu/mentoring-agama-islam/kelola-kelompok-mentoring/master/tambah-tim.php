<?php
/**
 * master/tambah-tim.php — Subhalaman Tambah Tim Mentoring
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));

use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterDataException;
use Mentoring\ProfilePhoto;

AdminAuth::guard();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaTim = trim($_POST['nama_tim'] ?? '');
    $mentorId = trim($_POST['mentor_id'] ?? '');

    if ($namaTim === '' || $mentorId === '') {
        header('Location: ' . $basePath . 'tambah-tim?error=wajib', true, 303);
        exit;
    }

    $newId = 'tim-' . substr(bin2hex(random_bytes(3)), 0, 6);

    $preparedLogo = null;
    if (!empty($_FILES['foto_profil']['name'])) {
        try {
            $preparedLogo = ProfilePhoto::prepareFromUpload('team', $_FILES['foto_profil']);
        } catch (\Throwable $e) {
            header('Location: ' . $basePath . 'tambah-tim?error=foto', true, 303);
            exit;
        }
    }

    $result = MasterData::transaction(static function (array $master) use ($newId, $namaTim, $mentorId, $preparedLogo): array {
        $fotoPath = null;
        $logoSha = null;
        $logoPhash = null;

        if ($preparedLogo !== null) {
            // Kerangka anti-logo-kembar antar tim
            $duplicate = ProfilePhoto::findDuplicateLogo(
                $master['teams'] ?? [],
                $preparedLogo['sha256'],
                $preparedLogo['phash']
            );
            if ($duplicate !== null) {
                throw new MasterDataException(
                    'Logo ini sudah dipakai oleh tim lain.',
                    'logo_duplikat',
                    ['tim' => (string)($duplicate['team']['nama_tim'] ?? '')]
                );
            }

            $fotoPath = ProfilePhoto::storePrepared('team', $newId, $preparedLogo['binary']);
            $logoSha = $preparedLogo['sha256'];
            $logoPhash = $preparedLogo['phash'];
        }

        $master['teams'][] = [
            'id' => $newId,
            'foto_profil' => $fotoPath,
            'logo_sha256' => $logoSha,
            'logo_phash' => $logoPhash,
            'nama_tim' => $namaTim,
            'mentor_id' => $mentorId,
            'status' => 'Aktif',
        ];

        return $master;
    }, 'tambah-tim');

    if (!$result['ok']) {
        mentoring_redirect_master_error($basePath . 'tambah-tim', $result);
    }

    header('Location: ' . $basePath . '?msg=team_added#tab-teams', true, 303);
    exit;
}

$master = MasterData::loadAll();
$errorWajib = ($_GET['error'] ?? '') === 'wajib';
$errorFoto = ($_GET['error'] ?? '') === 'foto';
$errorLogoDuplikat = ($_GET['error'] ?? '') === 'logo_duplikat';
$duplicateTeamName = trim((string)($_GET['tim'] ?? ''));
$formError = mentoring_form_error();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Tim — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Tambah Tim Mentoring</h1>
        <p class="page-subtitle">Buat kelompok mentoring baru beserta mentor pendampingnya</p>
        <div class="subpage-back">
            <a href="<?= htmlspecialchars($basePath) ?>">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if ($errorWajib): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Nama tim dan mentor pendamping wajib diisi.
        </div>
    <?php endif; ?>

    <?php if ($errorFoto): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Gambar gagal diproses. Gunakan file gambar (JPG/PNG/WEBP) maksimal 8 MB.
        </div>
    <?php endif; ?>

    <?php if ($errorLogoDuplikat): ?>
        <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b; color: #fcd34d; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Logo ini sudah dipakai<?= $duplicateTeamName !== '' ? ' oleh <b>' . htmlspecialchars($duplicateTeamName) . '</b>' : ' tim lain' ?>.
            Setiap tim harus memakai logo yang berbeda — silakan unggah logo lain.
        </div>
    <?php endif; ?>

    <?php if (!$errorWajib && !$errorFoto && !$errorLogoDuplikat && $formError !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($formError) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-section-label">Logo Kelompok</div>
            <div class="form-group">
                <label>Foto / Logo Identitas Kelompok:</label>
                <div class="photo-upload-box">
                    <div class="photo-preview" id="tim-photo-preview"></div>
                    <div class="photo-fields">
                        <input type="file" name="foto_profil" accept="image/*"
                               class="form-control" data-profile-photo
                               data-require-face="false" data-crop="contain"
                               data-preview="tim-photo-preview" data-info="tim-photo-info">
                        <div class="photo-hint" id="tim-photo-info">
                            Boleh <b>logo kelompok</b> atau foto identitas tim — tidak wajib selfie.
                            Setiap tim sebaiknya memakai logo yang berbeda. Maks. 8 MB — gambar
                            dipertahankan utuh (tidak dipotong) lalu dikompres otomatis.
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Tim Mentoring: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nama_tim" class="form-control" required placeholder="Tim Al-Fatih">
                </div>
                <div class="form-group">
                    <label>Mentor Pendamping: <span style="color:#f87171">*</span></label>
                    <select name="mentor_id" class="form-control" required>
                        <option value="">-- Pilih Mentor --</option>
                        <?php foreach ($master['mentors'] as $m): ?>
                            <option value="<?= htmlspecialchars($m['id']) ?>"><?= htmlspecialchars($m['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Simpan Tim</button>
                <a href="<?= htmlspecialchars($basePath) ?>" class="btn-cancel">Batal</a>
            </div>
        </form>
    </div>
</div>

<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/searchable-select.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select.form-control').forEach(el => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(el);
    });
});
</script>
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/profile-photo.js"></script>
</body>
</html>
