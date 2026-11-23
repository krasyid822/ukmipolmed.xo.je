<?php
/**
 * master/tambah-mentee.php — Subhalaman Tambah Mentee Baru
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterDataException;
use Mentoring\MasterSchema;
use Mentoring\ProfilePhoto;

AdminAuth::guard();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
    $nomorWa = trim($_POST['nomor_whatsapp'] ?? '');
    $timId = trim($_POST['tim_id'] ?? '');

    if ($timId === '') {
        header('Location: ' . $basePath . 'tambah-mentee?error=validasi&pesan=' . rawurlencode('Kelompok / Tim wajib dipilih.'), true, 303);
        exit;
    }

    $validationError = MasterSchema::validatePersonInput([
        'nama_lengkap' => $namaLengkap,
        'nomor_whatsapp' => $nomorWa,
        'gender' => $_POST['gender'] ?? 'Laki-laki',
        'tempat_lahir' => $_POST['tempat_lahir'] ?? '',
        'tanggal_lahir' => $_POST['tanggal_lahir'] ?? '',
        'nim' => $_POST['nim'] ?? '',
        'kelas' => $_POST['kelas'] ?? '',
        'jadwal_kuliah' => $_POST['jadwal_kuliah'] ?? 'Tidak Menentu',
        'alamat_sekarang' => $_POST['alamat_sekarang'] ?? '',
        'alamat_orang_tua' => $_POST['alamat_orang_tua'] ?? '',
    ], false);
    if ($validationError !== null) {
        header('Location: ' . $basePath . 'tambah-mentee?error=validasi&pesan=' . rawurlencode($validationError), true, 303);
        exit;
    }
    $nomorWa = MasterSchema::normalizePhone($nomorWa);

    $newId = 'mte-' . substr(bin2hex(random_bytes(3)), 0, 6);

    $fotoPath = null;
    if (!empty($_FILES['foto_profil']['name'])) {
        try {
            $fotoPath = ProfilePhoto::storeUpload('mentee', $newId, $_FILES['foto_profil']);
        } catch (\Throwable $e) {
            header('Location: ' . $basePath . 'tambah-mentee?error=foto', true, 303);
            exit;
        }
    }

    $result = MasterData::transaction(static function (array $master) use ($newId, $fotoPath, $namaLengkap, $nomorWa, $timId): array {
        // Mentor diambil otomatis dari tim yang dipilih (menjaga integritas referensi)
        $mentorId = '';
        foreach ($master['teams'] as $team) {
            if (($team['id'] ?? '') === $timId) {
                $mentorId = (string)($team['mentor_id'] ?? '');
                break;
            }
        }

        if ($mentorId === '') {
            throw new MasterDataException('Tim yang dipilih tidak ditemukan atau belum memiliki mentor.', 'notfound');
        }

        $master['mentees'][] = [
            'id' => $newId,
            'foto_profil' => $fotoPath,
            'nama_lengkap' => $namaLengkap,
            'gender' => trim($_POST['gender'] ?? 'Laki-laki'),
            'tempat_lahir' => MasterSchema::normalizeName((string)($_POST['tempat_lahir'] ?? '')),
            'tanggal_lahir' => trim($_POST['tanggal_lahir'] ?? ''),
            'kelas' => Academic::normalizeClass($_POST['kelas'] ?? ''),
            'nim' => trim($_POST['nim'] ?? ''),
            'nomor_whatsapp' => $nomorWa,
            'tim_id' => $timId,
            'mentor_id' => $mentorId,
            'jadwal_kuliah' => trim($_POST['jadwal_kuliah'] ?? 'Tidak Menentu') ?: 'Tidak Menentu',
            'alamat_sekarang' => MasterSchema::normalizeName((string)($_POST['alamat_sekarang'] ?? '')),
            'alamat_orang_tua' => MasterSchema::normalizeName((string)($_POST['alamat_orang_tua'] ?? '')),
            'amanah_organisasi' => trim($_POST['amanah_organisasi'] ?? 'Tidak Ada'),
            'status_keaktifan' => 'Aktif',
            'consecutive_absent' => 0,
            'tidak_jelas_kabarnya' => false,
        ];

        return $master;
    }, 'tambah-mentee');

    if (!$result['ok']) {
        mentoring_redirect_master_error($basePath . 'tambah-mentee', $result);
    }

    header('Location: ' . $basePath . '?msg=mentee_added#tab-mentees', true, 303);
    exit;
}

$master = MasterData::loadAll();
$errorWajib = ($_GET['error'] ?? '') === 'wajib';
$errorFoto = ($_GET['error'] ?? '') === 'foto';
$formError = mentoring_form_error();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Mentee — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($appBase) ?>public/assets/css/style.css">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container master-subpage" style="max-width: 960px;">
    <header class="page-header">
        <div>
            <h1 class="page-title">Tambah Mentee Baru</h1>
            <p class="page-subtitle">Lengkapi biodata peserta mentoring</p>
        </div>
        <div class="subpage-back">
            <a href="<?= htmlspecialchars($basePath) ?>">&larr; Kembali ke Data Master</a>
        </div>
    </header>

    <?php if ($errorWajib): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            Nama lengkap, Nomor WhatsApp, dan Kelompok / Tim wajib diisi.
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
                <label>Foto Selfie Mentee:</label>
                <div class="photo-upload-box">
                    <div class="photo-preview" id="mentee-photo-preview"></div>
                    <div class="photo-fields">
                        <input type="file" name="foto_profil" accept="image/*" capture="user"
                               class="form-control" data-profile-photo
                               data-allow-clipboard="true"
                               data-preview="mentee-photo-preview" data-info="mentee-photo-info">
                        <div class="photo-hint" id="mentee-photo-info">
                            Wajib berupa <b>foto selfie</b> yang memperlihatkan wajah dengan jelas
                            (tanpa masker/kacamata gelap). Maks. 8 MB — otomatis dipotong persegi
                            dan dikompres di perangkat Anda.
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section-label">Identitas Wajib</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Lengkap Mentee: <span style="color:#f87171">*</span></label>
                    <input type="text" name="nama_lengkap" class="form-control" required minlength="2" maxlength="120" placeholder="Rizky Ramadhan">
                </div>
                <div class="form-group">
                    <label>Nomor WhatsApp: <span style="color:#f87171">*</span></label>
                    <input type="tel" name="nomor_whatsapp" class="form-control" required inputmode="numeric" pattern="(\+?62|0)8[0-9\s\-]{7,15}" title="Gunakan nomor WhatsApp Indonesia yang valid, mis. 6281234567890 atau 081234567890" placeholder="6281234567890">
                </div>
                <div class="form-group">
                    <label>Kelompok / Tim: <span style="color:#f87171">*</span></label>
                    <select name="tim_id" class="form-control" required>
                        <option value="">-- Pilih Tim --</option>
                        <?php foreach ($master['teams'] as $t): ?>
                            <option value="<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['nama_tim']) ?></option>
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
                    <select name="jadwal_kuliah" class="form-control">
                        <option value="Pagi">Pagi</option>
                        <option value="Sore">Sore</option>
                        <option value="Tidak Menentu" selected>Tidak Menentu</option>
                    </select>
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
                <button type="submit" class="submit-btn">Simpan Mentee</button>
                <a href="<?= htmlspecialchars($basePath) ?>" class="btn-cancel">Batal</a>
            </div>
            </div>
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
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/profile-photo.js"></script>
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/master-draft.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initMasterFormDraft === 'function') {
        initMasterFormDraft({
            key: 'mai_master_draft_mentee_v1',
            photoPreviewId: 'mentee-photo-preview',
            photoInfoId: 'mentee-photo-info'
        });
    }
});
</script>
</body>
</html>
