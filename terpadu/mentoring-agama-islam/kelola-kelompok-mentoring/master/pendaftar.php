<?php
/**
 * master/pendaftar.php — Kelola Satu Data Pendaftar (Waiting List)
 * Admin dapat melihat detail biodata pendaftar, memperbarui status/catatan,
 * menerima & mengonversi pendaftar menjadi Mentee pada Tim Mentoring, atau menghapus.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterDataException;
use Mentoring\MasterSchema;
use Mentoring\ProfilePhoto;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$regId = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));

$master = MasterData::loadAll();
$regIndex = null;
foreach ($master['registrants'] ?? [] as $i => $row) {
    if (is_array($row) && (string)($row['id'] ?? '') === $regId) {
        $regIndex = $i;
        break;
    }
}

if ($regIndex === null) {
    header('Location: ' . $basePath . '?error=notfound#tab-pendaftar', true, 303);
    exit;
}

$reg = $master['registrants'][$regIndex];
$detailUrl = static function (string $msg = '', string $error = '') use ($basePath, $regId): string {
    $url = $basePath . 'pendaftar?id=' . urlencode($regId);
    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }
    if ($error !== '') {
        $url .= '&error=' . urlencode($error);
    }
    return $url;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $result = MasterData::transaction(static function (array $master) use ($regId): array {
            $before = count($master['registrants'] ?? []);
            $master['registrants'] = array_values(array_filter(
                $master['registrants'] ?? [],
                static fn($r): bool => is_array($r) && (string)($r['id'] ?? '') !== $regId
            ));
            if (count($master['registrants']) === $before) {
                throw new MasterDataException('Data pendaftar tidak ditemukan.', 'notfound');
            }
            return $master;
        }, 'hapus-pendaftar');

        if (!$result['ok']) {
            mentoring_redirect_master_error($detailUrl(), $result);
        }

        header('Location: ' . $basePath . '?msg=registrant_deleted#tab-pendaftar', true, 303);
        exit;
    }

    if ($action === 'update') {
        if (!empty($reg['assigned_mentee_id'])) {
            header('Location: ' . $detailUrl(), true, 303);
            exit;
        }
        $status = trim((string)($_POST['status_pendaftaran'] ?? 'Menunggu'));
        if (!in_array($status, MasterSchema::REGISTRANT_STATUS, true)) {
            $status = 'Menunggu';
        }
        $catatan = trim((string)($_POST['catatan_admin'] ?? ''));
        $namaLengkap = MasterSchema::normalizeName((string)($_POST['nama_lengkap'] ?? ''));
        $nomorWa = MasterSchema::normalizePhone((string)($_POST['nomor_whatsapp'] ?? ''));

        if ($namaLengkap === '' || $nomorWa === '') {
            header('Location: ' . $detailUrl('', 'wajib'), true, 303);
            exit;
        }

        $result = MasterData::transaction(static function (array $master) use ($regId, $status, $catatan, $namaLengkap, $nomorWa): array {
            $idx = null;
            foreach ($master['registrants'] ?? [] as $i => $row) {
                if (is_array($row) && (string)($row['id'] ?? '') === $regId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                throw new MasterDataException('Data pendaftar tidak ditemukan.', 'notfound');
            }

            $row = $master['registrants'][$idx];
            $row['nama_lengkap'] = $namaLengkap;
            $row['nomor_whatsapp'] = $nomorWa;
            $row['status_pendaftaran'] = $status;
            $row['catatan_admin'] = $catatan;
            $row['updated_at'] = date('c');

            $master['registrants'][$idx] = $row;
            return $master;
        }, 'update-pendaftar');

        if (!$result['ok']) {
            mentoring_redirect_master_error($detailUrl(), $result);
        }

        header('Location: ' . $detailUrl('updated'), true, 303);
        exit;
    }

    if ($action === 'promote_to_mentee') {
        $timId = trim((string)($_POST['tim_id'] ?? ''));
        if ($timId === '') {
            header('Location: ' . $detailUrl('', 'tim_wajib'), true, 303);
            exit;
        }

        $result = MasterData::transaction(static function (array $master) use ($regId, $timId): array {
            $idx = null;
            foreach ($master['registrants'] ?? [] as $i => $row) {
                if (is_array($row) && (string)($row['id'] ?? '') === $regId) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                throw new MasterDataException('Data pendaftar tidak ditemukan.', 'notfound');
            }

            $mentorId = '';
            foreach ($master['teams'] ?? [] as $team) {
                if (($team['id'] ?? '') === $timId) {
                    $mentorId = (string)($team['mentor_id'] ?? '');
                    break;
                }
            }
            if ($mentorId === '') {
                throw new MasterDataException('Tim tujuan tidak ditemukan atau belum memiliki mentor.', 'notfound');
            }

            $row = $master['registrants'][$idx];
            $newMenteeId = 'mte-' . substr(bin2hex(random_bytes(3)), 0, 6);

            $master['mentees'][] = [
                'id' => $newMenteeId,
                'foto_profil' => $row['foto_profil'] ?? null,
                'nama_lengkap' => (string)($row['nama_lengkap'] ?? ''),
                'gender' => (string)($row['gender'] ?? 'Laki-laki'),
                'tempat_lahir' => (string)($row['tempat_lahir'] ?? ''),
                'tanggal_lahir' => (string)($row['tanggal_lahir'] ?? ''),
                'kelas' => Academic::normalizeClass((string)($row['kelas'] ?? '')),
                'nim' => (string)($row['nim'] ?? ''),
                'nomor_whatsapp' => (string)($row['nomor_whatsapp'] ?? ''),
                'tim_id' => $timId,
                'mentor_id' => $mentorId,
                'jadwal_kuliah' => (string)($row['jadwal_kuliah'] ?? 'Pagi'),
                'alamat_sekarang' => (string)($row['alamat_sekarang'] ?? ''),
                'alamat_orang_tua' => (string)($row['alamat_orang_tua'] ?? ''),
                'amanah_organisasi' => 'Tidak Ada',
                'status_keaktifan' => 'Aktif',
                'consecutive_absent' => 0,
                'tidak_jelas_kabarnya' => false,
            ];

            $row['status_pendaftaran'] = 'Disetujui';
            $row['assigned_tim_id'] = $timId;
            $row['assigned_mentee_id'] = $newMenteeId;
            $row['updated_at'] = date('c');
            $master['registrants'][$idx] = $row;

            return $master;
        }, 'terima-pendaftar');

        if (!$result['ok']) {
            mentoring_redirect_master_error($detailUrl(), $result);
        }

        header('Location: ' . $basePath . '?msg=registrant_approved#tab-pendaftar', true, 303);
        exit;
    }
}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES);
$fotoUrl = ProfilePhoto::urlFor($reg['foto_profil'] ?? null);
$kelasTampil = Academic::formatClass($reg['kelas'] ?? '', $reg['nim'] ?? '');
$semester = Academic::semesterFromNim($reg['nim'] ?? '');
$stambuk = Academic::entryYearFromNim($reg['nim'] ?? '');
$waNumber = MasterSchema::normalizePhone((string)($reg['nomor_whatsapp'] ?? ''));

$mentorNames = [];
foreach ($master['mentors'] ?? [] as $m) {
    $mentorNames[$m['id'] ?? ''] = $m['nama_lengkap'] ?? '';
}

$teams = $master['teams'] ?? [];
$prefMentorId = (string)($reg['preferred_mentor_id'] ?? '');
$mediaList = is_array($reg['media_komunikasi_list'] ?? null) ? $reg['media_komunikasi_list'] : [];
if ($mediaList === [] && !empty($reg['media_komunikasi_lain'])) {
    $mediaList = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$reg['media_komunikasi_lain']) ?: [])));
}

$msg = match ((string)($_GET['msg'] ?? '')) {
    'updated' => 'Data dan status pendaftar berhasil diperbarui.',
    default => '',
};
$err = match ((string)($_GET['error'] ?? '')) {
    'wajib' => 'Nama lengkap dan Nomor WhatsApp wajib diisi.',
    'tim_wajib' => 'Pilih Tim Mentoring tujuan terlebih dahulu.',
    default => mentoring_form_error(),
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pendaftar: <?= $h($reg['nama_lengkap'] ?? '-') ?> — Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .summary-item {
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 10px 12px;
        }
        .summary-item.full { grid-column: span 2; }
        .summary-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .summary-value {
            font-size: 0.92rem;
            color: #e2e8f0;
            word-break: break-word;
            line-height: 1.5;
        }
        .media-chip {
            display: inline-block;
            background: #1e293b;
            border: 1px solid #334155;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 0.82rem;
            margin: 2px 4px 2px 0;
        }
        .reg-header-profile {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .reg-avatar {
            width: 88px;
            height: 88px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--card-border);
            background: #0b1120;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .reg-avatar img { width: 100%; height: 100%; object-fit: cover; }
        @media (max-width: 600px) {
            .summary-grid { grid-template-columns: 1fr; }
            .summary-item.full { grid-column: span 1; }
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Kelola Pendaftar</h1>
        <p class="page-subtitle"><?= $h($reg['nama_lengkap'] ?? '-') ?> &bull; <?= $h($regId) ?></p>
        <div class="subpage-back">
            <a href="<?= $h($basePath) ?>#tab-pendaftar">&larr; Kembali ke Tab Pendaftar</a>
        </div>
    </header>

    <?php if ($msg !== ''): ?>
        <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <?= $h($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <?= $h($err) ?>
        </div>
    <?php endif; ?>

    <!-- RINGKASAN BIODATA PENDAFTAR -->
    <div class="card">
        <div class="reg-header-profile" style="margin-bottom: 18px;">
            <div class="reg-avatar">
                <?php if ($fotoUrl): ?>
                    <img src="<?= $h($fotoUrl) ?>" alt="Foto <?= $h($reg['nama_lengkap'] ?? '') ?>">
                <?php else: ?>
                    <span class="avatar-initial" style="font-size: 1.8rem;"><?= $h(strtoupper(substr((string)($reg['nama_lengkap'] ?? '?'), 0, 1))) ?></span>
                <?php endif; ?>
            </div>
            <div>
                <h2 style="font-size: 1.25rem; color: #f8fafc;"><?= $h($reg['nama_lengkap'] ?? '-') ?></h2>
                <div style="font-size: 0.88rem; color: #94a3b8; margin-top: 2px;">
                    <?= $h($reg['gender'] ?? '-') ?> &bull; Mendaftar <?= !empty($reg['created_at']) ? $h(date('d M Y H:i', strtotime((string)$reg['created_at']))) : '-' ?>
                </div>
                <?php if ($waNumber !== ''): ?>
                    <div style="margin-top: 8px;">
                        <a href="https://wa.me/<?= $h($waNumber) ?>" target="_blank" rel="noopener noreferrer" class="btn-action" style="display:inline-flex; padding: 6px 12px; min-height: 0; font-size: 0.8rem;">
                            Hubungi via WhatsApp &nearr;
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-label">NIM &amp; Kelas</span>
                <span class="summary-value">
                    <?= $h($reg['nim'] ?? '-') ?>
                    <?= $kelasTampil !== '' ? ' &bull; ' . $h($kelasTampil) : '' ?>
                    <?= $semester !== null ? ' (Sem ' . $h($semester) . ', Stambuk ' . $h($stambuk) . ')' : '' ?>
                </span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Sesi Kuliah</span>
                <span class="summary-value"><?= $h($reg['jadwal_kuliah'] ?? '-') ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Nomor WhatsApp</span>
                <span class="summary-value"><?= $h($reg['nomor_whatsapp'] ?? '-') ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Email</span>
                <span class="summary-value"><?= ($reg['email'] ?? '') !== '' ? $h($reg['email']) : '-' ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Pengalaman Mentoring</span>
                <span class="summary-value"><?= $h($reg['pengalaman_mentoring'] ?? '-') ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Mentor yang Diminati</span>
                <span class="summary-value"><?= ($reg['preferred_mentor_nama'] ?? '') !== '' ? $h($reg['preferred_mentor_nama']) : 'Bebas / Dipilihkan Divisi' ?></span>
            </div>
            <div class="summary-item full">
                <span class="summary-label">Media Komunikasi Lain</span>
                <span class="summary-value">
                    <?php if ($mediaList === []): ?>
                        -
                    <?php else: ?>
                        <?php foreach ($mediaList as $item): ?>
                            <span class="media-chip"><?= $h($item) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="summary-item full">
                <span class="summary-label">Alasan Mendaftar Mentoring</span>
                <span class="summary-value"><?= ($reg['alasan_daftar'] ?? '') !== '' ? nl2br($h($reg['alasan_daftar'])) : '-' ?></span>
            </div>
            <div class="summary-item full">
                <span class="summary-label">Kegiatan yang Paling Banyak Menghabiskan Waktu Sekarang</span>
                <span class="summary-value"><?= ($reg['kegiatan_utama'] ?? '') !== '' ? $h($reg['kegiatan_utama']) : '-' ?></span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Tempat &amp; Tanggal Lahir</span>
                <span class="summary-value">
                    <?= trim(($reg['tempat_lahir'] ?? '') . ', ' . ($reg['tanggal_lahir'] ?? ''), ', ') ?: '-' ?>
                </span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Alamat Tinggal Sekarang</span>
                <span class="summary-value"><?= ($reg['alamat_sekarang'] ?? '') !== '' ? $h($reg['alamat_sekarang']) : '-' ?></span>
            </div>
            <div class="summary-item full">
                <span class="summary-label">Alamat Orang Tua</span>
                <span class="summary-value"><?= ($reg['alamat_orang_tua'] ?? '') !== '' ? $h($reg['alamat_orang_tua']) : '-' ?></span>
            </div>
        </div>
    </div>

    <!-- TERIMA & KONVERSI KE MENTEE -->
    <div class="card">
        <h3 class="card-title">Penempatan Kelompok (Konversi ke Mentee)</h3>
        <?php if (!empty($reg['assigned_mentee_id'])): ?>
            <p style="color: #34d399; font-size: 0.9rem;">
                Pendaftar ini sudah diterima dan tercatat sebagai Mentee (ID: <b><?= $h($reg['assigned_mentee_id']) ?></b>).
                <a href="<?= $h($basePath) ?>pengaturan?tipe=mentee&id=<?= urlencode((string)$reg['assigned_mentee_id']) ?>" style="color: #38bdf8; margin-left: 6px;">Lihat di Pengaturan Mentee &rarr;</a>
            </p>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 14px;">
                Pilih Tim Mentoring tujuan untuk langsung memindahkan pendaftar ini menjadi <b>Mentee Aktif</b> di Daftar Mentee.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="promote_to_mentee">
                <input type="hidden" name="id" value="<?= $h($regId) ?>">
                <div class="form-group">
                    <label for="tim_id">Pilih Tim Mentoring Tujuan:</label>
                    <select name="tim_id" id="tim_id" class="form-control searchable" required>
                        <option value="">-- Pilih Tim Mentoring --</option>
                        <?php foreach ($teams as $t): ?>
                            <?php
                                $tMentorId = (string)($t['mentor_id'] ?? '');
                                $tMentorName = $mentorNames[$tMentorId] ?? 'Belum ditentukan';
                                $isPreferred = ($prefMentorId !== '' && $tMentorId === $prefMentorId);
                            ?>
                            <option value="<?= $h($t['id']) ?>" <?= $isPreferred ? 'selected' : '' ?>>
                                <?= $h($t['nama_tim']) ?> &bull; Mentor: <?= $h($tMentorName) ?><?= $isPreferred ? ' ★ (Sesuai Minat)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="submit-btn" style="width: auto; padding: 10px 20px;">Terima &amp; Masukkan ke Kelompok</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- PERBARUI STATUS & CATATAN -->
    <?php if (empty($reg['assigned_mentee_id'])): ?>
    <div class="card">
        <h3 class="card-title">Perbarui Status &amp; Catatan Admin</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $h($regId) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="nama_lengkap">Nama Lengkap:</label>
                    <input type="text" name="nama_lengkap" id="nama_lengkap" class="form-control" required value="<?= $h($reg['nama_lengkap'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="nomor_whatsapp">Nomor WhatsApp:</label>
                    <input type="text" name="nomor_whatsapp" id="nomor_whatsapp" class="form-control" required value="<?= $h($reg['nomor_whatsapp'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="status_pendaftaran">Status Pendaftaran:</label>
                    <select name="status_pendaftaran" id="status_pendaftaran" class="form-control">
                        <?php foreach (MasterSchema::REGISTRANT_STATUS as $st): ?>
                            <option value="<?= $h($st) ?>" <?= ($reg['status_pendaftaran'] ?? 'Menunggu') === $st ? 'selected' : '' ?>><?= $h($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-span-2">
                    <label for="catatan_admin">Catatan Divisi Mentoring (Opsional):</label>
                    <textarea name="catatan_admin" id="catatan_admin" class="form-control" rows="2" placeholder="Catatan tindak lanjut atau alasan status..."><?= $h($reg['catatan_admin'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions" style="justify-content: space-between;">
                <button type="submit" class="submit-btn">Simpan Perubahan</button>
            </div>
        </form>

        <form method="POST" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #334155;"
              data-confirm="Hapus data pendaftar ini secara permanen?"
              data-confirm-title="Hapus Data Pendaftar">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $h($regId) ?>">
            <button type="submit" class="modal-btn danger" style="padding: 9px 16px;">Hapus Data Pendaftar</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
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

    document.querySelectorAll('select.form-control').forEach(el => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(el);
    });

    const namaEl = document.getElementById('nama_lengkap');
    if (namaEl) {
        const toTitleCase = (val) => val.toLowerCase().replace(/(^|[\s\-'])(\S)/g, (_, sep, ch) => sep + ch.toUpperCase());
        namaEl.addEventListener('input', () => {
            const start = namaEl.selectionStart;
            const end = namaEl.selectionEnd;
            const formatted = toTitleCase(namaEl.value);
            if (namaEl.value !== formatted) {
                namaEl.value = formatted;
                if (start !== null && end !== null) namaEl.setSelectionRange(start, end);
            }
        });
        namaEl.addEventListener('blur', () => {
            namaEl.value = toTitleCase(namaEl.value).replace(/\s+/g, ' ').trim();
        });
    }
});
</script>
</body>
</html>
