<?php
/**
 * master/pengaturan.php — Subhalaman Pengaturan per Data Master
 * Parameter: tipe=mentor|tim|mentee, id=<id data> (opsional)
 *
 * Saat `id` diisi, halaman menjadi pusat pengaturan satu data:
 * - ganti / unggah foto profil,
 * - ringkasan biodata + tautan ubah biodata,
 * - hapus data (karena kesalahan) atau pindahkan ke Arsip Purna
 *   (mentor/mentee yang sudah dikeluarkan dari UKMI).
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\MasterDataException;
use Mentoring\ProfilePhoto;
use Mentoring\Biometric;

AdminAuth::guard();

$appBase = mentoring_request_app_base('master/pengaturan');
if ($appBase === null) {
    $appBase = mentoring_base_path(dirname(__DIR__));
}
$appBase = rtrim($appBase, '/') . '/';
$basePath = $appBase . 'master/';
// Link setup dibagikan ke luar browser, jadi wajib absolut.
$shareBase = mentoring_request_origin() . $appBase;

$types = [
    'mentor' => [
        'label' => 'Mentor',
        'collection' => 'mentors',
        'archive' => 'archived_mentors',
        'edit' => 'edit-mentor',
        'add' => 'tambah-mentor',
        'tab' => 'tab-mentors',
        'photo_entity' => 'mentor',
        'is_person' => true,
        'photo_label' => 'Foto Selfie Mentor',
        'photo_hint' => 'Kosongkan bila foto tidak diganti. Maks. 8 MB — foto dipotong persegi dan dikompres otomatis di perangkat Anda.',
    ],
    'tim' => [
        'label' => 'Tim Mentoring',
        'collection' => 'teams',
        'archive' => null,
        'edit' => 'edit-tim',
        'add' => 'tambah-tim',
        'tab' => 'tab-teams',
        'photo_entity' => 'team',
        'is_person' => false,
        'photo_label' => 'Logo Kelompok',
        'photo_hint' => 'Kosongkan bila logo tidak diganti. Maks. 8 MB — gambar dipertahankan utuh lalu dikompres otomatis.',
    ],
    'mentee' => [
        'label' => 'Mentee',
        'collection' => 'mentees',
        'archive' => 'archived_mentees',
        'edit' => 'edit-mentee',
        'add' => 'tambah-mentee',
        'tab' => 'tab-mentees',
        'photo_entity' => 'mentee',
        'is_person' => true,
        'photo_label' => 'Foto Selfie Mentee',
        'photo_hint' => 'Kosongkan bila foto tidak diganti. Maks. 8 MB — foto dipotong persegi dan dikompres otomatis di perangkat Anda.',
    ],
];

$tipe = strtolower(trim((string)($_GET['tipe'] ?? 'mentor')));
if (!isset($types[$tipe])) {
    $tipe = 'mentor';
}
$cfg = $types[$tipe];

// Bila `id` diisi, halaman fokus ke satu data (dari tombol Pengaturan di tabel).
$focusId = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
$isSingle = $focusId !== '';
$settingsUrl = $basePath . 'pengaturan?tipe=' . urlencode($tipe)
    . ($isSingle ? '&id=' . urlencode($focusId) : '');

$master = AdminAuth::filterMasterData(MasterData::loadAll());
$recordIndex = null;
foreach ($master[$cfg['collection']] ?? [] as $i => $row) {
    if ((string)($row['id'] ?? '') === $focusId) {
        $recordIndex = $i;
        break;
    }
}
if ($isSingle && $recordIndex !== null && !AdminAuth::canAccessRecord($master, $tipe, $master[$cfg['collection']][$recordIndex])) {
    header('Location: ' . $basePath . '?error=notfound#' . urlencode($cfg['tab']), true, 303);
    exit;
}

/**
 * Cek relasi yang menghalangi penghapusan / pemindahan data.
 */
$findBlocker = static function (array $master, string $tipe, string $id): ?string {
    if ($tipe === 'mentor') {
        foreach ($master['teams'] ?? [] as $team) {
            if (($team['mentor_id'] ?? '') === $id) {
                return 'Mentor masih menjadi pendamping ' . ($team['nama_tim'] ?? 'sebuah tim')
                    . '. Pindahkan mentor pada tim tersebut terlebih dahulu.';
            }
        }
        foreach ($master['mentees'] ?? [] as $mentee) {
            if (($mentee['mentor_id'] ?? '') === $id) {
                return 'Mentor masih terhubung dengan mentee ' . ($mentee['nama_lengkap'] ?? '-') . '.';
            }
        }
    } elseif ($tipe === 'tim') {
        $totalMentee = 0;
        foreach ($master['mentees'] ?? [] as $mentee) {
            if (($mentee['tim_id'] ?? '') === $id) {
                $totalMentee++;
            }
        }
        if ($totalMentee > 0) {
            return 'Tim masih memiliki ' . $totalMentee . ' mentee. Pindahkan atau hapus mentee terlebih dahulu.';
        }
    }

    return null;
};

// ---------------------------------------------------------------------------
// Aksi POST: unggah foto, hapus permanen, atau pindahkan ke Arsip Purna
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSingle) {
    if (!AdminAuth::verifyCsrfToken()) {
        http_response_code(403);
        die('Token CSRF tidak valid atau sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'upload_photo') {
        if (empty($_FILES['foto_profil']['name'])) {
            header('Location: ' . $settingsUrl . '&error=foto', true, 303);
            exit;
        }

        try {
            $prepared = ProfilePhoto::prepareFromUpload($cfg['photo_entity'], $_FILES['foto_profil']);
        } catch (\Throwable $e) {
            header('Location: ' . $settingsUrl . '&error=foto', true, 303);
            exit;
        }

        $collection = $cfg['collection'];
        $photoEntity = $cfg['photo_entity'];

        $result = MasterData::transaction(static function (array $master) use ($collection, $photoEntity, $focusId, $prepared): array {
            $index = null;
            foreach ($master[$collection] ?? [] as $i => $row) {
                if ((string)($row['id'] ?? '') === $focusId) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                throw new MasterDataException('Data tidak ditemukan atau sudah dihapus.', 'notfound');
            }

            $record = $master[$collection][$index];
            if (!AdminAuth::canAccessRecord($master, $photoEntity, $record)) {
                throw new MasterDataException('Anda tidak memiliki akses ke data ini.', 'notfound');
            }

            // Kerangka anti-logo-kembar: hanya berlaku untuk logo kelompok
            if ($photoEntity === 'team') {
                $duplicate = ProfilePhoto::findDuplicateLogo(
                    $master['teams'] ?? [],
                    $prepared['sha256'],
                    $prepared['phash'],
                    $focusId
                );
                if ($duplicate !== null) {
                    throw new MasterDataException(
                        'Logo ini sudah dipakai oleh tim lain.',
                        'logo_duplikat',
                        ['tim' => (string)($duplicate['team']['nama_tim'] ?? '')]
                    );
                }
                $record['logo_sha256'] = $prepared['sha256'];
                $record['logo_phash'] = $prepared['phash'];
            }

            $record['foto_profil'] = ProfilePhoto::storePrepared($photoEntity, $focusId, $prepared['binary']);
            $record['foto_profil_updated_at'] = date('c');

            $master[$collection][$index] = $record;
            return $master;
        }, 'pengaturan-foto');

        if (!$result['ok']) {
            mentoring_redirect_master_error($settingsUrl, $result);
        }

        header('Location: ' . $settingsUrl . '&msg=foto_updated', true, 303);
        exit;
    }

    if ($action === 'delete') {
        $reason = (string)($_POST['delete_reason'] ?? 'kesalahan');
        $note = trim((string)($_POST['delete_note'] ?? ''));

        $collection = $cfg['collection'];
        $archiveCollection = $cfg['archive'];
        $wasArchived = $reason === 'dikeluarkan' && $cfg['is_person'] && $archiveCollection !== null;

        $result = MasterData::transaction(static function (array $master) use ($findBlocker, $tipe, $focusId, $collection, $archiveCollection, $wasArchived, $note): array {
            $index = null;
            foreach ($master[$collection] ?? [] as $i => $row) {
                if ((string)($row['id'] ?? '') === $focusId) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                throw new MasterDataException('Data tidak ditemukan atau sudah dihapus.', 'notfound');
            }

            $record = $master[$collection][$index];
            if (!AdminAuth::canAccessRecord($master, $tipe, $record)) {
                throw new MasterDataException('Anda tidak memiliki akses ke data ini.', 'notfound');
            }

            $blocker = $findBlocker($master, $tipe, $focusId);
            if ($blocker !== null) {
                throw new MasterDataException($blocker, 'relasi');
            }

            if ($wasArchived) {
                // Tempat khusus data yang telah pergi: mentor / mentee dikeluarkan dari UKMI.
                $archived = $record;
                $archived['archived_at'] = date('c');
                $archived['archive_reason'] = 'Dikeluarkan dari UKMI';
                $archived['archive_note'] = $note;
                $archived['previous_status'] = $record['status'] ?? $record['status_keaktifan'] ?? '';

                $master[$archiveCollection][] = $archived;
            }

            $master[$collection] = array_values(array_filter(
                $master[$collection] ?? [],
                static fn(array $row): bool => (string)($row['id'] ?? '') !== $focusId
            ));

            return $master;
        }, 'pengaturan-hapus');

        if (!$result['ok']) {
            mentoring_redirect_master_error($settingsUrl, $result);
        }

        if ($wasArchived) {
            header('Location: ' . $basePath . '?msg=archived#tab-archive', true, 303);
        } else {
            header('Location: ' . $basePath . '?msg=deleted&tab=' . urlencode($cfg['tab']), true, 303);
        }
        exit;
    }

    if ($action === 'reset_biometric' && in_array($tipe, ['mentor', 'mentee'], true)) {
        try {
            $result = Biometric::resetEnrolled($tipe, $focusId);
            header('Location: ' . $settingsUrl . '&msg=biometric_reset&token=' . urlencode($result['token'])
                . '&expires_at=' . urlencode($result['expires_at']), true, 303);
        } catch (\Throwable $e) {
            header('Location: ' . $settingsUrl . '&error=biometric_reset', true, 303);
        }
        exit;
    }

    if ($action === 'cancel_biometric' && in_array($tipe, ['mentor', 'mentee'], true)) {
        // Batalkan proses pendaftaran: hapus token setup yang belum terpakai
        // agar link yang sudah dibagikan tidak dapat digunakan lagi.
        Biometric::purgeSetupTokens($tipe, $focusId);
        header('Location: ' . $settingsUrl . '&msg=biometric_cancelled', true, 303);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Data untuk render GET
// ---------------------------------------------------------------------------
$master = AdminAuth::filterMasterData(MasterData::loadAll());
$allRows = $master[$cfg['collection']] ?? [];
$rows = array_reverse($allRows);
$record = null;

if ($isSingle) {
    $rows = array_values(array_filter($allRows, static function (array $row) use ($focusId): bool {
        return (string)($row['id'] ?? '') === $focusId;
    }));
    $record = $rows[0] ?? null;
}

$focusName = '';
if ($record !== null) {
    $focusName = (string)($record['nama_lengkap'] ?? $record['nama_tim'] ?? $focusId);
}

$mentorNames = [];
foreach ($master['mentors'] ?? [] as $mentor) {
    $mentorNames[$mentor['id'] ?? ''] = $mentor['nama_lengkap'] ?? '';
}

$teamNames = [];
foreach ($master['teams'] ?? [] as $team) {
    $teamNames[$team['id'] ?? ''] = $team['nama_tim'] ?? '';
}

/**
 * Render avatar bulat (tanpa form unggah) untuk daftar pengaturan.
 */
$renderAvatar = static function (?string $path, string $name): string {
    $url = ProfilePhoto::urlFor($path);
    $trimmed = trim($name);
    $initial = strtoupper(substr($trimmed !== '' ? $trimmed : '?', 0, 1));
    $inner = $url
        ? '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="Foto ' . htmlspecialchars($name, ENT_QUOTES) . '">'
        : '<span class="avatar-initial">' . htmlspecialchars($initial) . '</span>';

    return '<span class="avatar-wrap" style="cursor: default;">' . $inner . '</span>';
};

$columns = match ($tipe) {
    'mentor' => ['Foto', 'Nama', 'WhatsApp', 'Kesibukan', 'Status', 'Aksi'],
    'tim' => ['Foto', 'Nama Tim', 'Mentor', 'Status', 'Aksi'],
    'mentee' => ['Foto', 'Nama Mentee', 'NIM & Kelas', 'Tim', 'Status Keaktifan', 'Aksi'],
};

$messages = [
    'foto_updated' => 'Foto profil berhasil diperbarui.',
    'deleted' => 'Data berhasil dihapus permanen.',
    'archived' => 'Data berhasil dipindahkan ke Arsip Purna.',
    'biometric_cancelled' => 'Proses pendaftaran biometrik dibatalkan. Link pendaftaran tidak dapat digunakan lagi.',
];
$successMessage = $messages[(string)($_GET['msg'] ?? '')] ?? '';

$errorMessage = match ((string)($_GET['error'] ?? '')) {
    'foto' => 'Foto gagal diproses. Gunakan foto (JPG/PNG/WEBP) maksimal 8 MB.',
    'logo_duplikat' => 'Logo ini sudah dipakai'
        . (trim((string)($_GET['tim'] ?? '')) !== '' ? ' oleh ' . trim((string)($_GET['tim'] ?? '')) : ' tim lain')
        . '. Setiap tim harus memakai logo yang berbeda.',
    'relasi' => trim((string)($_GET['pesan'] ?? '')) ?: 'Data tidak dapat dihapus karena masih terhubung dengan data lain.',
    'notfound' => 'Data tidak ditemukan atau sudah dihapus.',
    default => '',
};

$fotoUrl = $record !== null ? ProfilePhoto::urlFor($record['foto_profil'] ?? null) : null;
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);

$summaryFields = [];
if ($record !== null) {
    if ($tipe === 'mentor') {
        $detail = is_array($record['detail_kesibukan'] ?? null) ? $record['detail_kesibukan'] : [];
        $kesibukan = (string)($record['status_kesibukan'] ?? '-');
        $detailText = $kesibukan === 'Bekerja'
            ? trim(($detail['profesi_jabatan'] ?? '') . ' — ' . ($detail['instansi_perusahaan'] ?? ''), ' —')
            : trim(($detail['nim'] ?? '') . ' • ' . Academic::formatClass($detail['kelas'] ?? '', $detail['nim'] ?? '') . ' • ' . ($detail['jadwal_kuliah'] ?? ''), ' •');
        $summaryFields = [
            'Nama Lengkap' => $record['nama_lengkap'] ?? '-',
            'Nomor WhatsApp' => $record['nomor_whatsapp'] ?? '-',
            'Gender' => $record['gender'] ?? '-',
            'Kesibukan' => $kesibukan . ($detailText !== '' ? ' — ' . $detailText : ''),
            'Amanah Organisasi' => $record['amanah_organisasi'] ?? '-',
            'Status' => $record['status'] ?? 'Aktif',
        ];
    } elseif ($tipe === 'tim') {
        $menteeCount = 0;
        $teamId = (string)($record['id'] ?? '');
        foreach ($master['mentees'] ?? [] as $mentee) {
            if ((string)($mentee['tim_id'] ?? '') === $teamId) {
                $menteeCount++;
            }
        }
        $summaryFields = [
            'Nama Tim' => $record['nama_tim'] ?? '-',
            'Mentor Pendamping' => $mentorNames[$record['mentor_id'] ?? ''] ?? ($record['mentor_id'] ?? '-'),
            'Jumlah Mentee' => $menteeCount . ' mentee',
            'Status' => $record['status'] ?? 'Aktif',
        ];
    } else {
        $semester = Academic::semesterFromNim($record['nim'] ?? '');
        $stambuk = Academic::entryYearFromNim($record['nim'] ?? '');
        $summaryFields = [
            'Nama Lengkap' => $record['nama_lengkap'] ?? '-',
            'NIM' => $record['nim'] ?? '-',
            'Kelas' => Academic::formatClass($record['kelas'] ?? '', $record['nim'] ?? '') ?: '-',
            'Semester' => $semester !== null
                ? $semester . ($stambuk !== null ? ' (stambuk ' . $stambuk . ')' : '')
                : '-',
            'Tim' => ($teamNames[$record['tim_id'] ?? ''] ?? '') !== '' ? $teamNames[$record['tim_id'] ?? ''] : ($record['tim_id'] ?? '-'),
            'Mentor' => $mentorNames[$record['mentor_id'] ?? ''] ?? ($record['mentor_id'] ?? '-'),
            'Status Keaktifan' => $record['status_keaktifan'] ?? 'Aktif',
        ];
        if (!empty($record['delegate_for'] ?? '')) {
            $delegateMentor = $mentorNames[$record['delegate_for'] ?? ''] ?? ($record['delegate_for'] ?? '-');
            $summaryFields['Perwakilan Mentor'] = $delegateMentor;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isSingle ? 'Pengaturan ' . $h($focusName !== '' ? $focusName : $focusId) : 'Pengaturan Data ' . $h($cfg['label']) ?> — Kelola Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
    <style>
        .settings-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .settings-head .card-title { margin-bottom: 0; }
        .table-search-bar { margin-bottom: 14px; display: flex; align-items: center; }
        .table-search-input {
            width: 100%;
            max-width: 340px;
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px 12px;
            color: #f8fafc;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .table-search-input:focus { border-color: var(--primary); }
        .row-action {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid #334155;
            background: #1e293b;
            color: #cbd5e1;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .row-action:hover { border-color: #38bdf8; color: #38bdf8; }
        .settings-empty {
            padding: 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* Header pengaturan satu data + tombol hapus di pojok kanan atas */
        .settings-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            text-align: left;
            flex-wrap: wrap;
        }
        .settings-page-header .subpage-back { margin-top: 10px; }
        .btn-delete-top {
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
        .btn-delete-top:hover { background: rgba(239, 68, 68, 0.25); border-color: #ef4444; }

        /* Kartu profil */
        .settings-profile {
            display: flex;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
        }
        .settings-avatar {
            width: 132px;
            height: 132px;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid var(--card-border);
            background: #0b1120;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }
        .settings-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .settings-avatar .avatar-initial { font-size: 2.4rem; }
        .settings-profile-body { flex: 1 1 280px; min-width: 0; }
        .settings-profile-body .card-title { margin-bottom: 4px; }
        .settings-profile-body .photo-fields { margin-top: 8px; }
        .settings-save-photo {
            width: auto !important;
            padding: 9px 18px;
            margin-top: 10px;
            font-size: 0.87rem;
            min-height: 40px;
        }

        /* Ringkasan biodata */
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
            min-width: 0;
        }
        .summary-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 3px;
        }
        .summary-value {
            font-size: 0.92rem;
            color: #e2e8f0;
            word-break: break-word;
        }
        .settings-card-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        /* Modal konfirmasi hapus */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(2, 6, 23, 0.72);
            backdrop-filter: blur(2px);
        }
        .modal-overlay.active { display: flex; }
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
        .modal-option {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 11px 12px;
            border: 1px solid #334155;
            border-radius: 10px;
            margin-bottom: 9px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.87rem;
            color: #e2e8f0;
            line-height: 1.45;
        }
        .modal-option:has(input:checked) {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.08);
        }
        .modal-option input { margin-top: 3px; accent-color: #ef4444; }
        .modal-option small {
            display: block;
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 400;
            margin-top: 2px;
        }
        .modal-impact {
            font-size: 0.8rem;
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.35);
            border-radius: 8px;
            padding: 9px 11px;
            margin: 4px 0 14px;
            line-height: 1.5;
            display: none;
        }
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 16px;
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

        /* Modal link pendaftaran biometrik (memakai komponen Modal UI aplikasi) */
        .biometric-modal { max-width: 520px; }
        .biometric-modal .modal-title { color: var(--primary); }
        .biometric-modal .modal-message { text-align: left; }
        .biometric-modal .biometric-field { text-align: left; }

        @media (max-width: 560px) {
            .summary-grid { grid-template-columns: 1fr; }
            .settings-page-header { flex-direction: column; }
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header<?= $isSingle ? ' settings-page-header' : '' ?>">
        <div>
            <h1 class="page-title"><?= $isSingle ? 'Pengaturan ' . $h($focusName !== '' ? $focusName : $focusId) : 'Pengaturan Data ' . $h($cfg['label']) ?></h1>
            <p class="page-subtitle"><?= $isSingle
                ? 'Kelola foto profil dan data ' . $h(strtolower($cfg['label'])) . ' ini.'
                : 'Pilih data ' . $h(strtolower($cfg['label'])) . ' yang ingin diatur.' ?></p>
            <div class="subpage-back">
                <a href="<?= $h($basePath) ?>#<?= $h($cfg['tab']) ?>">&larr; Kembali ke Data Master</a>
            </div>
        </div>
        <?php if ($record !== null): ?>
            <button type="button" class="btn-delete-top" onclick="openDeleteModal()">&#128465; Hapus Data</button>
        <?php endif; ?>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <?= $h($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= $h($errorMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($isSingle && $record === null): ?>
        <div class="card">
            <div class="settings-empty">
                Data <?= $h(strtolower($cfg['label'])) ?> dengan ID <?= $h($focusId) ?> tidak ditemukan.
            </div>
        </div>
    <?php elseif ($isSingle): ?>
        <div class="card">
            <div class="settings-profile">
                <div class="settings-avatar" id="settings-photo-preview">
                    <?php if ($fotoUrl): ?>
                        <img src="<?= $h($fotoUrl) ?>" alt="Foto <?= $h($focusName) ?>">
                    <?php else: ?>
                        <span class="avatar-initial"><?= $h(strtoupper(substr($focusName !== '' ? $focusName : '?', 0, 1))) ?></span>
                    <?php endif; ?>
                </div>
                <div class="settings-profile-body">
                    <h3 class="card-title"><?= $h($cfg['photo_label']) ?></h3>
                    <form method="post" enctype="multipart/form-data">
            <?= AdminAuth::csrfField() ?>
                        <input type="hidden" name="action" value="upload_photo">
                        <input type="hidden" name="tipe" value="<?= $h($tipe) ?>">
                        <input type="hidden" name="id" value="<?= $h($focusId) ?>">
                        <div class="photo-fields">
                            <input type="file" name="foto_profil" accept="image/*"
                                   class="form-control" data-profile-photo
                                   data-allow-clipboard="true"
                                   <?= $tipe === 'tim' ? 'data-require-face="false" data-crop="contain"' : 'capture="user"' ?>
                                   data-preview="settings-photo-preview" data-info="settings-photo-info">
                            <div class="photo-hint" id="settings-photo-info"><?= $h($cfg['photo_hint']) ?></div>
                            <button type="submit" class="submit-btn settings-save-photo">Simpan Foto</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title">Ringkasan Data</h3>
            <div class="summary-grid">
                <?php foreach ($summaryFields as $label => $value): ?>
                    <div class="summary-item">
                        <span class="summary-label"><?= $h($label) ?></span>
                        <span class="summary-value"><?= $h($value !== '' ? $value : '-') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="settings-card-actions">
                <a class="btn-action" href="<?= $h($basePath . $cfg['edit'] . '?id=' . urlencode($focusId)) ?>">Ubah Biodata</a>
                <?php if ($cfg['is_person']): ?>
                <form method="post" style="display:inline;" data-confirm="Pastikan Anda hanya menekan ini karena ingin menambahkan ke daftar penginput laporan. Jika tidak, batalkan tindakan ini segera. Link harus dibagikan kepada pemilik data untuk disiapkan ulang. Lanjutkan?" data-confirm-title="Atur Ulang Biometrik">
            <?= AdminAuth::csrfField() ?>
                    <input type="hidden" name="action" value="reset_biometric">
                    <input type="hidden" name="tipe" value="<?= $h($tipe) ?>">
                    <input type="hidden" name="id" value="<?= $h($focusId) ?>">
                    <button type="submit" class="btn-action" style="background:rgba(239,68,68,0.12);border-color:#7f1d1d;color:#fca5a5;">Biometrik</button>
                </form>
                <?php endif; ?>
                <a class="btn-cancel" href="<?= $h($basePath) ?>#<?= $h($cfg['tab']) ?>">Kembali</a>
            </div>
        </div>

        <?php
        $bioToken = (string)($_GET['token'] ?? '');
        // Token divalidasi langsung ke penyimpanan: kedaluwarsa, sudah terpakai,
        // atau bukan milik data ini tidak boleh menampilkan modal link.
        $bioTokenData = $bioToken !== '' ? Biometric::validateSetupToken($bioToken) : null;
        $showBiometricModal = ((string)($_GET['msg'] ?? '') === 'biometric_reset')
            && $bioTokenData !== null
            && (string)($bioTokenData['entity_type'] ?? '') === $tipe
            && (string)($bioTokenData['entity_id'] ?? '') === $focusId;
        $bioExpires = $showBiometricModal ? (string)($bioTokenData['expires_at'] ?? '') : '';
        $bioExpiresText = $bioExpires !== '' ? date('d M Y H:i', strtotime($bioExpires)) . ' WIB' : '-';
        ?>
        <?php if ($showBiometricModal): ?>
        <div class="modal-overlay" id="biometric-modal" aria-hidden="true">
            <div class="modal biometric-modal" role="dialog" aria-modal="true" aria-labelledby="biometric-modal-title">
                <div class="modal-icon info">&#128279;</div>
                <h3 class="modal-title" id="biometric-modal-title">Link Pendaftaran Biometrik</h3>
                <p class="modal-message">
                    Bagikan link sekali pakai berikut kepada <b><?= $h($focusName) ?></b> agar segera
                    mendaftarkan data biometrik. Link akan hilang setelah setup berhasil.
                </p>
                <div class="form-group biometric-field" style="margin-bottom: 0;">
                    <label>Link Pendaftaran</label>
                    <div class="link-box" style="display:flex;gap:8px;">
                        <input type="text" class="form-control" value="<?= $h($shareBase . 'setup-biometric?token=' . urlencode($bioToken)) ?>" readonly>
                        <button type="button" class="submit-btn" onclick="copyLink(this)" style="padding:0 14px;">Salin</button>
                    </div>
                    <div class="photo-hint" style="margin-top:6px;">Berlaku hingga <?= $h($bioExpiresText) ?>. Setelah setup berhasil, link ini tidak dapat digunakan lagi.</div>
                </div>
                <div class="modal-actions">
                    <form method="post" style="display:inline;"
                          data-confirm="Membatalkan proses pendaftaran akan menghapus link sekali pakai ini. Link yang sudah dibagikan tidak dapat digunakan lagi. Lanjutkan?"
                          data-confirm-title="Hapus Link Pendaftaran">
            <?= AdminAuth::csrfField() ?>
                        <input type="hidden" name="action" value="cancel_biometric">
                        <input type="hidden" name="tipe" value="<?= $h($tipe) ?>">
                        <input type="hidden" name="id" value="<?= $h($focusId) ?>">
                        <button type="submit" class="modal-btn danger">Hapus</button>
                    </form>
                    <button type="button" class="modal-btn primary" onclick="closeBiometricModal()">Tutup</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="modal-overlay" id="delete-modal" aria-hidden="true">
            <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title">
                <h3 id="delete-modal-title">Hapus Data <?= $h($cfg['label']) ?></h3>
                <p>
                    Konfirmasi alasan penghapusan <b><?= $h($focusName) ?></b>. Tindakan ini tidak dapat
                    dibatalkan dari halaman ini.
                </p>
                <form method="post" id="delete-form">
            <?= AdminAuth::csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tipe" value="<?= $h($tipe) ?>">
                    <input type="hidden" name="id" value="<?= $h($focusId) ?>">

                    <?php if ($cfg['is_person']): ?>
                        <label class="modal-option">
                            <input type="radio" name="delete_reason" value="kesalahan" checked onchange="updateDeleteImpact()">
                            <span>
                                Ada kesalahan data
                                <small>Data dihapus permanen dan tidak disimpan di arsip.</small>
                            </span>
                        </label>
                        <label class="modal-option">
                            <input type="radio" name="delete_reason" value="dikeluarkan" onchange="updateDeleteImpact()">
                            <span>
                                Sudah dikeluarkan dari UKMI
                                <small>Data dipindahkan ke Arsip Purna sebagai tempat khusus mentor/mentee yang telah pergi.</small>
                            </span>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="delete_reason" value="kesalahan">
                        <label class="modal-option">
                            <input type="radio" name="delete_reason" value="kesalahan" checked disabled>
                            <span>
                                Ada kesalahan data
                                <small>Tim hanya dapat dihapus permanen.</small>
                            </span>
                        </label>
                    <?php endif; ?>

                    <div class="modal-impact" id="delete-impact">
                        Data akan dipindahkan ke <b>Arsip Purna</b>, bukan dihapus permanen. Riwayat biodata
                        tetap tersimpan untuk mentor/mentee yang telah pergi.
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="delete-note">Catatan (opsional)</label>
                        <textarea name="delete_note" id="delete-note" class="form-control" rows="2"
                                  placeholder="Mis. dikeluarkan pada sidang organisasi November 2026..."></textarea>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Batal</button>
                        <button type="submit" class="danger-btn">Hapus Data</button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="tab-actions">
            <a href="<?= $h($basePath . $cfg['add']) ?>" class="btn-action">+ Tambah <?= $h($cfg['label']) ?></a>
        </div>

        <div class="card">
            <div class="settings-head">
                <h3 class="card-title">Daftar <?= $h($cfg['label']) ?></h3>
                <span style="color: var(--text-muted); font-size: 0.85rem;"><?= count($rows) ?> data</span>
            </div>

            <?php if ($rows === []): ?>
                <div class="settings-empty">Belum ada data <?= $h(strtolower($cfg['label'])) ?>.</div>
            <?php else: ?>
                <div class="table-search-bar">
                    <input type="text" class="table-search-input" id="settings-search"
                           placeholder="🔍 Cari data <?= $h(strtolower($cfg['label'])) ?>...">
                </div>
                <div class="table-scroll">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <th><?= $h($column) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody id="settings-tbody">
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $id = (string)($row['id'] ?? '');
                                $openUrl = $basePath . 'pengaturan?tipe=' . urlencode($tipe) . '&id=' . urlencode($id);
                                ?>
                                <tr>
                                    <?php if ($tipe === 'mentor'): ?>
                                        <?= $renderAvatar($row['foto_profil'] ?? null, $row['nama_lengkap'] ?? '') ?>
                                        <td><b><?= $h($row['nama_lengkap'] ?? '-') ?></b><br><small><?= $h($id) ?></small></td>
                                        <td><?= $h($row['nomor_whatsapp'] ?? '-') ?></td>
                                        <td><?= $h($row['status_kesibukan'] ?? '-') ?></td>
                                        <td><span class="badge-status aktif"><?= $h($row['status'] ?? 'Aktif') ?></span></td>
                                    <?php elseif ($tipe === 'tim'): ?>
                                        <?= $renderAvatar($row['foto_profil'] ?? null, $row['nama_tim'] ?? '') ?>
                                        <td><b><?= $h($row['nama_tim'] ?? '-') ?></b><br><small><?= $h($id) ?></small></td>
                                        <td><?= $h($mentorNames[$row['mentor_id'] ?? ''] ?? ($row['mentor_id'] ?? '-')) ?></td>
                                        <td><span class="badge-status aktif"><?= $h($row['status'] ?? 'Aktif') ?></span></td>
                                    <?php else: ?>
                                        <?php
                                        $statusBadgeClass = ($row['status_keaktifan'] ?? '') === 'Peringatan'
                                            ? 'peringatan'
                                            : ((($row['status_keaktifan'] ?? '') === 'Tidak Aktif') ? 'tidak_aktif' : 'aktif');
                                        ?>
                                        <?= $renderAvatar($row['foto_profil'] ?? null, $row['nama_lengkap'] ?? '') ?>
                                        <td><b><?= $h($row['nama_lengkap'] ?? '-') ?></b><br><small><?= $h($id) ?></small></td>
                                        <?php $kelasArsip = Academic::formatClass($row['kelas'] ?? '', $row['nim'] ?? ''); ?>
                                        <td><?= $h($row['nim'] ?? '-') ?><?= $kelasArsip !== '' ? ' • ' . $h($kelasArsip) : '' ?></td>
                                        <td><?= $h($row['tim_id'] ?? '-') ?></td>
                                        <td><span class="badge-status <?= $statusBadgeClass ?>"><?= $h($row['status_keaktifan'] ?? 'Aktif') ?></span></td>
                                    <?php endif; ?>
                                    <td>
                                        <a class="row-action" href="<?= $h($openUrl) ?>">&#9881; Atur</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/modal.js?v=3"></script>
<script>
function openDeleteModal() {
    const modal = document.getElementById('delete-modal');
    if (!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    updateDeleteImpact();
}

function closeDeleteModal() {
    const modal = document.getElementById('delete-modal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

function openBiometricModal() {
    const modal = document.getElementById('biometric-modal');
    if (!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function closeBiometricModal() {
    const modal = document.getElementById('biometric-modal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

function updateDeleteImpact() {
    const impact = document.getElementById('delete-impact');
    if (!impact) return;
    const selected = document.querySelector('input[name="delete_reason"]:checked');
    impact.style.display = (selected && selected.value === 'dikeluarkan') ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    // Konfirmasi tindakan berisiko memakai Modal UI milik aplikasi,
    // bukan window.confirm bawaan browser.
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
                    const actionInput = form.querySelector('input[name="action"]');
                    const actionVal = actionInput ? actionInput.value : '';
                    const msg = actionVal === 'reset_biometric'
                        ? 'Menyiapkan link biometrik, mohon tunggu...'
                        : 'Memproses permintaan, mohon tunggu...';
                    window.ukmiShowLoadingOverlay(msg);
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

    const modal = document.getElementById('delete-modal');
    if (modal) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeDeleteModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('active')) closeDeleteModal();
        });
    }

    // Link pendaftaran biometrik tampil sebagai modal sekali pakai,
    // bukan kartu permanen di halaman.
    const bioModal = document.getElementById('biometric-modal');
    if (bioModal) {
        openBiometricModal();
        bioModal.addEventListener('click', (event) => {
            if (event.target === bioModal) closeBiometricModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && bioModal.classList.contains('active')) closeBiometricModal();
        });
    }

    const input = document.getElementById('settings-search');
    const tbody = document.getElementById('settings-tbody');
    if (!input || !tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr'));
    input.addEventListener('input', () => {
        const keyword = input.value.toLowerCase().trim();
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(keyword) ? '' : 'none';
        });
    });
});

function copyLink(btn) {
    const input = btn.previousElementSibling;
    if (!input) return;
    const done = () => {
        const old = btn.textContent;
        btn.textContent = 'Disalin';
        setTimeout(() => { btn.textContent = old; }, 1500);
    };
    const fallback = () => {
        input.select();
        try { document.execCommand('copy'); } catch (e) {}
        done();
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(done).catch(fallback);
    } else {
        fallback();
    }
}
</script>
<?php if ($isSingle): ?>
<script src="<?= $h($appBase) ?>public/assets/js/profile-photo.js"></script>
<?php endif; ?>

</body>
</html>
