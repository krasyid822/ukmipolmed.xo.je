<?php
/**
 * master/tambah-mentor.php — Subhalaman Tambah Mentor Baru
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterSchema;
use Mentoring\ProfilePhoto;

AdminAuth::guard();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
    $nomorWa = trim($_POST['nomor_whatsapp'] ?? '');

    $validationError = MasterSchema::validatePersonInput([
        'nama_lengkap' => $namaLengkap,
        'nomor_whatsapp' => $nomorWa,
        'gender' => $_POST['gender'] ?? 'Laki-laki',
        'tempat_lahir' => $_POST['tempat_lahir'] ?? '',
        'tanggal_lahir' => $_POST['tanggal_lahir'] ?? '',
        'nim' => $_POST['nim'] ?? '',
        'kelas' => $_POST['kelas'] ?? '',
        'jadwal_kuliah' => $_POST['jadwal_kuliah_mentor'] ?? 'Tidak Menentu',
        'status_kesibukan' => $_POST['status_kesibukan'] ?? 'Kuliah',
        'alamat_sekarang' => $_POST['alamat_sekarang'] ?? '',
        'alamat_orang_tua' => $_POST['alamat_orang_tua'] ?? '',
    ], true);
    if ($validationError !== null) {
        header('Location: ' . $basePath . 'tambah-mentor?error=validasi&pesan=' . rawurlencode($validationError), true, 303);
        exit;
    }
    $nomorWa = MasterSchema::normalizePhone($nomorWa);

    $newId = 'mtr-' . substr(bin2hex(random_bytes(3)), 0, 6);

    $fotoPath = null;
    if (!empty($_FILES['foto_profil']['name'])) {
        try {
            $fotoPath = ProfilePhoto::storeUpload('mentor', $newId, $_FILES['foto_profil']);
        } catch (\Throwable $e) {
            header('Location: ' . $basePath . 'tambah-mentor?error=foto', true, 303);
            exit;
        }
    }

    $result = MasterData::transaction(static function (array $master) use ($newId, $fotoPath, $namaLengkap, $nomorWa): array {
        $master['mentors'][] = [
            'id' => $newId,
            'foto_profil' => $fotoPath,
            'nama_lengkap' => $namaLengkap,
            'gender' => trim($_POST['gender'] ?? 'Laki-laki'),
            'tempat_lahir' => MasterSchema::normalizeName((string)($_POST['tempat_lahir'] ?? '')),
            'tanggal_lahir' => trim($_POST['tanggal_lahir'] ?? ''),
            'nomor_whatsapp' => $nomorWa,
            'status_kesibukan' => trim($_POST['status_kesibukan'] ?? 'Kuliah'),
            'detail_kesibukan' => [
                'instansi_perusahaan' => trim($_POST['instansi_perusahaan'] ?? ''),
                'profesi_jabatan' => trim($_POST['profesi_jabatan'] ?? ''),
                'jadwal_kerja' => trim($_POST['jadwal_kerja'] ?? ''),
                'nim' => trim($_POST['nim'] ?? ''),
                'kelas' => Academic::normalizeClass($_POST['kelas'] ?? ''),
                'jadwal_kuliah' => trim($_POST['jadwal_kuliah_mentor'] ?? 'Tidak Menentu') ?: 'Tidak Menentu',
            ],
            'alamat_sekarang' => MasterSchema::normalizeName((string)($_POST['alamat_sekarang'] ?? '')),
            'alamat_orang_tua' => MasterSchema::normalizeName((string)($_POST['alamat_orang_tua'] ?? '')),
            'amanah_organisasi' => trim($_POST['amanah_organisasi'] ?? 'Tidak Ada'),
            'status' => 'Aktif',
        ];

        return $master;
    }, 'tambah-mentor');

    if (!$result['ok']) {
        mentoring_redirect_master_error($basePath . 'tambah-mentor', $result);
    }

    header('Location: ' . $basePath . '?msg=mentor_added#tab-mentors', true, 303);
    exit;
}

$errorWajib = ($_GET['error'] ?? '') === 'wajib';
$errorFoto = ($_GET['error'] ?? '') === 'foto';
$formError = mentoring_form_error();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Mentor — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container master-subpage" style="max-width: 960px;">
    <header class="page-header">
        <div>
            <h1 class="page-title">Tambah Mentor Baru</h1>
            <p class="page-subtitle">Lengkapi biodata mentor pendamping mentoring</p>
        </div>
        <div class="subpage-back">
            <a href="<?= htmlspecialchars($basePath) ?>">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if ($errorWajib): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Nama lengkap dan Nomor WhatsApp wajib diisi.
        </div>
    <?php endif; ?>

    <?php if ($errorFoto): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Foto gagal diproses. Gunakan foto selfie (JPG/PNG/WEBP) maksimal 8 MB.
        </div>
    <?php endif; ?>

    <?php if (!$errorWajib && !$errorFoto && $formError !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($formError) ?>
        </div>
    <?php endif; ?>

    <div id="draftRestoreBanner" style="display:none; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.35); color: #6ee7b7; padding: 10px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 0.85rem; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
        <span>Progres pengisian terakhir Anda dipulihkan otomatis.</span>
        <button type="button" id="btnResetDraft" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.45); color: #fca5a5; padding: 5px 11px; border-radius: 7px; font-size: 0.78rem; font-weight: 600; cursor: pointer; font-family: inherit;">Reset Isian</button>
    </div>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <div class="master-form-split">
            <div class="master-form-col">
            <div class="form-section-label">Foto Profil</div>
            <div class="form-group">
                <label>Foto Selfie Mentor:</label>
                <div class="photo-upload-box">
                    <div class="photo-preview" id="mentor-photo-preview"></div>
                    <div class="photo-fields">
                        <input type="file" name="foto_profil" accept="image/*" capture="user"
                               class="form-control" data-profile-photo
                               data-allow-clipboard="true"
                               data-preview="mentor-photo-preview" data-info="mentor-photo-info">
                        <div class="photo-hint" id="mentor-photo-info">
                            Wajib berupa <b>foto selfie</b> yang memperlihatkan wajah dengan jelas
                            (tanpa masker/kacamata gelap). Maks. 8 MB — otomatis dipotong persegi
                            dan dikompres di perangkat Anda.
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Lengkap &amp; Gelar: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nama_lengkap" class="form-control" required minlength="2" maxlength="120" placeholder="Ahmad Fauzi, S.Kom.">
                </div>
                <div class="form-group">
                    <label>Nomor WhatsApp: <span style="color:#f87171">*</span></label>
                    <input type="tel" name="nomor_whatsapp" class="form-control" required inputmode="numeric" pattern="(\+?62|0)8[0-9\s\-]{7,15}" title="Gunakan nomor WhatsApp Indonesia yang valid, mis. 6281234567890 atau 081234567890" placeholder="6281234567890">
                </div>
                <div class="form-group">
                    <label>Gender:</label>
                    <select name="gender" class="form-control">
                        <option value="Laki-laki">Laki-laki</option>
                        <option value="Perempuan">Perempuan</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tempat Lahir:</label>
                    <input type="text" name="tempat_lahir" class="form-control" minlength="2" maxlength="80" placeholder="Medan">
                </div>
                <div class="form-group">
                    <label>Tanggal Lahir:</label>
                    <input type="date" name="tanggal_lahir" class="form-control" min="1940-01-01" max="<?= date('Y-m-d', strtotime('-10 years')) ?>">
                </div>
                <div class="form-group">
                    <label>Status Kesibukan:</label>
                    <select name="status_kesibukan" class="form-control" id="mentor-status-kesibukan" onchange="toggleDetailKesibukan()">
                        <option value="Kuliah">Kuliah</option>
                        <option value="Bekerja">Bekerja</option>
                        <option value="Kuliah & Bekerja">Kuliah &amp; Bekerja</option>
                    </select>
                </div>
            </div>
            </div>
            <div class="master-form-col">
            <!-- Detail Kuliah (tampil saat Kuliah / Kuliah & Bekerja) -->
            <div id="detail-kuliah">
                <div class="form-section-label">Detail Perkuliahan</div>
                <div class="form-grid cols-3">
                    <div class="form-group">
                        <label>NIM:</label>
                        <input type="text" name="nim" class="form-control" inputmode="numeric" pattern="\d{6,15}" title="NIM harus berupa angka 6–15 digit" placeholder="2305181010">
                    </div>
                    <div class="form-group">
                        <label>Kelas:</label>
                        <input type="text" name="kelas" class="form-control" pattern="[A-Za-z]{2,10}([\s\-]?[0-9]*[A-Za-z][A-Za-z0-9]*)?" title="Contoh: TRPL-A atau MI-B" placeholder="TRPL-A">
                        <div class="photo-hint">Cukup program studi + kelas, contoh <b>TRPL-A</b>. Semester dihitung otomatis dari dua digit awal NIM.</div>
                    </div>
                    <div class="form-group">
                        <label>Jadwal Kuliah:</label>
                        <select name="jadwal_kuliah_mentor" class="form-control">
                            <option value="Pagi">Pagi</option>
                            <option value="Sore">Sore</option>
                            <option value="Tidak Menentu" selected>Tidak Menentu</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Detail Bekerja (tampil saat Bekerja / Kuliah & Bekerja) -->
            <div id="detail-bekerja" style="display:none;">
                <div class="form-section-label">Detail Pekerjaan</div>
                <div class="form-grid cols-3">
                    <div class="form-group">
                        <label>Instansi / Perusahaan:</label>
                        <input type="text" name="instansi_perusahaan" class="form-control" placeholder="PT. Contoh">
                    </div>
                    <div class="form-group">
                        <label>Profesi / Jabatan:</label>
                        <input type="text" name="profesi_jabatan" class="form-control" placeholder="Staff IT">
                    </div>
                    <div class="form-group">
                        <label>Jadwal Kerja:</label>
                        <input type="text" name="jadwal_kerja" class="form-control" placeholder="Senin–Jumat, 08.00–17.00">
                    </div>
                </div>
            </div>

            <div class="form-section-label">Domisili &amp; Organisasi</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Alamat Sekarang:</label>
                    <input type="text" name="alamat_sekarang" class="form-control" minlength="4" maxlength="250" placeholder="Jl. Medan, No. 1">
                </div>
                <div class="form-group">
                    <label>Alamat Orang Tua:</label>
                    <input type="text" name="alamat_orang_tua" class="form-control" minlength="4" maxlength="250" placeholder="Jl. Kampung, No. 2">
                </div>
                <div class="form-group col-span-2">
                    <label>Amanah Organisasi:</label>
                    <input type="text" name="amanah_organisasi" class="form-control" placeholder="Staff Kaderisasi / Tidak Ada">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Simpan Mentor</button>
                <a href="<?= htmlspecialchars($basePath) ?>" class="btn-cancel">Batal</a>
            </div>
            </div>
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
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/searchable-select.js"></script>
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/profile-photo.js"></script>
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/master-draft.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initMasterFormDraft === 'function') {
        initMasterFormDraft({
            key: 'mai_master_draft_mentor_v1',
            photoPreviewId: 'mentor-photo-preview',
            photoInfoId: 'mentor-photo-info'
        });
    }
});
</script>
</body>
</html>
