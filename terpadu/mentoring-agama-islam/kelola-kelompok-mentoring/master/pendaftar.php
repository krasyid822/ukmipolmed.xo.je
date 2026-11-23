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
if (!AdminAuth::canAccessGender($reg['gender'] ?? '')) {
    header('Location: ' . $basePath . '?error=notfound#tab-pendaftar', true, 303);
    exit;
}
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
    if (!AdminAuth::verifyCsrfToken()) {
        http_response_code(403);
        die('Token CSRF tidak valid atau sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
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
        $catatan = trim((string)($_POST['catatan_admin'] ?? ''));

        $result = MasterData::transaction(static function (array $master) use ($regId, $catatan): array {
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
            $row['catatan_admin'] = $catatan;
            $row['status_pendaftaran'] = 'Cadangan';
            $row['alasan_penolakan'] = '';
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

    if ($action === 'restore_to_cadangan') {
        if (!empty($reg['assigned_mentee_id'])) {
            header('Location: ' . $detailUrl(), true, 303);
            exit;
        }

        $result = MasterData::transaction(static function (array $master) use ($regId): array {
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
            $alasan = (string)($row['alasan_penolakan'] ?? '');
            if ($alasan !== '' && (string)($row['catatan_admin'] ?? '') === $alasan) {
                $row['catatan_admin'] = '';
            }
            $row['status_pendaftaran'] = 'Cadangan';
            $row['alasan_penolakan'] = '';
            $row['updated_at'] = date('c');

            $master['registrants'][$idx] = $row;
            return $master;
        }, 'pulihkan-pendaftar');

        if (!$result['ok']) {
            mentoring_redirect_master_error($detailUrl(), $result);
        }

        header('Location: ' . $detailUrl('restored'), true, 303);
        exit;
    }

    if ($action === 'reject') {
        if (!empty($reg['assigned_mentee_id'])) {
            header('Location: ' . $detailUrl(), true, 303);
            exit;
        }
        $alasan = trim((string)($_POST['alasan_penolakan'] ?? ''));
        if ($alasan === '') {
            header('Location: ' . $detailUrl('', 'alasan_wajib'), true, 303);
            exit;
        }

        $result = MasterData::transaction(static function (array $master) use ($regId, $alasan): array {
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
            $row['status_pendaftaran'] = 'Ditolak';
            $row['catatan_admin'] = $alasan;
            $row['alasan_penolakan'] = $alasan;
            $row['updated_at'] = date('c');

            $master['registrants'][$idx] = $row;
            return $master;
        }, 'tolak-pendaftar');

        if (!$result['ok']) {
            mentoring_redirect_master_error($detailUrl(), $result);
        }

        header('Location: ' . $detailUrl('rejected'), true, 303);
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
            $selectedTeam = null;
            foreach ($master['teams'] ?? [] as $team) {
                if (($team['id'] ?? '') === $timId) {
                    $selectedTeam = $team;
                    $mentorId = (string)($team['mentor_id'] ?? '');
                    break;
                }
            }
            if ($mentorId === '' || $selectedTeam === null || !AdminAuth::canAccessRecord($master, 'tim', $selectedTeam)) {
                throw new MasterDataException('Tim tujuan tidak ditemukan, belum memiliki mentor, atau tidak sesuai akses role Anda.', 'notfound');
            }

            $row = $master['registrants'][$idx];
            $existingMenteeId = trim((string)($row['assigned_mentee_id'] ?? ''));
            if ($existingMenteeId !== '') {
                foreach ($master['mentees'] ?? [] as $mRow) {
                    if (is_array($mRow) && (string)($mRow['id'] ?? '') === $existingMenteeId) {
                        throw new MasterDataException('Pendaftar ini sudah terdaftar sebagai mentee.', 'duplikat');
                    }
                }
            }
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
                'jadwal_kuliah' => (string)(($row['jadwal_kuliah'] ?? '') !== '' ? $row['jadwal_kuliah'] : 'Tidak Menentu'),
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

$filteredMaster = AdminAuth::filterMasterData($master);
$teams = $filteredMaster['teams'] ?? [];
$assignedMentee = null;
$assignedTeam = null;
$assignedMenteeId = trim((string)($reg['assigned_mentee_id'] ?? ''));
if ($assignedMenteeId !== '') {
    foreach (array_merge($master['mentees'] ?? [], $master['archived_mentees'] ?? []) as $mRow) {
        if (is_array($mRow) && (string)($mRow['id'] ?? '') === $assignedMenteeId) {
            $assignedMentee = $mRow;
            break;
        }
    }
    if ($assignedMentee !== null) {
        $mTimId = (string)($assignedMentee['tim_id'] ?? '');
        foreach ($teams as $tRow) {
            if (is_array($tRow) && (string)($tRow['id'] ?? '') === $mTimId) {
                $assignedTeam = $tRow;
                break;
            }
        }
    }
}
$isApprovedMentee = ($assignedMentee !== null && $assignedTeam !== null);
$prefMentorId = (string)($reg['preferred_mentor_id'] ?? '');
$mediaList = is_array($reg['media_komunikasi_list'] ?? null) ? $reg['media_komunikasi_list'] : [];
if ($mediaList === [] && !empty($reg['media_komunikasi_lain'])) {
    $mediaList = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$reg['media_komunikasi_lain']) ?: [])));
}

$msg = match ((string)($_GET['msg'] ?? '')) {
    'updated' => 'Rencana penempatan pendaftar berhasil disimpan dan status ditandai sebagai Cadangan.',
    'restored' => 'Status pendaftar berhasil dipulihkan menjadi Cadangan.',
    'rejected' => 'Pendaftar telah ditandai sebagai Ditolak beserta alasannya.',
    default => '',
};
$err = match ((string)($_GET['error'] ?? '')) {
    'tim_wajib' => 'Pilih Tim Mentoring tujuan terlebih dahulu.',
    'alasan_wajib' => 'Alasan penolakan wajib diisi.',
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
        .reg-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            text-align: left;
            flex-wrap: wrap;
        }
        .reg-page-header .subpage-back { margin-top: 10px; }
        .btn-reject-top {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #7f1d1d;
            background: rgba(239, 68, 68, 0.12);
            color: #fca5a5;
            font-family: inherit;
            font-size: 0.83rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-reject-top:hover { background: rgba(239, 68, 68, 0.25); border-color: #ef4444; }
        .modal-box {
            width: 100%;
            max-width: 480px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
            max-height: calc(100vh - 36px);
            overflow-y: auto;
        }
        .modal-box h3 {
            font-size: 1.08rem;
            color: #fecaca;
            margin-bottom: 6px;
        }
        .modal-box > p {
            font-size: 0.86rem;
            color: var(--text-muted);
            line-height: 1.55;
            margin-bottom: 14px;
        }
        .danger-btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            background: #dc2626;
            color: #fff;
            font-family: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .danger-btn:hover { background: #b91c1c; }
        @media (max-width: 600px) {
            .summary-grid { grid-template-columns: 1fr; }
            .summary-item.full { grid-column: span 1; }
            .reg-page-header { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header reg-page-header">
        <div>
            <h1 class="page-title">Kelola Pendaftar</h1>
            <p class="page-subtitle"><?= $h($reg['nama_lengkap'] ?? '-') ?> &bull; <?= $h($regId) ?></p>
            <div class="subpage-back">
                <a href="<?= $h($basePath) ?>#tab-pendaftar">&larr; Kembali ke Tab Pendaftar</a>
            </div>
        </div>
        <?php if (!$isApprovedMentee): ?>
            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                <?php if (($reg['status_pendaftaran'] ?? '') === 'Ditolak'): ?>
                    <form method="POST" style="margin: 0;">
            <?= AdminAuth::csrfField() ?>
                        <input type="hidden" name="action" value="restore_to_cadangan">
                        <input type="hidden" name="id" value="<?= $h($regId) ?>">
                        <button type="submit" class="btn-action" style="padding: 9px 14px; font-size: 0.83rem;">&#8634; Pulihkan ke Cadangan</button>
                    </form>
                <?php endif; ?>
                <form method="POST" style="margin: 0;"
                      data-confirm="Hapus data pendaftar ini secara permanen?"
                      data-confirm-title="Hapus Data Pendaftar">
            <?= AdminAuth::csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $h($regId) ?>">
                    <button type="submit" class="btn-reject-top">&#128465; Hapus Data Pendaftar</button>
                </form>
            </div>
        <?php endif; ?>
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
                <?php
                    $curStatus = (string)($reg['status_pendaftaran'] ?? 'Menunggu');
                    $curBadgeClass = match ($curStatus) {
                        'Disetujui' => 'aktif',
                        'Ditolak' => 'tidak_aktif',
                        default => 'peringatan',
                    };
                ?>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <h2 style="font-size: 1.25rem; color: #f8fafc;"><?= $h($reg['nama_lengkap'] ?? '-') ?></h2>
                    <span class="badge-status <?= $curBadgeClass ?>"><?= $h($curStatus) ?></span>
                </div>
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
            <?php if (!empty($reg['catatan_admin'])): ?>
            <div class="summary-item full">
                <span class="summary-label"><?= ($reg['status_pendaftaran'] ?? '') === 'Ditolak' ? 'Alasan Penolakan' : 'Rencana Penempatan / Catatan Admin' ?></span>
                <span class="summary-value"><?= nl2br($h($reg['catatan_admin'])) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TERIMA & KONVERSI KE MENTEE -->
    <div class="card">
        <h3 class="card-title">Penempatan Kelompok (Konversi ke Mentee)</h3>
        <?php if ($isApprovedMentee): ?>
            <p style="color: #34d399; font-size: 0.9rem;">
                Pendaftar ini sudah diterima dan tercatat sebagai Mentee (ID: <b><?= $h($assignedMentee['id']) ?></b>) pada Tim <b><?= $h($assignedTeam['nama_tim']) ?></b>.
                <a href="<?= $h($basePath) ?>pengaturan?tipe=mentee&id=<?= urlencode((string)$assignedMentee['id']) ?>" style="color: #38bdf8; margin-left: 6px;">Lihat di Pengaturan Mentee &rarr;</a>
            </p>
        <?php else: ?>
            <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 14px;">
                Pilih Tim Mentoring tujuan untuk langsung memindahkan pendaftar ini menjadi <b>Mentee Aktif</b> di Daftar Mentee.
            </p>
            <form method="POST">
            <?= AdminAuth::csrfField() ?>
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

    <!-- RENCANA PENEMPATAN -->
    <?php if (!$isApprovedMentee): ?>
    <div class="card">
        <h3 class="card-title">Rencana Penempatan</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 14px;">
            <?php if (($reg['status_pendaftaran'] ?? '') === 'Ditolak'): ?>
                Pendaftar ini berstatus <b>Ditolak</b>. Menyimpan rencana penempatan di bawah ini atau menekan tombol <b>Pulihkan ke Cadangan</b> akan mengembalikan status pendaftar menjadi <b>Cadangan</b>.
            <?php else: ?>
                Mengisi atau menyimpan rencana penempatan di bawah ini akan otomatis menandai status pendaftar menjadi <b>Cadangan</b>.
            <?php endif; ?>
        </p>
        <form method="POST">
            <?= AdminAuth::csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $h($regId) ?>">
            <div class="form-group">
                <label for="catatan_admin">Rencana Penempatan / Catatan Divisi Mentoring:</label>
                <textarea name="catatan_admin" id="catatan_admin" class="form-control" rows="7" style="min-height: 150px; line-height: 1.6; resize: vertical;" placeholder="Tuliskan rencana kelompok atau catatan tindak lanjut..."><?= ($reg['status_pendaftaran'] ?? '') === 'Ditolak' ? '' : $h($reg['catatan_admin'] ?? '') ?></textarea>
            </div>
            <div class="form-actions" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <button type="submit" class="submit-btn" style="width: auto; padding: 10px 20px;">
                    <?= ($reg['status_pendaftaran'] ?? '') === 'Ditolak' ? 'Simpan Rencana &amp; Pulihkan ke Cadangan' : 'Simpan Rencana' ?>
                </button>
            </div>
        </form>

        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #334155; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <?php if (($reg['status_pendaftaran'] ?? '') === 'Ditolak'): ?>
                <form method="POST" style="margin: 0;">
            <?= AdminAuth::csrfField() ?>
                    <input type="hidden" name="action" value="restore_to_cadangan">
                    <input type="hidden" name="id" value="<?= $h($regId) ?>">
                    <button type="submit" class="btn-action" style="padding: 9px 16px;">&#8634; Pulihkan ke Status Cadangan</button>
                </form>
                <button type="button" class="modal-btn danger" style="padding: 9px 16px;" onclick="openRejectModal()">&#10005; Ubah Alasan Tolak</button>
            <?php else: ?>
                <button type="button" class="modal-btn danger" style="padding: 9px 16px;" onclick="openRejectModal()">&#10005; Tolak Pendaftar</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL TOLAK PENDAFTAR -->
    <div class="modal-overlay" id="reject-modal" aria-hidden="true">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
            <h3 id="reject-modal-title">Tolak Pendaftar</h3>
            <p>
                Berikan alasan mengapa pendaftar <b><?= $h($reg['nama_lengkap'] ?? '-') ?></b> ditolak. Status akan berubah menjadi <b>Ditolak</b>.
            </p>
            <form method="POST" id="reject-form">
            <?= AdminAuth::csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" value="<?= $h($regId) ?>">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="alasan_penolakan">Alasan Penolakan: <span style="color:#f87171">*</span></label>
                    <textarea name="alasan_penolakan" id="alasan_penolakan" class="form-control" rows="3" required placeholder="Contoh: Nomor WhatsApp tidak dapat dihubungi / mendaftar ganda..."><?= ($reg['status_pendaftaran'] ?? '') === 'Ditolak' ? $h($reg['catatan_admin'] ?? '') : '' ?></textarea>
                </div>
                <div class="modal-actions" style="margin-top: 16px; justify-content: flex-end;">
                    <button type="button" class="btn-cancel" onclick="closeRejectModal()">Batal</button>
                    <button type="submit" class="danger-btn">Tolak Pendaftar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
<script src="<?= $h($appBase) ?>public/assets/js/modal.js?v=3"></script>
<script>
function openRejectModal() {
    const modal = document.getElementById('reject-modal');
    if (!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    const ta = document.getElementById('alasan_penolakan');
    if (ta) setTimeout(() => ta.focus(), 50);
}

function closeRejectModal() {
    const modal = document.getElementById('reject-modal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

document.addEventListener('DOMContentLoaded', () => {
    const rejectModal = document.getElementById('reject-modal');
    if (rejectModal) {
        rejectModal.addEventListener('click', (event) => {
            if (event.target === rejectModal) closeRejectModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && rejectModal.classList.contains('active')) closeRejectModal();
        });
    }
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
});
</script>
</body>
</html>
