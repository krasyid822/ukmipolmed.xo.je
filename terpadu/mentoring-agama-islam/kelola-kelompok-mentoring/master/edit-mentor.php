<?php
/**
 * master/edit-mentor.php — Subhalaman Ubah Biodata Mentor
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterDataException;
use Mentoring\MasterSchema;
use Mentoring\Biometric;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$mentorId = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));

$master = MasterData::loadAll();
$index = null;
foreach ($master['mentors'] ?? [] as $i => $mentor) {
    if (($mentor['id'] ?? '') === $mentorId) {
        $index = $i;
        break;
    }
}

if ($index === null) {
    header('Location: ' . $basePath . '?error=notfound#tab-mentors', true, 303);
    exit;
}

$mentor = $master['mentors'][$index];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
    $nomorWa = trim($_POST['nomor_whatsapp'] ?? '');

    if ($namaLengkap === '' || $nomorWa === '') {
        header('Location: ' . $basePath . 'edit-mentor?id=' . urlencode($mentorId) . '&error=wajib', true, 303);
        exit;
    }

    $result = MasterData::transaction(static function (array $master) use ($mentorId, $namaLengkap, $nomorWa): array {
        $index = null;
        foreach ($master['mentors'] ?? [] as $i => $mentor) {
            if (($mentor['id'] ?? '') === $mentorId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            throw new MasterDataException('Mentor tidak ditemukan atau sudah dihapus.', 'notfound');
        }

        $mentor = $master['mentors'][$index];
        $mentor['nama_lengkap'] = $namaLengkap;
        $mentor['nomor_whatsapp'] = $nomorWa;
        $mentor['gender'] = trim($_POST['gender'] ?? 'Laki-laki');
        $mentor['tempat_lahir'] = MasterSchema::normalizeName((string)($_POST['tempat_lahir'] ?? ''));
        $mentor['tanggal_lahir'] = trim($_POST['tanggal_lahir'] ?? '');
        $mentor['status_kesibukan'] = trim($_POST['status_kesibukan'] ?? 'Kuliah');
        $mentor['detail_kesibukan'] = array_merge($mentor['detail_kesibukan'] ?? [], [
            'instansi_perusahaan' => trim($_POST['instansi_perusahaan'] ?? ''),
            'profesi_jabatan' => trim($_POST['profesi_jabatan'] ?? ''),
            'jadwal_kerja' => trim($_POST['jadwal_kerja'] ?? ''),
            'nim' => trim($_POST['nim'] ?? ''),
            'kelas' => Academic::normalizeClass($_POST['kelas'] ?? ''),
            'jadwal_kuliah' => trim($_POST['jadwal_kuliah_mentor'] ?? ''),
        ]);
        $mentor['alamat_sekarang'] = MasterSchema::normalizeName((string)($_POST['alamat_sekarang'] ?? ''));
        $mentor['alamat_orang_tua'] = MasterSchema::normalizeName((string)($_POST['alamat_orang_tua'] ?? ''));
        $mentor['amanah_organisasi'] = trim($_POST['amanah_organisasi'] ?? 'Tidak Ada');
        $mentor['status'] = trim($_POST['status'] ?? 'Aktif');

        $master['mentors'][$index] = $mentor;
        return $master;
    }, 'edit-mentor');

    if (!$result['ok']) {
        mentoring_redirect_master_error($basePath . 'edit-mentor?id=' . urlencode($mentorId), $result);
    }

    // Atur mentee perwakilan (boleh kosong untuk membatalkan)
    $delegateMenteeId = trim($_POST['delegate_mentee_id'] ?? '');
    Biometric::setDelegate($mentorId, $delegateMenteeId !== '' ? $delegateMenteeId : null);

    header('Location: ' . $basePath . '?msg=mentor_updated#tab-mentors', true, 303);
    exit;
}

$errorKey = $_GET['error'] ?? '';
$formError = mentoring_form_error();
$detail = is_array($mentor['detail_kesibukan'] ?? null) ? $mentor['detail_kesibukan'] : [];
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);

// Mentee yang bisa diangkat sebagai perwakilan (milih dari mentee milik mentor ini)
$menteesOfMentor = [];
foreach ($master['mentees'] ?? [] as $m) {
    if ((string)($m['mentor_id'] ?? '') === $mentorId) {
        $menteesOfMentor[] = $m;
    }
}
$currentDelegate = null;
foreach ($menteesOfMentor as $m) {
    if (!empty($m['delegate_for'] ?? '')) {
        $currentDelegate = $m;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Mentor — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Ubah Data Mentor</h1>
        <p class="page-subtitle">Perbarui biodata mentor pendamping mentoring</p>
        <div class="subpage-back">
            <a href="<?= $h($basePath) ?>#tab-mentors">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if ($errorKey === 'wajib'): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Nama lengkap dan Nomor WhatsApp wajib diisi.
        </div>
    <?php elseif ($formError !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($formError) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= $h($mentorId) ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Lengkap &amp; Gelar: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nama_lengkap" class="form-control" required value="<?= $h($mentor['nama_lengkap'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Nomor WhatsApp: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nomor_whatsapp" class="form-control" required value="<?= $h($mentor['nomor_whatsapp'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Gender:</label>
                    <select name="gender" class="form-control">
                        <?php foreach (['Laki-laki', 'Perempuan'] as $gender): ?>
                            <option value="<?= $h($gender) ?>" <?= ($mentor['gender'] ?? '') === $gender ? 'selected' : '' ?>><?= $h($gender) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tempat Lahir:</label>
                    <input type="text" name="tempat_lahir" class="form-control" value="<?= $h($mentor['tempat_lahir'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Tanggal Lahir:</label>
                    <input type="date" name="tanggal_lahir" class="form-control" value="<?= $h($mentor['tanggal_lahir'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Status Kesibukan:</label>
                    <select name="status_kesibukan" class="form-control" id="mentor-status-kesibukan" onchange="toggleDetailKesibukan()">
                        <?php foreach (['Kuliah', 'Bekerja', 'Kuliah & Bekerja'] as $kesibukan): ?>
                            <option value="<?= $h($kesibukan) ?>" <?= ($mentor['status_kesibukan'] ?? '') === $kesibukan ? 'selected' : '' ?>><?= $h($kesibukan) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status Keanggotaan:</label>
                    <select name="status" class="form-control">
                        <?php foreach (['Aktif', 'Non-aktif'] as $status): ?>
                            <option value="<?= $h($status) ?>" <?= ($mentor['status'] ?? 'Aktif') === $status ? 'selected' : '' ?>><?= $h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="detail-kuliah">
                <div class="form-section-label">Detail Perkuliahan</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>NIM:</label>
                        <input type="text" name="nim" class="form-control" value="<?= $h($detail['nim'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Kelas:</label>
                        <input type="text" name="kelas" class="form-control" value="<?= $h(Academic::normalizeClass($detail['kelas'] ?? '')) ?>" placeholder="TRPL-A">
                        <?php $semesterAkademik = Academic::semesterFromNim($detail['nim'] ?? ''); $stambukAkademik = Academic::entryYearFromNim($detail['nim'] ?? ''); ?>
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
                        <select name="jadwal_kuliah_mentor" class="form-control">
                            <?php foreach (['Pagi', 'Sore', 'Tidak Menentu'] as $jadwal): ?>
                                <option value="<?= $h($jadwal) ?>" <?= ($detail['jadwal_kuliah'] ?? '') === $jadwal ? 'selected' : '' ?>><?= $h($jadwal) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div id="detail-bekerja">
                <div class="form-section-label">Detail Pekerjaan</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Instansi / Perusahaan:</label>
                        <input type="text" name="instansi_perusahaan" class="form-control" value="<?= $h($detail['instansi_perusahaan'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Profesi / Jabatan:</label>
                        <input type="text" name="profesi_jabatan" class="form-control" value="<?= $h($detail['profesi_jabatan'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Jadwal Kerja:</label>
                        <input type="text" name="jadwal_kerja" class="form-control" value="<?= $h($detail['jadwal_kerja'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section-label">Domisili &amp; Organisasi</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Alamat Sekarang:</label>
                    <input type="text" name="alamat_sekarang" class="form-control" value="<?= $h($mentor['alamat_sekarang'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Alamat Orang Tua:</label>
                    <input type="text" name="alamat_orang_tua" class="form-control" value="<?= $h($mentor['alamat_orang_tua'] ?? '') ?>">
                </div>
                <div class="form-group col-span-2">
                    <label>Amanah Organisasi:</label>
                    <input type="text" name="amanah_organisasi" class="form-control" value="<?= $h($mentor['amanah_organisasi'] ?? '') ?>">
                </div>
            </div>

            <div class="form-section-label">Mentee Perwakilan (Penginput Laporan)</div>
            <div class="form-grid">
                <div class="form-group col-span-2">
                    <label>Mentee yang diizinkan menginput laporan atas nama mentor:</label>
                    <select name="delegate_mentee_id" class="form-control">
                        <option value="">-- Tidak ada perwakilan --</option>
                        <?php foreach ($menteesOfMentor as $m): ?>
                            <option value="<?= $h($m['id']) ?>" <?= ($currentDelegate !== null && (string)($m['id'] ?? '') === (string)($currentDelegate['id'] ?? '')) ? 'selected' : '' ?>>
                                <?= $h($m['nama_lengkap'] ?? '') ?> (<?= $h($m['nim'] ?? '-') ?>)
                                <?= !empty($m['delegate_for'] ?? '') ? ' • sudah dipercaya' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="photo-hint">
                        Mentee yang dipilih akan menerima                         link pendaftaran biometrik sekali pakai
                        (dibuat lewat halaman Pengaturan → Atur Ulang Biometrik) untuk menyiapkan data
                        biometrik pribadinya. Hanya satu perwakilan per mentor.
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Simpan Perubahan</button>
                <a href="<?= $h($basePath) ?>#tab-mentors" class="btn-cancel">Batal</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDetailKesibukan() {
    const val = document.getElementById('mentor-status-kesibukan').value;
    document.getElementById('detail-kuliah').style.display =
        (val === 'Kuliah' || val === 'Kuliah & Bekerja') ? '' : 'none';
    document.getElementById('detail-bekerja').style.display =
        (val === 'Bekerja' || val === 'Kuliah & Bekerja') ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    toggleDetailKesibukan();
    document.querySelectorAll('select.form-control').forEach(el => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(el);
    });
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
<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
</body>
</html>
