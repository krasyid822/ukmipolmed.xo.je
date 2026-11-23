<?php
/**
 * master/edit-mentee.php — Subhalaman Ubah Biodata & Status Mentee
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterDataException;
use Mentoring\MasterSchema;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$menteeId = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));

$master = MasterData::loadAll();
$index = null;
foreach ($master['mentees'] ?? [] as $i => $mentee) {
    if (($mentee['id'] ?? '') === $menteeId) {
        $index = $i;
        break;
    }
}

if ($index === null) {
    header('Location: ' . $basePath . '?error=notfound#tab-mentees', true, 303);
    exit;
}

$mentee = $master['mentees'][$index];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
    $nomorWa = trim($_POST['nomor_whatsapp'] ?? '');
    $timId = trim($_POST['tim_id'] ?? '');

    if ($namaLengkap === '' || $nomorWa === '' || $timId === '') {
        header('Location: ' . $basePath . 'edit-mentee?id=' . urlencode($menteeId) . '&error=wajib', true, 303);
        exit;
    }

    $result = MasterData::transaction(static function (array $master) use ($menteeId, $namaLengkap, $nomorWa, $timId): array {
        $index = null;
        foreach ($master['mentees'] ?? [] as $i => $mentee) {
            if (($mentee['id'] ?? '') === $menteeId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            throw new MasterDataException('Mentee tidak ditemukan atau sudah dihapus.', 'notfound');
        }

        // Mentor mengikuti tim yang dipilih (menjaga integritas referensi)
        $mentorId = '';
        foreach ($master['teams'] ?? [] as $team) {
            if (($team['id'] ?? '') === $timId) {
                $mentorId = (string)($team['mentor_id'] ?? '');
                break;
            }
        }

        $mentee = $master['mentees'][$index];
        $mentee['nama_lengkap'] = $namaLengkap;
        $mentee['nomor_whatsapp'] = $nomorWa;
        $mentee['tim_id'] = $timId;
        $mentee['mentor_id'] = $mentorId;
        $mentee['gender'] = trim($_POST['gender'] ?? 'Laki-laki');
        $mentee['tempat_lahir'] = MasterSchema::normalizeName((string)($_POST['tempat_lahir'] ?? ''));
        $mentee['tanggal_lahir'] = trim($_POST['tanggal_lahir'] ?? '');
        $mentee['kelas'] = Academic::normalizeClass($_POST['kelas'] ?? '');
        $mentee['nim'] = trim($_POST['nim'] ?? '');
        $mentee['jadwal_kuliah'] = trim($_POST['jadwal_kuliah'] ?? '');
        $mentee['alamat_sekarang'] = MasterSchema::normalizeName((string)($_POST['alamat_sekarang'] ?? ''));
        $mentee['alamat_orang_tua'] = MasterSchema::normalizeName((string)($_POST['alamat_orang_tua'] ?? ''));
        $mentee['amanah_organisasi'] = trim($_POST['amanah_organisasi'] ?? 'Tidak Ada');

        $status = trim($_POST['status_keaktifan'] ?? 'Aktif');
        $mentee['status_keaktifan'] = in_array($status, ['Aktif', 'Peringatan', 'Tidak Aktif'], true) ? $status : 'Aktif';

        if ($mentee['status_keaktifan'] === 'Aktif') {
            $mentee['consecutive_absent'] = 0;
            $mentee['tidak_jelas_kabarnya'] = false;
        }

        $master['mentees'][$index] = $mentee;
        return $master;
    }, 'edit-mentee');

    if (!$result['ok']) {
        mentoring_redirect_master_error($basePath . 'edit-mentee?id=' . urlencode($menteeId), $result);
    }

    header('Location: ' . $basePath . '?msg=mentee_updated#tab-mentees', true, 303);
    exit;
}

$errorKey = $_GET['error'] ?? '';
$formError = mentoring_form_error();
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
$semesterAkademik = Academic::semesterFromNim($mentee['nim'] ?? '');
$stambukAkademik = Academic::entryYearFromNim($mentee['nim'] ?? '');
$timIds = array_column($master['teams'] ?? [], 'nama_tim', 'id');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Mentee — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container master-subpage" style="max-width: 960px;">
    <header class="page-header">
        <div>
            <h1 class="page-title">Ubah Data Mentee</h1>
            <p class="page-subtitle">Perbarui biodata, kelompok, dan status keaktifan mentee</p>
        </div>
        <div class="subpage-back">
            <a href="<?= $h($basePath) ?>#tab-mentees">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if ($errorKey === 'wajib'): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Nama lengkap, Nomor WhatsApp, dan Kelompok / Tim wajib diisi.
        </div>
    <?php elseif ($formError !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($formError) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= $h($menteeId) ?>">
            <div class="master-form-split">
            <div class="master-form-col">
            <div class="form-section-label">Identitas Wajib</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Lengkap Mentee: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nama_lengkap" class="form-control" required value="<?= $h($mentee['nama_lengkap'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Nomor WhatsApp: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nomor_whatsapp" class="form-control" required value="<?= $h($mentee['nomor_whatsapp'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Kelompok / Tim: <span style="color:#f87171">*</span></label>
                    <select name="tim_id" class="form-control" required>
                        <option value="">-- Pilih Tim --</option>
                        <?php foreach ($timIds as $id => $nama): ?>
                            <option value="<?= $h($id) ?>" <?= ($mentee['tim_id'] ?? '') === $id ? 'selected' : '' ?>><?= $h($nama) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status Keaktifan:</label>
                    <select name="status_keaktifan" class="form-control">
                        <?php foreach (['Aktif', 'Peringatan', 'Tidak Aktif'] as $status): ?>
                            <option value="<?= $h($status) ?>" <?= ($mentee['status_keaktifan'] ?? 'Aktif') === $status ? 'selected' : '' ?>><?= $h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            </div>
            <div class="master-form-col">
            <div class="form-section-label">Biodata</div>
            <div class="form-grid cols-3">
                <div class="form-group">
                    <label>Gender:</label>
                    <select name="gender" class="form-control">
                        <?php foreach (['Laki-laki', 'Perempuan'] as $gender): ?>
                            <option value="<?= $h($gender) ?>" <?= ($mentee['gender'] ?? '') === $gender ? 'selected' : '' ?>><?= $h($gender) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tempat Lahir:</label>
                    <input type="text" name="tempat_lahir" class="form-control" value="<?= $h($mentee['tempat_lahir'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Tanggal Lahir:</label>
                    <input type="date" name="tanggal_lahir" class="form-control" value="<?= $h($mentee['tanggal_lahir'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>NIM:</label>
                    <input type="text" name="nim" class="form-control" value="<?= $h($mentee['nim'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Kelas:</label>
                    <input type="text" name="kelas" class="form-control" value="<?= $h(Academic::normalizeClass($mentee['kelas'] ?? '')) ?>" placeholder="TRPL-A">
                    <div class="photo-hint">
                        <?php if ($semesterAkademik !== null): ?>
                            Semester saat ini: <b><?= $h($semesterAkademik) ?></b> (stambuk <?= $h($stambukAkademik) ?>).
                        <?php else: ?>
                            Cukup program studi + kelas, contoh <b>TRPL-A</b>. Semester dihitung otomatis dari dua digit awal NIM.
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>Jadwal Kuliah:</label>
                    <select name="jadwal_kuliah" class="form-control">
                        <?php foreach (['Pagi', 'Sore', 'Tidak Menentu'] as $jadwal): ?>
                            <option value="<?= $h($jadwal) ?>" <?= (($mentee['jadwal_kuliah'] ?? '') ?: 'Tidak Menentu') === $jadwal ? 'selected' : '' ?>><?= $h($jadwal) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-section-label">Domisili &amp; Organisasi</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Alamat Sekarang:</label>
                    <input type="text" name="alamat_sekarang" class="form-control" value="<?= $h($mentee['alamat_sekarang'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Alamat Orang Tua:</label>
                    <input type="text" name="alamat_orang_tua" class="form-control" value="<?= $h($mentee['alamat_orang_tua'] ?? '') ?>">
                </div>
                <div class="form-group col-span-2">
                    <label>Amanah Organisasi:</label>
                    <input type="text" name="amanah_organisasi" class="form-control" value="<?= $h($mentee['amanah_organisasi'] ?? '') ?>">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Simpan Perubahan</button>
                <a href="<?= $h($basePath) ?>#tab-mentees" class="btn-cancel">Batal</a>
            </div>
            </div>
            </div>
        </form>
    </div>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
<script>
function getColContentHeight(col, actions) {
    let height = 0;
    const colTop = col.getBoundingClientRect().top;
    for (const child of col.children) {
        if (child === actions || child.style.display === 'none') continue;
        const rect = child.getBoundingClientRect();
        const bottom = rect.bottom - colTop;
        if (bottom > height) height = bottom;
    }
    return height;
}

function relocateFormActions() {
    const split = document.querySelector('.master-form-split');
    const actions = split ? split.querySelector('.form-actions') : null;
    if (!split || !actions) return;
    const cols = split.querySelectorAll('.master-form-col');
    if (cols.length < 2) return;
    const [leftCol, rightCol] = cols;

    if (window.innerWidth < 1024) {
        if (actions.parentElement !== rightCol) rightCol.appendChild(actions);
        return;
    }

    const leftHeight = getColContentHeight(leftCol, actions);
    const rightHeight = getColContentHeight(rightCol, actions);

    if (rightHeight > leftHeight) {
        if (actions.parentElement !== leftCol) leftCol.appendChild(actions);
    } else {
        if (actions.parentElement !== rightCol) rightCol.appendChild(actions);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select.form-control').forEach(el => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(el);
    });
    relocateFormActions();
    window.addEventListener('resize', relocateFormActions);
    const toTitleCase = (val) => val.toLowerCase().replace(/(^|[\s\-'.,\/()])(\S)/g, (_, sep, ch) => sep + ch.toUpperCase());
    ['tempat_lahir', 'alamat_sekarang', 'alamat_orang_tua'].forEach((name) => {
        const el = document.querySelector(`input[name="${name}"]`);
        if (!el) return;
        el.addEventListener('input', () => {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const formatted = toTitleCase(el.value);
            if (el.value !== formatted) {
                el.value = formatted;
                if (start !== null && end !== null) el.setSelectionRange(start, end);
            }
        });
        el.addEventListener('blur', () => {
            el.value = toTitleCase(el.value).replace(/\s+/g, ' ').trim();
        });
    });
});
</script>
</body>
</html>
