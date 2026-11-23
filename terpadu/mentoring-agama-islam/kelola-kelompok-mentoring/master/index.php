<?php
/**
 * public/master/index.php — Manajemen CRUD Data Master (Mentors, Teams, Mentees)
 * Menyediakan antarmuka pengelolaan master data secara mandiri
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\BirthdayReminder;
use Mentoring\MasterData;
use Mentoring\Config;
use Mentoring\ProfilePhoto;
use Mentoring\ReportRepository;
use Mentoring\Watobot;
use Mentoring\BotQueue;

AdminAuth::guard();

$isSuperAdmin = AdminAuth::isSuperAdmin();
$roleGenderLabel = AdminAuth::allowedGenderLabel();
$allowedExportScope = AdminAuth::allowedExportScope();

$allowedBotChannels = $isSuperAdmin
    ? ['ikhwan', 'akhwat']
    : [($allowedExportScope === 'akhwat' ? 'akhwat' : 'ikhwan')];

// Kesempatan mencoba ulang antrean bot secara otomatis setiap admin
// membuka halaman master (hanya untuk channel yang aktif & sesuai role).
$botChannels = [];
$botQueueItems = [];
$botPendingCount = 0;
$botAnyInvalid = false;
$primaryBotChannel = $allowedBotChannels[0];

foreach ($allowedBotChannels as $ch) {
    $cfg = Watobot::channelConfig($ch);
    if ($cfg['enabled']) {
        BotQueue::processDue(1, false, [], $ch);
    }
    $chState = BotQueue::state($ch);
    $chItems = BotQueue::items($ch);
    $botChannels[$ch] = [
        'channel' => $ch,
        'label' => $cfg['label'],
        'enabled' => $cfg['enabled'],
        'group_id' => $cfg['group_id'],
        'has_api_key' => $cfg['api_key'] !== '',
        'state' => $chState,
        'pending_count' => count($chItems),
    ];
    if ($cfg['enabled']) {
        $botQueueItems = array_merge($botQueueItems, $chItems);
        $botPendingCount += count($chItems);
        if (!empty($chState['api_invalid'])) {
            $botAnyInvalid = true;
            $primaryBotChannel = $ch;
        }
    }
}

$botState = $botChannels[$primaryBotChannel]['state'];
$showBotModal = $botAnyInvalid;

$master = AdminAuth::filterMasterData(MasterData::loadAll());
$todayBirthdays = BirthdayReminder::getTodayBirthdays($master);
$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));

$message = '';
$msgMap = [
    'mentor_added' => ['Mentor berhasil ditambahkan!', 'tab-mentors'],
    'mentor_updated' => ['Data mentor berhasil diperbarui!', 'tab-mentors'],
    'team_added' => ['Tim berhasil ditambahkan!', 'tab-teams'],
    'team_updated' => ['Data tim berhasil diperbarui!', 'tab-teams'],
    'mentee_added' => ['Mentee berhasil ditambahkan!', 'tab-mentees'],
    'mentee_updated' => ['Data mentee berhasil diperbarui!', 'tab-mentees'],
    'foto_updated' => ['Foto profil berhasil diperbarui!', ''],
    'deleted' => ['Data berhasil dihapus.', ''],
    'archived' => ['Data berhasil dipindahkan ke Arsip Purna.', 'tab-archive'],
    'report_updated' => ['Laporan berhasil diperbarui!', 'tab-laporan'],
    'report_deleted' => ['Laporan berhasil dihapus.', 'tab-laporan'],
    'report_sent' => ['Laporan berhasil dikirim ulang ke grup WhatsApp!', 'tab-laporan'],
    'report_send_failed' => ['Laporan tersimpan, tetapi pengiriman ke grup WhatsApp masuk antrean ulang.', 'tab-laporan'],
    'registrant_updated' => ['Data pendaftar berhasil diperbarui!', 'tab-pendaftar'],
    'registrant_approved' => ['Pendaftar berhasil diterima dan ditambahkan ke Daftar Mentee!', 'tab-pendaftar'],
    'registrant_deleted' => ['Data pendaftar berhasil dihapus.', 'tab-pendaftar'],
    'bot_api_saved' => ['API bot berhasil diperbarui.', ''],
];
$msgKey = $_GET['msg'] ?? '';
$activeTab = 'tab-mentors';
if (isset($msgMap[$msgKey])) {
    $message = $msgMap[$msgKey][0];
    if ($msgMap[$msgKey][1] !== '') {
        $activeTab = $msgMap[$msgKey][1];
    }
}

$errorMessage = '';
$errorKey = $_GET['error'] ?? '';
if ($errorKey === 'foto') {
    $errorMessage = 'Foto gagal diproses. Gunakan foto selfie (JPG/PNG/WEBP) maksimal 8 MB.';
} elseif ($errorKey === 'relasi') {
    $errorMessage = trim((string)($_GET['pesan'] ?? '')) ?: 'Data tidak dapat dihapus karena masih terhubung dengan data lain.';
} elseif ($errorKey === 'hapus') {
    $errorMessage = 'Data gagal dihapus.';
} elseif ($errorKey === 'notfound') {
    $errorMessage = 'Data tidak ditemukan atau sudah dihapus.';
} elseif ($errorKey === 'report_notfound') {
    $errorMessage = 'Laporan tidak ditemukan atau sudah dihapus.';
} elseif ($errorKey === 'simpan' || $errorKey === 'storage' || $errorKey === 'muat') {
    $errorMessage = trim((string)($_GET['pesan'] ?? '')) ?: 'Data gagal disimpan. Data lama tidak diubah.';
} elseif ($errorKey === 'konflik') {
    $errorMessage = 'Data master sudah berubah oleh proses lain. Muat ulang halaman lalu ulangi perubahan.';
} elseif ($errorKey === 'kosong') {
    $errorMessage = 'Penyimpanan dibatalkan karena data master terbaca kosong. Data lama tetap aman.';
} elseif ($errorKey === 'validasi') {
    $errorMessage = trim((string)($_GET['pesan'] ?? '')) ?: 'Data tidak lolos validasi skema master.';
} elseif ($errorKey === 'logo_duplikat') {
    $errorMessage = 'Logo ini sudah dipakai tim lain. Gunakan logo yang berbeda.';
} elseif ($errorKey === 'biometric_reset') {
    $errorMessage = 'Gagal mereset biometrik. Coba lagi.';
}

// Tab yang diminta setelah aksi hapus / unggah foto
$requestedTab = (string)($_GET['tab'] ?? '');
if (in_array($requestedTab, ['tab-mentors', 'tab-teams', 'tab-mentees', 'tab-export', 'tab-archive', 'tab-laporan', 'tab-pendaftar'], true)) {
    $activeTab = $requestedTab;
}

// ---- Data tab Kelola Laporan (daftar + rekap) ----
$laporanAll = ReportRepository::summaries();
if ($roleGenderLabel !== null) {
    $teamGenders = ReportRepository::teamGenderMap();
    $laporanAll = array_values(array_filter($laporanAll, static function (array $s) use ($roleGenderLabel, $teamGenders): bool {
        $g = $teamGenders['tim:' . $s['tim_id']] ?? $teamGenders['nama:' . $s['nama_tim']] ?? '';
        return $g === $roleGenderLabel;
    }));
}
$laporanBulan = (string)($_GET['laporan_bulan'] ?? '');
$laporanTim = (string)($_GET['laporan_tim'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $laporanBulan)) {
    $laporanBulan = '';
}

$laporanFiltered = array_values(array_filter($laporanAll, static function (array $s) use ($laporanBulan, $laporanTim): bool {
    $tanggal = $s['tanggal'] !== '' ? $s['tanggal'] : substr($s['created_at'], 0, 10);
    if ($laporanBulan !== '' && substr($tanggal, 0, 7) !== $laporanBulan) {
        return false;
    }
    if ($laporanTim !== '' && 'nama:' . $s['nama_tim'] !== $laporanTim) {
        return false;
    }
    return true;
}));

$laporanRecap = ReportRepository::recap($laporanFiltered);

$opsiBulan = [];
$opsiTim = [];
foreach ($laporanAll as $s) {
    $tanggal = $s['tanggal'] !== '' ? $s['tanggal'] : substr($s['created_at'], 0, 10);
    if (preg_match('/^(\d{4}-\d{2})/', $tanggal, $m)) {
        $opsiBulan[$m[1]] = true;
    }
    $opsiTim['nama:' . $s['nama_tim']] = $s['nama_tim'];
}
krsort($opsiBulan);
asort($opsiTim);

$bulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$formatBulan = static function (string $ym) use ($bulanIndo): string {
    [$y, $m] = array_pad(explode('-', $ym, 2), 2, '');
    return ($bulanIndo[(int)$m] ?? $m) . ' ' . $y;
};

/**
 * Render sel avatar (hanya tampilan; ubah foto dilakukan di halaman pengaturan).
 */
$renderAvatar = function (string $entity, string $id, ?string $path, string $name): string {
    $url = ProfilePhoto::urlFor($path);
    $trimmed = trim($name);
    $initial = strtoupper(substr($trimmed !== '' ? $trimmed : '?', 0, 1));
    $inner = $url
        ? '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="Foto ' . htmlspecialchars($name, ENT_QUOTES) . '">'
        : '<span class="avatar-initial">' . htmlspecialchars($initial) . '</span>';

    return '<td><span class="avatar-wrap" style="cursor: default;">' . $inner . '</span></td>';
};

/**
 * Render kolom aksi: satu tombol Pengaturan (ubah biodata, foto, hapus, arsip
 * semuanya tersedia di subhalaman pengaturan).
 */
$renderActions = function (string $entity, string $editSlug, string $id) use ($basePath): string {
    $settingsType = $entity === 'team' ? 'tim' : $entity;
    $settingsUrl = $basePath . 'pengaturan?tipe=' . urlencode($settingsType) . '&id=' . urlencode($id);

    return '<td><a class="row-action settings" href="' . htmlspecialchars($settingsUrl, ENT_QUOTES) . '">&#9881; Pengaturan</a></td>';
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Data Master Mentoring MAI</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(mentoring_base_path(dirname(__DIR__))) ?>public/assets/css/style.css">
    <style>
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--card-border); padding-bottom: 12px; overflow-x: auto; -webkit-overflow-scrolling: touch; flex-wrap: nowrap; scrollbar-width: thin; }
        .tab-btn { background: none; border: none; color: var(--text-muted); font-size: 1rem; font-weight: 600; padding: 8px 16px; border-radius: 8px; cursor: pointer; white-space: nowrap; flex: 0 0 auto; }
        .tab-btn.active { background: var(--primary); color: #fff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #334155;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pagination-info {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .pagination-controls {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: nowrap;
            max-width: 100%;
            overflow: hidden;
        }
        .page-nav-btn {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .page-nav-btn:hover:not(:disabled) {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .page-nav-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .page-nav-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 600;
        }
        .page-nav-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            padding: 6px 6px;
            font-size: 0.85rem;
            font-weight: 600;
            user-select: none;
            flex-shrink: 0;
        }

        /* Search input bar on table cards */
        .table-search-bar {
            margin-bottom: 14px;
            display: flex;
            align-items: center;
        }
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
        .table-search-input:focus {
            border-color: var(--primary);
        }

        /* Aksi baris (ubah / hapus) */
        .row-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .row-action {
            display: inline-block;
            white-space: nowrap;
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid #334155;
            background: #1e293b;
            color: #cbd5e1;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        .row-action.edit:hover,
        .row-action.settings:hover { border-color: #38bdf8; color: #38bdf8; }
        .row-action.delete {
            background: rgba(239, 68, 68, 0.12);
            border-color: #7f1d1d;
            color: #fca5a5;
        }
        .row-action.delete:hover { background: rgba(239, 68, 68, 0.25); border-color: #ef4444; }

        /* Kolom aksi cukup selebar tombolnya agar tidak turun baris */
        .attendance-table th:last-child,
        .attendance-table td:last-child {
            width: 1%;
            white-space: nowrap;
        }

        .tab-actions { gap: 10px; flex-wrap: wrap; }
        .export-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .export-actions .btn-action { padding: 9px 14px; min-height: 40px; font-size: 0.85rem; }

        /* Tab Kelola Laporan */
        .laporan-filter {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 14px;
        }
        .filter-field { display: flex; flex-direction: column; gap: 6px; flex: 1 1 160px; min-width: 150px; }
        .filter-field select { min-height: 38px; }
        .laporan-filter .searchable-select-trigger {
            min-height: 38px;
            padding: 7px 12px;
            font-size: 0.875rem;
            border-radius: 8px;
        }
        .laporan-filter .searchable-select-panel {
            min-width: 210px;
            right: 0;
            left: auto;
        }
        .laporan-filter .btn-action { width: auto; min-height: 38px; padding: 7px 14px; }
        .laporan-filter .btn-cancel {
            min-height: 38px;
            padding: 7px 12px;
            font-size: 0.875rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
        }

        .recap-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 18px; }
        .bot-stat-box { display: flex; flex-direction: column; justify-content: space-between; gap: 8px; }
        .bot-stat-header { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
        .bot-stat-switches { display: flex; flex-direction: column; gap: 6px; }
        .bot-switch { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 3px 8px; border-radius: 999px; background: rgba(15, 23, 42, 0.55); border: 1px solid var(--card-border); font-size: 0.76rem; font-weight: 600; color: #cbd5e1; cursor: pointer; user-select: none; }
        .bot-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
        .bot-switch-track { position: relative; width: 28px; height: 15px; border-radius: 999px; background: #475569; transition: background 0.18s ease; flex-shrink: 0; }
        .bot-switch-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 11px; height: 11px; border-radius: 50%; background: #f8fafc; transition: transform 0.18s ease; }
        .bot-switch input:checked + .bot-switch-track { background: #10b981; }
        .bot-switch input:checked + .bot-switch-track::after { transform: translateX(13px); }
        .bot-switch input:disabled + .bot-switch-track { opacity: 0.6; }
        .bot-gear-btn { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 6px; background: rgba(15, 23, 42, 0.65); border: 1px solid var(--card-border); color: #cbd5e1; font-size: 0.85rem; cursor: pointer; transition: border-color 0.15s, color 0.15s; flex-shrink: 0; }
        .bot-gear-btn:hover { border-color: #38bdf8; color: #38bdf8; }
        .stat-box { background: #0b1120; border: 1px solid #334155; border-radius: 12px; padding: 14px; }
        .stat-box .stat-label { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-box .stat-value { margin-top: 4px; font-size: 1.45rem; font-weight: 700; color: #f1f5f9; line-height: 1.2; }
        .stat-box .stat-value small { font-size: 0.78rem; font-weight: 600; color: #64748b; }
        .recap-cta { display: flex; justify-content: flex-end; }
        .recap-cta .btn-action { width: auto; min-height: 44px; padding: 10px 18px; font-size: 0.9rem; }
        .row-action.manage { background: rgba(2, 132, 199, 0.14); border-color: #075985; color: #7dd3fc; }
        .row-action.manage:hover { background: rgba(2, 132, 199, 0.28); border-color: #38bdf8; color: #e0f2fe; }

        /* Status & modal bot WhatsApp */
        .bot-status-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 0.88rem;
            background: rgba(2, 132, 199, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #7dd3fc;
        }
        .bot-status-bar.invalid {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.45);
            color: #fca5a5;
        }
        .bot-status-bar .bot-status-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .flash-message { transition: opacity 0.35s ease, transform 0.35s ease; }
        .flash-message.hide { opacity: 0; transform: translateY(-4px); pointer-events: none; }
        .modal.bot-modal {
            max-width: 560px;
            max-height: calc(100vh - 32px);
            overflow-y: auto;
        }
        .bot-modal .modal-title,
        .bot-modal .modal-message { text-align: left; }
        .bot-modal .modal-message { margin-bottom: 14px; }
        .bot-modal .modal-icon { margin: 0 0 14px; }
        .bot-queue-list {
            list-style: none;
            margin: 0 0 16px;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 150px;
            overflow-y: auto;
        }
        .bot-queue-list li {
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 0.8rem;
            color: #cbd5e1;
            line-height: 1.45;
        }
        .bot-queue-list .queue-error { color: #f87171; display: block; margin-top: 2px; }
        .bot-modal .form-group { margin-bottom: 12px; text-align: left; }
        .bot-modal .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #cbd5e1; margin-bottom: 5px; }
        .bot-modal .form-control {
            width: 100%;
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 9px 11px;
            color: #f8fafc;
            font-family: inherit;
            font-size: 0.86rem;
            outline: none;
        }
        .bot-modal .form-control:focus { border-color: #38bdf8; }
        .bot-modal .modal-actions { justify-content: flex-end; flex-wrap: wrap; }
        .bot-modal .modal-btn { min-width: 0; padding: 9px 16px; }
        .bot-hint { font-size: 0.76rem; color: #94a3b8; margin-top: 4px; line-height: 1.45; }
        .bot-form-message { font-size: 0.82rem; margin-bottom: 10px; display: none; line-height: 1.45; }
        .bot-form-message.info { display: block; color: #7dd3fc; }
        .bot-form-message.error { display: block; color: #f87171; }
        .bot-form-message.success { display: block; color: #34d399; }

        /* Mobile Hamburger Menu & Drawer */
        .page-header.master-header { display: none; }
        .master-nav-row {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 14px;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 10px;
        }
        .master-nav-row .tabs {
            flex: 1 1 auto;
            min-width: 0;
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }
        .mobile-menu-btn {
            flex: 0 0 auto;
            width: 40px;
            height: 40px;
            background: #1e293b;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            color: #f8fafc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }
        .mobile-menu-btn:hover,
        .mobile-menu-btn:focus-visible {
            background: #273449;
            border-color: var(--primary);
            outline: none;
        }
        .mobile-drawer-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }
        .mobile-drawer-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .mobile-drawer {
            width: min(300px, 85vw);
            height: 100%;
            background: var(--card-bg);
            border-right: 1px solid var(--card-border);
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            transform: translateX(-100%);
            transition: transform 0.2s ease;
            overflow-y: auto;
        }
        .mobile-drawer-overlay.active .mobile-drawer {
            transform: translateX(0);
        }
        .mobile-drawer-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--card-border);
        }
        .mobile-drawer-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #f1f5f9;
            line-height: 1.3;
        }
        .mobile-drawer-subtitle {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 4px;
            line-height: 1.4;
        }
        .mobile-drawer-close {
            flex: 0 0 auto;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #0b1120;
            border: 1px solid #334155;
            color: #cbd5e1;
            font-size: 1.25rem;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .mobile-drawer-links {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .mobile-drawer-links a {
            padding: 12px 14px;
            border-radius: 10px;
            background: #0b1120;
            border: 1px solid #334155;
            color: #38bdf8;
            text-decoration: none;
            font-size: 0.92rem;
            font-weight: 600;
            transition: background 0.15s, border-color 0.15s;
        }
        .mobile-drawer-links a:hover {
            background: #172033;
            border-color: var(--primary);
        }
        .mobile-pendaftar-indicator {
            position: absolute;
            right: -8px;
            top: 20px;
            transform: translateY(-50%);
            height: 36px;
            min-width: 15px;
            padding: 0 3px 0 5px;
            border-radius: 100% 0 0 100% / 50% 0 0 50%;
            background: rgba(30, 41, 59, 0.94);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(56, 189, 248, 0.45);
            border-right: none;
            box-shadow: -4px 0 12px rgba(0, 0, 0, 0.35);
            color: #7dd3fc;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            white-space: nowrap;
            z-index: 20;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .mobile-pendaftar-indicator.is-hidden {
            opacity: 0;
            pointer-events: none;
            transform: translate(100%, -50%);
        }

        @media (max-width: 640px) {
            #tab-pendaftar .tab-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 10px;
            }
            #tab-pendaftar .tab-actions .btn-action {
                min-height: 36px;
                padding: 7px 10px;
                font-size: 0.82rem;
                border-radius: 8px;
                width: 100%;
            }
        }

        @media (min-width: 1024px) {
            .container { max-width: 1280px !important; }
            .mobile-menu-btn,
            .mobile-drawer-overlay,
            .mobile-pendaftar-indicator { display: none !important; }
            .master-nav-row {
                display: block;
                border-bottom: none;
                padding-bottom: 0;
                margin-bottom: 0;
            }
            .master-nav-row .tabs {
                margin-bottom: 14px;
                padding-bottom: 10px;
                border-bottom: 1px solid var(--card-border);
                flex-wrap: wrap;
                overflow-x: visible;
            }
            .page-header.master-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                text-align: left;
                margin-bottom: 16px;
            }
            .master-header-links {
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                align-items: center;
            }
            .tabs { margin-bottom: 14px; padding-bottom: 10px; flex-wrap: wrap; overflow-x: visible; }
            .card-toolbar {
                display: flex;
                align-items: center;
                justify-content: flex-start;
                gap: 16px;
                flex-wrap: nowrap;
                margin-bottom: 14px;
            }
            .card-toolbar .card-title { margin-bottom: 0; flex: 0 0 auto; white-space: nowrap; }
            .card-toolbar .table-search-bar { margin-bottom: 0; flex: 1 1 auto; max-width: none; }
            .card-toolbar .table-search-input { max-width: none; width: 100%; }
            .card-toolbar .tab-actions { margin-bottom: 0; flex: 0 0 auto; }
            .card-toolbar .laporan-filter { margin-bottom: 0; flex: 0 0 auto; flex-wrap: nowrap; }
            .card-toolbar .laporan-filter .filter-field { flex: 0 0 175px; width: 175px; }
            .tab-split-desktop {
                display: grid;
                grid-template-columns: 330px minmax(0, 1fr);
                gap: 18px;
                align-items: start;
            }
            .tab-split-desktop .recap-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header master-header">
        <div class="master-header-title">
            <h1 class="page-title">Kelola Data Master Mentoring</h1>
            <p class="page-subtitle">Manajemen Biodata Mentor, Tim Mentoring, dan Mentee</p>
        </div>
        <div class="master-header-links" style="margin-top: 10px; display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
            <?php if ($roleGenderLabel !== null): ?>
                <span style="padding: 4px 10px; border-radius: 999px; background: rgba(56, 189, 248, 0.14); border: 1px solid rgba(56, 189, 248, 0.35); color: #7dd3fc; font-size: 0.78rem; font-weight: 700;">Akses <?= htmlspecialchars($roleGenderLabel) ?></span>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(mentoring_base_path(dirname(__DIR__))) ?>" style="color: #38bdf8; text-decoration: none; font-size: 0.9rem;">&larr; Kembali ke Form Pelaporan</a>
            <?php if ($isSuperAdmin): ?>
                <a href="<?= htmlspecialchars($basePath) ?>backup" style="color: #38bdf8; text-decoration: none; font-size: 0.9rem;">Backup Sistem</a>
                <a href="<?= htmlspecialchars($basePath) ?>restore" style="color: #38bdf8; text-decoration: none; font-size: 0.9rem;">Pulihkan / Rollback</a>
            <?php endif; ?>
            <a href="/login?logout=1" style="color: #fca5a5; text-decoration: none; font-size: 0.9rem;">Keluar</a>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="flash-message" data-flash style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php
        $draftClearMap = [
            'mentor_added' => 'mai_master_draft_mentor_v1',
            'team_added' => 'mai_master_draft_tim_v1',
            'mentee_added' => 'mai_master_draft_mentee_v1',
        ];
        if (isset($draftClearMap[$msgKey])):
        ?>
        <script>
        try { localStorage.removeItem(<?= json_encode($draftClearMap[$msgKey]) ?>); } catch (e) {}
        </script>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="flash-message" data-flash style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($botPendingCount > 0 || $botAnyInvalid): ?>
        <div class="bot-status-bar <?= $botAnyInvalid ? 'invalid' : '' ?>">
            <span>
                <?php if ($botAnyInvalid): ?>
                    <b>API bot <?= htmlspecialchars($botChannels[$primaryBotChannel]['label']) ?> kedaluwarsa.</b>
                <?php else: ?>
                    <b>Pengiriman bot tertunda.</b>
                <?php endif; ?>
                <?= (int)$botPendingCount ?> pesan menunggu antrean.
            </span>
            <span class="bot-status-actions">
                <button type="button" class="row-action manage" onclick="openBotModal(<?= htmlspecialchars(json_encode($primaryBotChannel), ENT_QUOTES) ?>)">Kelola Bot</button>
            </span>
        </div>
    <?php endif; ?>

    <?= BirthdayReminder::renderBanner($todayBirthdays, $appBase . 'ultah?from=master') ?>

    <?php
    $registrants = array_reverse(array_values(array_filter($master['registrants'] ?? [], 'is_array')));
    $statusPriority = [
        'Menunggu' => 1,
        'Cadangan' => 2,
        'Ditolak' => 3,
        'Disetujui' => 4,
    ];
    usort($registrants, static function (array $a, array $b) use ($statusPriority): int {
        $stA = (string)($a['status_pendaftaran'] ?? 'Menunggu');
        $stB = (string)($b['status_pendaftaran'] ?? 'Menunggu');
        $prioA = $statusPriority[$stA] ?? 99;
        $prioB = $statusPriority[$stB] ?? 99;
        if ($prioA !== $prioB) {
            return $prioA <=> $prioB;
        }
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    $regStats = ['total' => 0, 'Menunggu' => 0, 'Disetujui' => 0, 'Cadangan' => 0, 'Ditolak' => 0, 'ikhwan' => 0, 'akhwat' => 0];
    foreach ($registrants as $reg) {
        $st = (string)($reg['status_pendaftaran'] ?? 'Menunggu');
        if (isset($regStats[$st])) {
            $regStats[$st]++;
        }
        if ($st !== 'Disetujui') {
            $regStats['total']++;
            if (($reg['gender'] ?? '') === 'Laki-laki') {
                $regStats['ikhwan']++;
            } elseif (($reg['gender'] ?? '') === 'Perempuan') {
                $regStats['akhwat']++;
            }
        }
    }
    $pendingRegistrantsCount = $regStats['total'];
    $publicDaftarUrl = mentoring_request_origin() . $appBase . 'daftar';
    ?>

    <div class="master-nav-row">
        <button type="button" class="mobile-menu-btn" id="mobile-menu-btn" aria-label="Buka menu" aria-expanded="false" aria-controls="mobile-menu-drawer">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
        <div class="tabs">
            <button class="tab-btn <?= $activeTab === 'tab-mentors' ? 'active' : '' ?>" onclick="switchTab('tab-mentors', this)">Daftar Mentor (<?= count($master['mentors']) ?>)</button>
            <button class="tab-btn <?= $activeTab === 'tab-teams' ? 'active' : '' ?>" onclick="switchTab('tab-teams', this)">Daftar Tim (<?= count($master['teams']) ?>)</button>
            <button class="tab-btn <?= $activeTab === 'tab-mentees' ? 'active' : '' ?>" onclick="switchTab('tab-mentees', this)">Daftar Mentee (<?= count($master['mentees']) ?>)</button>
            <button class="tab-btn <?= $activeTab === 'tab-export' ? 'active' : '' ?>" onclick="switchTab('tab-export', this)">Ekspor Excel</button>
            <button class="tab-btn <?= $activeTab === 'tab-archive' ? 'active' : '' ?>" onclick="switchTab('tab-archive', this)">Arsip Purna (<?= count($master['archived_mentors'] ?? []) + count($master['archived_mentees'] ?? []) ?>)</button>
            <button class="tab-btn <?= $activeTab === 'tab-laporan' ? 'active' : '' ?>" onclick="switchTab('tab-laporan', this)">Kelola Laporan (<?= count($laporanAll) ?>)</button>
            <button class="tab-btn <?= $activeTab === 'tab-pendaftar' ? 'active' : '' ?>" onclick="switchTab('tab-pendaftar', this)">Pendaftar (<?= (int)$pendingRegistrantsCount ?>)</button>
        </div>
        <?php if ($pendingRegistrantsCount > 0): ?>
            <button type="button" class="mobile-pendaftar-indicator" id="mobile-pendaftar-indicator" title="Ada <?= (int)$pendingRegistrantsCount ?> pendaftar" aria-label="Buka tab Pendaftar (<?= (int)$pendingRegistrantsCount ?>)">
                <span><?= (int)$pendingRegistrantsCount ?></span>
            </button>
        <?php endif; ?>
    </div>

    <div class="mobile-drawer-overlay" id="mobile-menu-drawer" aria-hidden="true">
        <div class="mobile-drawer" role="dialog" aria-modal="true" aria-labelledby="mobile-drawer-title">
            <div class="mobile-drawer-header">
                <div>
                    <div class="mobile-drawer-title" id="mobile-drawer-title">Kelola Data Master Mentoring</div>
                    <div class="mobile-drawer-subtitle">Manajemen Biodata Mentor, Tim Mentoring, dan Mentee</div>
                </div>
                <button type="button" class="mobile-drawer-close" id="mobile-drawer-close" aria-label="Tutup menu">&times;</button>
            </div>
            <nav class="mobile-drawer-links" aria-label="Navigasi Master">
                <?php if ($roleGenderLabel !== null): ?>
                    <div style="padding: 8px 12px; border-radius: 8px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.3); color: #7dd3fc; font-size: 0.82rem; font-weight: 700;">Mode: Akses <?= htmlspecialchars($roleGenderLabel) ?></div>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(mentoring_base_path(dirname(__DIR__))) ?>">&larr; Kembali ke Form Pelaporan</a>
                <?php if ($isSuperAdmin): ?>
                    <a href="<?= htmlspecialchars($basePath) ?>backup">Backup Sistem</a>
                    <a href="<?= htmlspecialchars($basePath) ?>restore">Pulihkan / Rollback</a>
                <?php endif; ?>
                <a href="/login?logout=1" style="color: #fca5a5;">Keluar</a>
            </nav>
        </div>
    </div>

    <!-- TAB 1: MENTOR -->
    <div id="tab-mentors" class="tab-content <?= $activeTab === 'tab-mentors' ? 'active' : '' ?>">
        <div class="card card-paginated">
            <div class="card-toolbar">
                <h3 class="card-title">Daftar Mentor Aktif</h3>
                <div class="table-search-bar">
                    <input type="text" class="table-search-input" placeholder="🔍 Cari nama, ID, WA, kesibukan...">
                </div>
                <div class="tab-actions">
                    <a href="<?= htmlspecialchars($basePath) ?>tambah-mentor" class="btn-action">+ Tambah Mentor</a>
                </div>
            </div>
            <div class="table-scroll">
                <table class="attendance-table">
                    <thead>
                        <tr><th>Foto</th><th>Nama</th><th>WhatsApp</th><th>Status</th><th>Kesibukan</th><th>Aksi</th></tr>
                    </thead>
                    <tbody class="paginated-tbody">
                        <?php foreach (array_reverse($master['mentors']) as $m): ?>
                            <tr>
                                <?= $renderAvatar('mentor', $m['id'], $m['foto_profil'] ?? null, $m['nama_lengkap']) ?>
                                <td><b><?= htmlspecialchars($m['nama_lengkap']) ?></b><br><small><?= htmlspecialchars($m['id']) ?></small></td>
                                <td><?= htmlspecialchars($m['nomor_whatsapp']) ?></td>
                                <td><span class="badge-status aktif"><?= htmlspecialchars($m['status']) ?></span></td>
                                <td><?= htmlspecialchars($m['status_kesibukan']) ?></td>
                                <?= $renderActions('mentor', 'mentor', $m['id']) ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: TEAMS -->
    <div id="tab-teams" class="tab-content <?= $activeTab === 'tab-teams' ? 'active' : '' ?>">
        <div class="card card-paginated">
            <div class="card-toolbar">
                <h3 class="card-title">Daftar Tim Mentoring</h3>
                <div class="table-search-bar">
                    <input type="text" class="table-search-input" placeholder="🔍 Cari nama tim, ID mentor...">
                </div>
                <div class="tab-actions">
                    <a href="<?= htmlspecialchars($basePath) ?>tambah-tim" class="btn-action">+ Tambah Tim</a>
                </div>
            </div>
            <div class="table-scroll">
                <table class="attendance-table">
                    <thead>
                        <tr><th>Foto</th><th>Nama Tim</th><th>Mentor ID</th><th>Status</th><th>Aksi</th></tr>
                    </thead>
                    <tbody class="paginated-tbody">
                        <?php foreach (array_reverse($master['teams']) as $t): ?>
                            <tr>
                                <?= $renderAvatar('team', $t['id'], $t['foto_profil'] ?? null, $t['nama_tim']) ?>
                                <td><b><?= htmlspecialchars($t['nama_tim']) ?></b></td>
                                <td><?= htmlspecialchars($t['mentor_id']) ?></td>
                                <td><span class="badge-status aktif"><?= htmlspecialchars($t['status']) ?></span></td>
                                <?= $renderActions('team', 'tim', $t['id']) ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 3: MENTEES -->
    <div id="tab-mentees" class="tab-content <?= $activeTab === 'tab-mentees' ? 'active' : '' ?>">
        <div class="card card-paginated">
            <div class="card-toolbar">
                <h3 class="card-title">Daftar Mentee &amp; Tracking Keaktifan</h3>
                <div class="table-search-bar">
                    <input type="text" class="table-search-input" placeholder="🔍 Cari nama mentee, NIM, kelas, tim...">
                </div>
                <div class="tab-actions">
                    <a href="<?= htmlspecialchars($basePath) ?>tambah-mentee" class="btn-action">+ Tambah Mentee</a>
                </div>
            </div>
            <div class="table-scroll">
                <table class="attendance-table">
                    <thead>
                        <tr><th>Foto</th><th>Nama Mentee</th><th>NIM & Kelas</th><th>Tim ID</th><th>Status Keaktifan</th><th>Absen Beruntun</th><th>Aksi</th></tr>
                    </thead>
                    <tbody class="paginated-tbody">
                        <?php foreach (array_reverse($master['mentees']) as $me): ?>
                            <?php 
                                $statusBadgeClass = ($me['status_keaktifan'] === 'Peringatan') ? 'peringatan' : (($me['status_keaktifan'] === 'Tidak Aktif') ? 'tidak_aktif' : 'aktif');
                            ?>
                            <tr>
                                <?= $renderAvatar('mentee', $me['id'], $me['foto_profil'] ?? null, $me['nama_lengkap']) ?>
                                <td><b><?= htmlspecialchars($me['nama_lengkap']) ?></b></td>
                                <?php $kelasTampil = Academic::formatClass($me['kelas'] ?? '', $me['nim'] ?? ''); ?>
                                <td><?= htmlspecialchars($me['nim'] ?? '-') ?><?= $kelasTampil !== '' ? ' • ' . htmlspecialchars($kelasTampil) : '' ?></td>
                                <td><?= htmlspecialchars($me['tim_id'] ?? '-') ?></td>
                                <td><span class="badge-status <?= $statusBadgeClass ?>"><?= htmlspecialchars($me['status_keaktifan'] ?? 'Aktif') ?></span></td>
                                <td><?= (int)($me['consecutive_absent'] ?? 0) ?>x</td>
                                <?= $renderActions('mentee', 'mentee', $me['id']) ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 5: ARSIP PURNA -->
    <div id="tab-archive" class="tab-content <?= $activeTab === 'tab-archive' ? 'active' : '' ?>">
        <div class="card card-paginated">
            <?php if ($archivedRows === []): ?>
                <h3 class="card-title">Arsip Purna — Mentor &amp; Mentee yang Telah Pergi</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0 0 16px;">
                    Tempat khusus data mentor dan mentee yang sudah dikeluarkan dari UKMI. Data di sini
                    tidak lagi muncul pada daftar aktif, tetapi riwayat biodatanya tetap tersimpan.
                </p>
                <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                    Belum ada data di Arsip Purna.
                </div>
            <?php else: ?>
                <div class="card-toolbar">
                    <h3 class="card-title">Arsip Purna</h3>
                    <div class="table-search-bar">
                        <input type="text" class="table-search-input" id="archive-search" placeholder="🔍 Cari nama, jenis, atau alasan...">
                    </div>
                </div>
                <div class="table-scroll">
                    <table class="attendance-table">
                        <thead>
                            <tr><th>Foto</th><th>Nama</th><th>Jenis</th><th>Alasan</th><th>Catatan</th><th>Diarsipkan</th></tr>
                        </thead>
                        <tbody id="archive-tbody" class="paginated-tbody">
                            <?php foreach ($archivedRows as $entry): ?>
                                <?php $row = $entry['row']; $nama = $row['nama_lengkap'] ?? $row['nama_tim'] ?? '-'; ?>
                                <tr>
                                    <?= $renderAvatar($entry['jenis'], $row['id'] ?? '', $row['foto_profil'] ?? null, (string)$nama) ?>
                                    <td><b><?= htmlspecialchars((string)$nama) ?></b><br><small><?= htmlspecialchars((string)($row['id'] ?? '')) ?></small></td>
                                    <td><span class="badge-status <?= $entry['jenis'] === 'Mentor' ? 'aktif' : 'peringatan' ?>"><?= htmlspecialchars($entry['jenis']) ?></span></td>
                                    <td><?= htmlspecialchars((string)($row['archive_reason'] ?? 'Dikeluarkan dari UKMI')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['archive_note'] ?? '')) !== '' ? htmlspecialchars((string)$row['archive_note']) : '-' ?></td>
                                    <td>
                                        <?php
                                        $archivedAt = (string)($row['archived_at'] ?? '');
                                        $ts = $archivedAt !== '' ? strtotime($archivedAt) : false;
                                        echo htmlspecialchars($ts ? date('d M Y', $ts) : '-');
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 6: KELOLA LAPORAN -->
    <div id="tab-laporan" class="tab-content <?= $activeTab === 'tab-laporan' ? 'active' : '' ?>">
        <div class="tab-split-desktop">
        <div class="card">
            <h3 class="card-title">Rekap Laporan Mentoring</h3>

            <div class="recap-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Laporan</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_laporan'] ?> <small>laporan</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Hadir</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_hadir'] ?> <small>kehadiran</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Izin</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_izin'] ?> <small>izin</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Absen</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_absen'] ?> <small>absen</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Tingkat Kehadiran</div>
                    <div class="stat-value"><?= htmlspecialchars((string)ReportRepository::attendanceRate((int)$laporanRecap['total_hadir'], (int)$laporanRecap['total_izin'], (int)$laporanRecap['total_absen'])) ?>%</div>
                </div>
                <div class="stat-box bot-stat-box" aria-label="Pengaturan Bot WhatsApp">
                    <div class="stat-label bot-stat-header">
                        <span>Bot WhatsApp</span>
                        <button type="button" class="bot-gear-btn" onclick="openBotModal()" title="Pengaturan API &amp; Grup Bot" aria-label="Pengaturan Bot">&#9881;</button>
                    </div>
                    <div class="bot-stat-switches">
                        <?php foreach ($allowedBotChannels as $ch): ?>
                            <?php
                            $chInfo = $botChannels[$ch];
                            $isBotOn = !empty($chInfo['enabled']);
                            $shortLabel = count($allowedBotChannels) > 1 ? $chInfo['label'] : 'Aktif';
                            ?>
                            <label class="bot-switch" title="<?= $isBotOn ? 'Matikan' : 'Aktifkan' ?> Bot <?= htmlspecialchars($chInfo['label']) ?>">
                                <span><?= htmlspecialchars($shortLabel) ?></span>
                                <input type="checkbox" <?= $isBotOn ? 'checked' : '' ?>
                                       onchange="toggleBotFeature(<?= htmlspecialchars(json_encode($ch), ENT_QUOTES) ?>, this.checked, this)">
                                <span class="bot-switch-track" aria-hidden="true"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php
            $rekapQuery = array_filter([
                'laporan_bulan' => $laporanBulan,
                'laporan_tim' => $laporanTim,
            ], static fn(string $value): bool => $value !== '');
            $rekapUrl = $basePath . 'rekap-laporan' . ($rekapQuery !== [] ? '?' . http_build_query($rekapQuery) : '');
            ?>
            <div class="recap-cta">
                <a href="<?= htmlspecialchars($rekapUrl, ENT_QUOTES) ?>" class="btn-action">Lihat Rekap</a>
            </div>
        </div>

        <div class="card card-paginated">
            <div class="card-toolbar">
                <h3 class="card-title">Daftar Laporan Masuk</h3>
                <div class="table-search-bar">
                    <input type="text" class="table-search-input" placeholder="🔍 Cari tim, tema, tanggal, atau ID laporan...">
                </div>
                <form class="laporan-filter" method="get" action="" id="laporan-filter-form">
                    <input type="hidden" name="tab" value="tab-laporan">
                    <div class="filter-field">
                        <select name="laporan_bulan" id="laporan-bulan" data-searchable aria-label="Filter Periode">
                            <option value="">Semua Periode</option>
                            <?php foreach (array_keys($opsiBulan) as $ym): ?>
                                <option value="<?= htmlspecialchars($ym) ?>" <?= $laporanBulan === $ym ? 'selected' : '' ?>><?= htmlspecialchars($formatBulan($ym)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <select name="laporan_tim" id="laporan-tim" data-searchable aria-label="Filter Tim">
                            <option value="">Semua Tim</option>
                            <?php foreach ($opsiTim as $key => $nama): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $laporanTim === $key ? 'selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <a class="btn-cancel" href="<?= htmlspecialchars($basePath) ?>?tab=tab-laporan">Reset</a>
                </form>
            </div>
            <div class="table-scroll">
                <table class="attendance-table">
                    <thead>
                        <tr><th>Tim</th><th>Tanggal</th><th>Tema</th><th>Kehadiran</th><th>Aksi</th></tr>
                    </thead>
                    <tbody class="paginated-tbody">
                        <?php if ($laporanRecap['rows'] === []): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                    Belum ada laporan masuk pada filter ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($laporanRecap['rows'] as $row): ?>
                                <?php
                                    $rowTanggal = (string)($row['tanggal'] !== '' ? $row['tanggal'] : substr((string)$row['created_at'], 0, 10));
                                    $rowTanggalText = $rowTanggal !== '' ? Watobot::formatTanggalIndonesia($rowTanggal) : '-';
                                ?>
                                <tr>
                                    <td><b><?= htmlspecialchars((string)$row['nama_tim']) ?></b><br><small><?= htmlspecialchars((string)$row['id']) ?></small></td>
                                    <td><?= htmlspecialchars($rowTanggalText) ?></td>
                                    <td><?= htmlspecialchars((string)($row['tema'] !== '' ? $row['tema'] : '-')) ?></td>
                                    <td>
                                        <span class="badge-status aktif">H <?= (int)$row['hadir'] ?></span>
                                        <span class="badge-status peringatan">I <?= (int)$row['izin'] ?></span>
                                        <span class="badge-status tidak_aktif">A <?= (int)$row['absen'] ?></span>
                                    </td>
                                    <td>
                                        <a class="row-action manage" href="<?= htmlspecialchars($basePath) ?>laporan?id=<?= urlencode((string)$row['id']) ?>">&#9881; Kelola</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>

    <!-- TAB 7: PENDAFTAR -->
    <div id="tab-pendaftar" class="tab-content <?= $activeTab === 'tab-pendaftar' ? 'active' : '' ?>">
        <div class="tab-split-desktop">
        <div class="card">
            <h3 class="card-title">Ringkasan Pendaftar (Waiting List)</h3>
            <div class="recap-grid" style="margin-bottom: 0;">
                <div class="stat-box">
                    <div class="stat-label">Total Pendaftar</div>
                    <?php if ($roleGenderLabel === null): ?>
                        <div class="stat-value"><?= (int)$regStats['total'] ?> <small>(<?= (int)$regStats['ikhwan'] ?> I / <?= (int)$regStats['akhwat'] ?> A)</small></div>
                    <?php else: ?>
                        <div class="stat-value"><?= (int)$regStats['total'] ?> <small>(<?= htmlspecialchars($roleGenderLabel) ?>)</small></div>
                    <?php endif; ?>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Menunggu</div>
                    <div class="stat-value" style="color: #fbbf24;"><?= (int)$regStats['Menunggu'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Cadangan</div>
                    <div class="stat-value" style="color: #7dd3fc;"><?= (int)$regStats['Cadangan'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Disetujui</div>
                    <div class="stat-value" style="color: #34d399;"><?= (int)$regStats['Disetujui'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Ditolak</div>
                    <div class="stat-value" style="color: #f87171;"><?= (int)$regStats['Ditolak'] ?></div>
                </div>
            </div>
        </div>

        <div class="card card-paginated">
            <div class="card-toolbar">
                <h3 class="card-title">Daftar Calon Mentee</h3>
                <div class="table-search-bar">
                    <input type="text" class="table-search-input" placeholder="🔍 Cari nama, NIM, kelas, WA, mentor, atau status...">
                </div>
                <div class="tab-actions">
                    <a href="<?= htmlspecialchars($appBase) ?>daftar" target="_blank" rel="noopener noreferrer" class="btn-action secondary">Buka Form &nearr;</a>
                    <button type="button" class="btn-action secondary" onclick="copyDaftarLink(<?= htmlspecialchars(json_encode($publicDaftarUrl), ENT_QUOTES) ?>, this)">Salin Link</button>
                </div>
            </div>
            <div class="table-scroll">
                <table class="attendance-table">
                    <thead>
                        <tr><th>Foto</th><th>Nama &amp; Gender</th><th>NIM &amp; Kelas</th><th>WhatsApp</th><th>Preferensi Mentor</th><th>Status</th><th>Aksi</th></tr>
                    </thead>
                    <tbody class="paginated-tbody">
                        <?php if ($registrants === []): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                    Belum ada pendaftar yang masuk. Bagikan link form pendaftaran publik kepada calon peserta.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registrants as $reg): ?>
                                <?php
                                    $regId = (string)($reg['id'] ?? '');
                                    $regStatus = (string)($reg['status_pendaftaran'] ?? 'Menunggu');
                                    $regBadgeClass = match ($regStatus) {
                                        'Disetujui' => 'aktif',
                                        'Ditolak' => 'tidak_aktif',
                                        default => 'peringatan',
                                    };
                                    $regKelas = Academic::formatClass($reg['kelas'] ?? '', $reg['nim'] ?? '');
                                    $regCreated = !empty($reg['created_at']) ? date('d M Y', strtotime((string)$reg['created_at'])) : '-';
                                    $prefMentor = trim((string)($reg['preferred_mentor_nama'] ?? ''));
                                ?>
                                <tr>
                                    <?= $renderAvatar('pendaftar', $regId, $reg['foto_profil'] ?? null, (string)($reg['nama_lengkap'] ?? '')) ?>
                                    <td>
                                        <b><?= htmlspecialchars((string)($reg['nama_lengkap'] ?? '-')) ?></b>
                                        <br><small><?= htmlspecialchars((string)($reg['gender'] ?? '-')) ?> &bull; <?= htmlspecialchars($regCreated) ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string)($reg['nim'] ?? '-')) ?>
                                        <?= $regKelas !== '' ? '<br><small>' . htmlspecialchars($regKelas) . ' (' . htmlspecialchars((string)($reg['jadwal_kuliah'] ?? '-')) . ')</small>' : '' ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($reg['nomor_whatsapp'] ?? '-')) ?></td>
                                    <td><?= $prefMentor !== '' ? htmlspecialchars($prefMentor) : '<span style="color:var(--text-muted);">Bebas / Divisi</span>' ?></td>
                                    <td><span class="badge-status <?= $regBadgeClass ?>"><?= htmlspecialchars($regStatus) ?></span></td>
                                    <td>
                                        <a class="row-action manage" href="<?= htmlspecialchars($basePath) ?>pendaftar?id=<?= urlencode($regId) ?>">&#9881; Kelola</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>

    <!-- TAB 4: EKSPOR EXCEL -->
    <div id="tab-export" class="tab-content <?= $activeTab === 'tab-export' ? 'active' : '' ?>">
        <div class="card">
            <h3 class="card-title">Ekspor Data Mentee ke Excel</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0 0 16px;">
                Unduh data mentee dalam format <b>.xlsx</b> siap cetak. Setiap tim memakai warna baris
                yang berbeda agar mudah dibaca.
            </p>
            <div class="export-actions">
                <?php if ($isSuperAdmin || $allowedExportScope === 'ikhwan'): ?>
                    <a href="<?= htmlspecialchars($basePath) ?>export?scope=ikhwan" class="btn-action secondary">Ekspor Ikhwan</a>
                <?php endif; ?>
                <?php if ($isSuperAdmin || $allowedExportScope === 'akhwat'): ?>
                    <a href="<?= htmlspecialchars($basePath) ?>export?scope=akhwat" class="btn-action secondary">Ekspor Akhwat</a>
                <?php endif; ?>
                <?php if ($isSuperAdmin): ?>
                    <a href="<?= htmlspecialchars($basePath) ?>export?scope=semua" class="btn-action secondary">Ekspor Gabungan</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: API BOT KEDALUWARSA / ANTREAN PENGIRIMAN -->
<div class="modal-overlay <?= $showBotModal ? 'active' : '' ?>" id="bot-modal" aria-hidden="<?= $showBotModal ? 'false' : 'true' ?>">
    <div class="modal bot-modal" role="dialog" aria-modal="true" aria-labelledby="bot-modal-title">
        <div class="modal-icon warning">&#9888;</div>
        <h3 class="modal-title" id="bot-modal-title">
            <?= !empty($botState['api_invalid']) ? 'API Bot Kedaluwarsa' : 'Antrean Pengiriman Bot' ?>
        </h3>
        <p class="modal-message">
            <?php if (!empty($botState['api_invalid'])): ?>
                API Watobot menolak pengiriman sehingga <b><?= (int)$botPendingCount ?> laporan</b>
                ditahan di antrean. Masukkan API key terbaru agar laporan dapat dikirim ulang.
                Proses submit tetap berjalan; tidak ada laporan yang hilang.
            <?php else: ?>
                Terdapat <b><?= (int)$botPendingCount ?> pesan</b> dalam antrean yang menunggu jadwal
                pengiriman ulang otomatis.
            <?php endif; ?>
        </p>

        <?php if (!empty($botState['last_error'])): ?>
            <p class="bot-hint" style="margin-bottom: 12px; color: #fca5a5;">
                Pesan terakhir dari API: <?= htmlspecialchars((string)$botState['last_error']) ?>
            </p>
        <?php endif; ?>

        <?php if ($botQueueItems !== []): ?>
            <ul class="bot-queue-list">
                <?php foreach (array_slice($botQueueItems, 0, 5) as $item): ?>
                    <li>
                        <b><?= htmlspecialchars((string)($item['meta']['nama_tim'] ?? $item['report_id'] ?? '-')) ?></b>
                        &bull; <?= htmlspecialchars((string)($item['report_id'] ?? '-')) ?>
                        &bull; percobaan ke-<?= (int)($item['attempts'] ?? 0) ?>
                        <?php if (!empty($item['last_error'])): ?>
                            <span class="queue-error"><?= htmlspecialchars((string)$item['last_error']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($botPendingCount > 5): ?>
                <p class="bot-hint" style="margin-top: -8px;">&hellip; dan <?= (int)($botPendingCount - 5) ?> pesan lainnya.</p>
            <?php endif; ?>
        <?php endif; ?>

        <div class="bot-form-message" id="bot-form-message"></div>

        <form id="bot-api-form" autocomplete="off">
            <?php if (count($allowedBotChannels) > 1): ?>
                <div class="form-group">
                    <label for="bot-channel">Target Bot Gender</label>
                    <select class="form-control" id="bot-channel" name="channel" onchange="onBotChannelChange()">
                        <?php foreach ($allowedBotChannels as $ch): ?>
                            <option value="<?= htmlspecialchars($ch) ?>" <?= $ch === $primaryBotChannel ? 'selected' : '' ?>>
                                Bot <?= htmlspecialchars($botChannels[$ch]['label']) ?> <?= $botChannels[$ch]['enabled'] ? '(Aktif)' : '(Dimatikan)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" id="bot-channel" name="channel" value="<?= htmlspecialchars($primaryBotChannel) ?>">
                <div class="form-group">
                    <label>Target Bot Gender</label>
                    <div class="bot-hint" style="margin-top: 0; color: #7dd3fc; font-weight: 700;">
                        Bot <?= htmlspecialchars($botChannels[$primaryBotChannel]['label']) ?>
                        (<?= $botChannels[$primaryBotChannel]['enabled'] ? 'Aktif' : 'Dimatikan' ?>)
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 5px;">
                    <label for="bot-group-id" style="margin-bottom: 0;">ID Grup WhatsApp</label>
                    <a href="<?= htmlspecialchars($basePath) ?>panduan-grup-wa" target="_blank" rel="noopener noreferrer" style="color: #38bdf8; font-size: 0.78rem; text-decoration: none;">Cara Ambil ID Grup &nearr;</a>
                </div>
                <input type="text" class="form-control" id="bot-group-id" name="group_id"
                       value="<?= htmlspecialchars((string)$botChannels[$primaryBotChannel]['group_id']) ?>"
                       placeholder="Contoh: 120363xxxxxxxxxxxx@g.us">
                <div class="bot-hint">Diperoleh dari daftar grup Watobot untuk nomor pengirim kategori ini.</div>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 5px;">
                    <label for="bot-api-key" style="margin-bottom: 0;">API Key Watobot Baru</label>
                    <a href="https://watobot.xyz/dashboard/" target="_blank" rel="noopener noreferrer" style="color: #38bdf8; font-size: 0.78rem; text-decoration: none;">Buka Dasbor Watobot &nearr;</a>
                </div>
                <input type="password" class="form-control" id="bot-api-key" name="api_key"
                       placeholder="Tempel API key terbaru di sini (kosongkan jika hanya ubah ID grup)">
                <div class="bot-hint">API Watobot dapat kedaluwarsa setiap jam. Ambil kunci baru di dasbor Watobot, lalu tempel di sini. Kunci diuji ke antrean terlebih dahulu; bila ditolak, kunci lama tetap dipakai dan pesan tidak hilang.</div>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn secondary" onclick="closeBotModal()">Tutup</button>
                <?php if ($botPendingCount > 0): ?>
                    <button type="button" class="modal-btn secondary" id="bot-retry-btn" onclick="processBotQueue(this)">Kirim Sekarang</button>
                <?php endif; ?>
                <button type="submit" class="modal-btn primary" id="bot-save-btn">Simpan Pengaturan</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/searchable-select.js"></script>
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/modal.js"></script>
<script>
const BOT_API_SAVE_URL = <?= json_encode($appBase . 'api?action=bot_save_api') ?>;
const BOT_API_TOGGLE_URL = <?= json_encode($appBase . 'api?action=bot_toggle') ?>;
const BOT_API_PROCESS_URL = <?= json_encode($appBase . 'api?action=bot_process_queue') ?>;
const BOT_CSRF_TOKEN = <?= json_encode(AdminAuth::csrfToken()) ?>;
const BOT_CHANNELS_DATA = <?= json_encode($botChannels) ?>;

function onBotChannelChange() {
    const channelEl = document.getElementById('bot-channel');
    const groupEl = document.getElementById('bot-group-id');
    if (!channelEl || !groupEl) return;
    const ch = channelEl.value;
    const info = BOT_CHANNELS_DATA[ch] || {};
    groupEl.value = info.group_id || '';
}

function openBotModal(channel) {
    const modal = document.getElementById('bot-modal');
    if (!modal) return;
    const channelEl = document.getElementById('bot-channel');
    if (channel && channelEl && BOT_CHANNELS_DATA[channel]) {
        channelEl.value = channel;
    }
    onBotChannelChange();
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function closeBotModal() {
    const modal = document.getElementById('bot-modal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

function showBotFormMessage(text, kind) {
    const el = document.getElementById('bot-form-message');
    if (!el) return;
    el.textContent = text || '';
    el.className = 'bot-form-message' + (kind ? ' ' + kind : '');
}

function reloadMaster() {
    // Notifikasi sukses sudah ditampilkan lewat Modal; jangan bawa param
    // `msg` ke URL agar banner tidak muncul/menempel setelah reload.
    const url = new URL(window.location.href);
    url.searchParams.delete('msg');
    url.searchParams.delete('error');
    url.hash = '';
    window.location.replace(url.toString());
}

async function saveBotApi(event) {
    event.preventDefault();
    const channelEl = document.getElementById('bot-channel');
    const groupEl = document.getElementById('bot-group-id');
    const keyInput = document.getElementById('bot-api-key');
    const saveBtn = document.getElementById('bot-save-btn');
    const channel = channelEl ? (channelEl.value || 'ikhwan') : 'ikhwan';
    const groupId = groupEl ? (groupEl.value || '').trim() : '';
    const apiKey = (keyInput.value || '').trim();

    if (!apiKey && !groupId) {
        showBotFormMessage('Isi API key baru atau ID grup WhatsApp terlebih dahulu.', 'error');
        keyInput.focus();
        return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = 'Menyimpan...';
    showBotFormMessage('Menyimpan pengaturan bot...', 'info');
    if (typeof window.ukmiShowLoadingOverlay === 'function') {
        window.ukmiShowLoadingOverlay('Menyimpan pengaturan bot...');
    }

    try {
        const response = await fetch(BOT_API_SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BOT_CSRF_TOKEN },
            body: JSON.stringify({ channel: channel, api_key: apiKey, group_id: groupId })
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            showBotFormMessage(data.message || 'Gagal menyimpan pengaturan bot.', 'error');
            if (data.data && data.data.key_rejected) {
                keyInput.select();
            }
            return;
        }

        const state = (data.data && data.data.state) || {};
        const pending = (data.data && data.data.pending_count) || 0;
        if (state.api_invalid) {
            showBotFormMessage('Pengaturan tersimpan, tetapi API masih menolak. ' + pending + ' pesan tetap dalam antrean.', 'error');
            return;
        }

        if (typeof window.Modal !== 'undefined') {
            await window.Modal.success(
                data.message || ('Pengaturan bot disimpan' + (pending > 0 ? ', ' + pending + ' pesan masih dijadwalkan ulang.' : '.')),
                'Pengaturan Bot Diperbarui'
            );
        }
        reloadMaster();
    } catch (error) {
        showBotFormMessage('Tidak dapat menghubungi server. Periksa koneksi lalu coba lagi.', 'error');
    } finally {
        if (typeof window.ukmiHideLoadingOverlay === 'function') {
            window.ukmiHideLoadingOverlay();
        }
        saveBtn.disabled = false;
        saveBtn.textContent = 'Simpan Pengaturan';
    }
}

async function toggleBotFeature(channel, enabled, btn) {
    if (btn) btn.disabled = true;
    if (typeof window.ukmiShowLoadingOverlay === 'function') {
        window.ukmiShowLoadingOverlay(enabled ? 'Mengaktifkan fitur bot...' : 'Mematikan fitur bot...');
    }
    try {
        const response = await fetch(BOT_API_TOGGLE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BOT_CSRF_TOKEN },
            body: JSON.stringify({ channel: channel, enabled: !!enabled })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            if (btn && typeof btn.checked === 'boolean') btn.checked = !enabled;
            if (typeof window.Modal !== 'undefined') {
                await window.Modal.error(data.message || 'Gagal mengubah status bot.', 'Gagal');
            }
            return;
        }
        if (typeof window.Modal !== 'undefined') {
            await window.Modal.success(data.message || 'Status bot diperbarui.', 'Berhasil');
        }
        reloadMaster();
    } catch (error) {
        if (btn && typeof btn.checked === 'boolean') btn.checked = !enabled;
        if (typeof window.Modal !== 'undefined') {
            await window.Modal.error('Tidak dapat menghubungi server.', 'Gagal');
        }
    } finally {
        if (typeof window.ukmiHideLoadingOverlay === 'function') {
            window.ukmiHideLoadingOverlay();
        }
        if (btn) btn.disabled = false;
    }
}

async function processBotQueue(btn) {
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Mengirim...';
    }
    showBotFormMessage('Mencoba mengirim seluruh antrean...', 'info');
    if (typeof window.ukmiShowLoadingOverlay === 'function') {
        window.ukmiShowLoadingOverlay('Mengirim antrean pesan WhatsApp...');
    }

    try {
        const response = await fetch(BOT_API_PROCESS_URL, {
            method: 'POST',
            headers: { 'X-CSRF-Token': BOT_CSRF_TOKEN }
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            showBotFormMessage(data.message || 'Gagal memproses antrean.', 'error');
            return;
        }

        const pending = (data.data && data.data.pending_count) || 0;
        const sent = (data.data && data.data.sent) || 0;
        const state = (data.data && data.data.state) || {};

        if (pending === 0) {
            if (typeof window.Modal !== 'undefined') {
                await window.Modal.success(sent + ' pesan berhasil dikirim.', 'Antrean Selesai');
            }
            reloadMaster();
            return;
        }

        if (state.api_invalid) {
            showBotFormMessage('API masih kedaluwarsa. Masukkan API key baru di atas. ' + pending + ' pesan tetap menunggu.', 'error');
        } else {
            showBotFormMessage(sent + ' terkirim, ' + pending + ' masih menunggu jadwal berikutnya.', 'error');
        }
    } catch (error) {
        showBotFormMessage('Tidak dapat menghubungi server. Periksa koneksi lalu coba lagi.', 'error');
    } finally {
        if (typeof window.ukmiHideLoadingOverlay === 'function') {
            window.ukmiHideLoadingOverlay();
        }
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Kirim Sekarang';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const botForm = document.getElementById('bot-api-form');
    if (botForm) botForm.addEventListener('submit', saveBotApi);

    const botModal = document.getElementById('bot-modal');
    if (botModal) {
        botModal.addEventListener('click', (event) => {
            if (event.target === botModal) closeBotModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && botModal.classList.contains('active')) closeBotModal();
        });
    }

    // Hapus param msg/error dari URL agar banner tidak muncul lagi saat refresh,
    // lalu sembunyikan banner otomatis setelah beberapa detik.
    const cleanUrl = new URL(window.location.href);
    let urlChanged = false;
    ['msg', 'error'].forEach((key) => {
        if (cleanUrl.searchParams.has(key)) {
            cleanUrl.searchParams.delete(key);
            urlChanged = true;
        }
    });
    if (urlChanged && history.replaceState) {
        history.replaceState(null, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
    }

    document.querySelectorAll('[data-flash]').forEach((el) => {
        setTimeout(() => {
            el.classList.add('hide');
            setTimeout(() => el.remove(), 400);
        }, 5000);
    });
});

function copyDaftarLink(url, btn) {
    const done = () => {
        const old = btn.textContent;
        btn.textContent = 'Link Disalin ✓';
        setTimeout(() => { btn.textContent = old; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(done).catch(() => {});
    } else {
        const tmp = document.createElement('input');
        tmp.value = url;
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(tmp);
        done();
    }
}

function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
    if (history.replaceState) history.replaceState(null, '', '#' + tabId);
    if (typeof window.recalcAllPaginatedCards === 'function') {
        window.recalcAllPaginatedCards();
    }
}

// Inisialisasi Pencarian & Pagination untuk semua card yang memiliki judul "Daftar"
document.addEventListener('DOMContentLoaded', () => {
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenuDrawer = document.getElementById('mobile-menu-drawer');
    const mobileDrawerClose = document.getElementById('mobile-drawer-close');
    const setMobileDrawerOpen = (open) => {
        if (!mobileMenuDrawer || !mobileMenuBtn) return;
        mobileMenuDrawer.classList.toggle('active', open);
        mobileMenuDrawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        mobileMenuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    if (mobileMenuBtn && mobileMenuDrawer) {
        mobileMenuBtn.addEventListener('click', () => setMobileDrawerOpen(true));
        if (mobileDrawerClose) {
            mobileDrawerClose.addEventListener('click', () => setMobileDrawerOpen(false));
        }
        mobileMenuDrawer.addEventListener('click', (e) => {
            if (e.target === mobileMenuDrawer) setMobileDrawerOpen(false);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && mobileMenuDrawer.classList.contains('active')) {
                setMobileDrawerOpen(false);
            }
        });
    }

    const mobilePendaftarIndicator = document.getElementById('mobile-pendaftar-indicator');
    const tabsContainer = document.querySelector('.master-nav-row .tabs');
    const pendaftarTabBtn = document.querySelector('.tab-btn[onclick*="tab-pendaftar"]');
    const updatePendaftarIndicatorVisibility = () => {
        if (!mobilePendaftarIndicator || !tabsContainer || !pendaftarTabBtn) return;
        const tabsRect = tabsContainer.getBoundingClientRect();
        const btnRect = pendaftarTabBtn.getBoundingClientRect();
        const isVisible = btnRect.left < tabsRect.right - 24 && btnRect.right > tabsRect.left;
        mobilePendaftarIndicator.classList.toggle('is-hidden', isVisible);
    };
    if (mobilePendaftarIndicator) {
        mobilePendaftarIndicator.addEventListener('click', () => {
            if (pendaftarTabBtn) {
                switchTab('tab-pendaftar', pendaftarTabBtn);
                pendaftarTabBtn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            }
        });
        if (tabsContainer) {
            tabsContainer.addEventListener('scroll', updatePendaftarIndicatorVisibility, { passive: true });
        }
        window.addEventListener('resize', updatePendaftarIndicatorVisibility);
        updatePendaftarIndicatorVisibility();
    }

    // Searchable select untuk filter rekap laporan
    document.querySelectorAll('select[data-searchable]').forEach(select => initSearchableSelect(select));

    // Filter Kelola Laporan langsung diterapkan saat pilihan berubah (tanpa tombol Terapkan).
    const laporanFilterForm = document.getElementById('laporan-filter-form');
    if (laporanFilterForm) {
        laporanFilterForm.addEventListener('change', () => laporanFilterForm.submit());
    }

    // Buka tab sesuai hash (mis. kembali dari subhalaman pengaturan)
    const hashTab = location.hash.replace('#', '');
    if (hashTab) {
        const target = document.getElementById(hashTab);
        if (target && target.classList.contains('tab-content')) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            target.classList.add('active');
            const btn = document.querySelector('.tab-btn[onclick*="' + hashTab + '"]');
            if (btn) btn.classList.add('active');
        }
    }

    const paginatedRecalcFns = [];
    window.recalcAllPaginatedCards = () => {
        paginatedRecalcFns.forEach(fn => fn());
    };

    function computeAutoPageSize(card, tbody, allRows) {
        const isDesktop = window.innerWidth >= 1024;
        const firstVisibleRow = allRows.find(r => r.offsetHeight > 0);
        const rowHeight = firstVisibleRow ? Math.max(48, firstVisibleRow.offsetHeight) : 64;
        let tbodyTop = tbody.getBoundingClientRect().top;
        if (tbodyTop <= 0) {
            const tabsEl = document.querySelector('.tabs');
            tbodyTop = tabsEl ? (tabsEl.getBoundingClientRect().bottom + 130) : 280;
        }
        const availableHeight = window.innerHeight - tbodyTop - 78 - 24;
        const autoCount = Math.floor(availableHeight / rowHeight);
        return isDesktop ? Math.max(2, Math.min(15, autoCount)) : Math.max(3, Math.min(8, autoCount));
    }

    document.querySelectorAll('.card-paginated').forEach(card => {
        const tbody = card.querySelector('.paginated-tbody');
        const searchInput = card.querySelector('.table-search-input');
        if (!tbody) return;

        const allRows = Array.from(tbody.querySelectorAll('tr'));
        let filteredRows = [...allRows];
        let pageSize = computeAutoPageSize(card, tbody, allRows);
        let totalPages = Math.ceil(filteredRows.length / pageSize) || 1;
        let currentPage = 1;

        // Container pagination
        const paginationContainer = document.createElement('div');
        paginationContainer.className = 'pagination-container';

        const infoSpan = document.createElement('div');
        infoSpan.className = 'pagination-info';

        const controlsDiv = document.createElement('div');
        controlsDiv.className = 'pagination-controls';

        paginationContainer.appendChild(infoSpan);
        paginationContainer.appendChild(controlsDiv);
        card.appendChild(paginationContainer);

        function updatePagination() {
            pageSize = computeAutoPageSize(card, tbody, allRows);
            totalPages = Math.ceil(filteredRows.length / pageSize) || 1;
            if (currentPage > totalPages) currentPage = 1;
            renderPage(currentPage);
        }

        paginatedRecalcFns.push(updatePagination);

        function renderPage(page) {
            currentPage = page;
            const start = (page - 1) * pageSize;
            const end = start + pageSize;

            allRows.forEach(row => row.style.display = 'none');
            filteredRows.forEach((row, idx) => {
                row.style.display = (idx >= start && idx < end) ? '' : 'none';
            });

            const total = filteredRows.length;
            const showingStart = total === 0 ? 0 : start + 1;
            const showingEnd = Math.min(end, total);
            infoSpan.textContent = `Menampilkan ${showingStart}-${showingEnd} dari ${total} data (Halaman ${currentPage}/${totalPages})`;

            renderControls();
        }

        function buildPaginationItems(current, total, maxSlots) {
            if (total <= maxSlots) {
                return Array.from({ length: total }, (_, idx) => idx + 1);
            }
            if (maxSlots <= 1) {
                return [current];
            }
            if (maxSlots === 2) {
                return current > 1 ? ['...', current] : [current, '...'];
            }
            if (maxSlots === 3) {
                if (current === 1 || current === total) {
                    return [1, '...', total];
                }
                return ['...', current, '...'];
            }
            if (maxSlots === 4) {
                if (current <= 2) return [1, 2, '...', total];
                if (current >= total - 1) return [1, '...', total - 1, total];
                return [1, '...', current, '...'];
            }

            if (current <= maxSlots - 2) {
                const items = [];
                for (let i = 1; i <= maxSlots - 2; i++) items.push(i);
                items.push('...', total);
                return items;
            }
            if (current >= total - (maxSlots - 3)) {
                const items = [1, '...'];
                for (let i = total - (maxSlots - 3); i <= total; i++) items.push(i);
                return items;
            }

            const innerCount = maxSlots - 4;
            const half = Math.floor(innerCount / 2);
            let start = current - half;
            let end = start + innerCount - 1;
            if (start < 2) {
                start = 2;
                end = start + innerCount - 1;
            }
            if (end > total - 1) {
                end = total - 1;
                start = end - innerCount + 1;
            }
            const items = [1, '...'];
            for (let i = start; i <= end; i++) items.push(i);
            items.push('...', total);
            return items;
        }

        function getAvailableControlsWidth() {
            const containerWidth = paginationContainer.clientWidth;
            if (containerWidth <= 0) {
                return Math.max(200, (card.clientWidth || window.innerWidth) - 48);
            }
            const infoWidth = infoSpan.offsetWidth || 0;
            if (containerWidth - infoWidth - 16 >= 320) {
                return containerWidth - infoWidth - 16;
            }
            return containerWidth;
        }

        function renderControlsWithSlots(maxSlots) {
            controlsDiv.innerHTML = '';

            // Tombol Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'page-nav-btn';
            prevBtn.textContent = '« Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) renderPage(currentPage - 1);
            });
            controlsDiv.appendChild(prevBtn);

            // Tombol nomor halaman & ellipsis
            const items = buildPaginationItems(currentPage, totalPages, maxSlots);
            items.forEach(item => {
                if (item === '...') {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'page-nav-ellipsis';
                    ellipsis.textContent = '...';
                    ellipsis.setAttribute('aria-hidden', 'true');
                    controlsDiv.appendChild(ellipsis);
                } else {
                    const numBtn = document.createElement('button');
                    numBtn.className = 'page-nav-btn' + (item === currentPage ? ' active' : '');
                    numBtn.textContent = item;
                    numBtn.addEventListener('click', () => renderPage(item));
                    controlsDiv.appendChild(numBtn);
                }
            });

            // Tombol Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'page-nav-btn';
            nextBtn.textContent = 'Next »';
            nextBtn.disabled = (currentPage === totalPages || totalPages === 0);
            nextBtn.addEventListener('click', () => {
                if (currentPage < totalPages) renderPage(currentPage + 1);
            });
            controlsDiv.appendChild(nextBtn);
        }

        function renderControls() {
            const availableWidth = getAvailableControlsWidth();
            let maxSlots = Math.max(1, Math.min(totalPages, Math.floor((availableWidth - 148) / 42)));
            renderControlsWithSlots(maxSlots);

            while (availableWidth > 0 && controlsDiv.scrollWidth > availableWidth && maxSlots > 1) {
                maxSlots -= 1;
                renderControlsWithSlots(maxSlots);
            }
        }

        // Event listener kolom pencarian
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const keyword = e.target.value.toLowerCase().trim();
                filteredRows = allRows.filter(row => {
                    const text = row.innerText.toLowerCase();
                    return text.includes(keyword);
                });
                updatePagination();
            });
        }

        updatePagination();
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (typeof window.recalcAllPaginatedCards === 'function') {
                window.recalcAllPaginatedCards();
            }
        }, 120);
    });

    // Pencarian sederhana pada Arsip Purna
    const archiveInput = document.getElementById('archive-search');
    const archiveBody = document.getElementById('archive-tbody');
    if (archiveInput && archiveBody) {
        const archiveRows = Array.from(archiveBody.querySelectorAll('tr'));
        archiveInput.addEventListener('input', () => {
            const keyword = archiveInput.value.toLowerCase().trim();
            archiveRows.forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(keyword) ? '' : 'none';
            });
        });
    }
});
</script>

</body>
</html>
