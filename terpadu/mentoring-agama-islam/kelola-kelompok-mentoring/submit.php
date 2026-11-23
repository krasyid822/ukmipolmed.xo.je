<?php
/**
 * submit.php — Endpoint Pemrosesan Formulir Laporan Mentoring
 * Mengimplementasikan alur end-to-end sesuai AGENTS.md Bagian 6
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
session_start();

use Mentoring\Config;
use Mentoring\Crypto;
use Mentoring\S3Client;
use Mentoring\Storage;
use Mentoring\MasterData;
use Mentoring\Watobot;
use Mentoring\BotQueue;
use Mentoring\ImageProcessor;
use Mentoring\Biometric;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./');
    exit;
}

$isAjax = (($_POST['_ajax'] ?? '') === '1')
    || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

$jsonFail = static function (int $status, string $errorCode, string $message) use ($isAjax): void {
    if ($isAjax) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error_code' => $errorCode,
            'message' => $message,
        ]);
        exit;
    }
};

// 1. Ekstraksi dan Validasi Input Form
$timId = trim($_POST['tim_id'] ?? '');

// Enforcing verifikasi biometrik sebelum formulir dapat disubmit (sesi atau offline token).
$verified = Biometric::checkVerified($timId);
if (!$verified) {
    $offlineToken = trim((string)($_POST['biometric_offline_token'] ?? $_SERVER['HTTP_X_BIOMETRIC_OFFLINE_TOKEN'] ?? ''));
    if ($offlineToken !== '') {
        $verified = Biometric::verifyOfflineToken($offlineToken, $timId);
    }
}
if (!$verified) {
    $jsonFail(403, 'biometric_required', 'Akses ditolak: verifikasi biometrik belum lulus. Silakan buka formulir dan verifikasi terlebih dahulu.');
    http_response_code(403);
    die("Akses ditolak: verifikasi biometrik belum lulus. Silakan buka formulir dan verifikasi terlebih dahulu.");
}

if (!Biometric::isTeamAllowedForEntity((string)($verified['entity_type'] ?? ''), (string)($verified['entity_id'] ?? ''), $timId)) {
    $jsonFail(403, 'team_not_allowed', 'Akses ditolak: Anda tidak memiliki relasi langsung dengan kelompok mentoring yang dipilih.');
    http_response_code(403);
    die('Akses ditolak: Anda tidak memiliki relasi langsung dengan kelompok mentoring yang dipilih.');
}

$tanggal = trim($_POST['tanggal'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$jamMulai = mb_substr(trim($_POST['jam_mulai'] ?? '16:00'), 0, 10);
$jamSelesai = mb_substr(trim($_POST['jam_selesai'] ?? '17:30'), 0, 10);
$tempat = mb_substr(trim($_POST['tempat'] ?? ''), 0, 250);
$tema = mb_substr(trim($_POST['tema'] ?? ''), 0, 500);

if ($tempat === '' || $tema === '') {
    $jsonFail(422, 'wajib', 'Data pertemuan belum lengkap. Isi tempat dan tema terlebih dahulu.');
    header('Location: ./?error=wajib');
    exit;
}

$mentorNama = trim($_POST['mentor_nama'] ?? '');
$moderator = trim($_POST['moderator'] ?? '');
$tilawah = trim($_POST['tilawah'] ?? '');
$tadabburPetugas = trim($_POST['tadabbur_petugas'] ?? '');
$tadabburSurahAyat = trim($_POST['tadabbur_surah_ayat'] ?? '');
$tadabburInti = trim($_POST['tadabbur_inti'] ?? '');
$kultumPetugas = trim($_POST['kultum_petugas'] ?? '');
$kultumJudulInti = trim($_POST['kultum_judul_inti'] ?? '');
$doaPetugas = trim($_POST['doa_petugas'] ?? '');

// Dapatkan nama tim
$teams = MasterData::getTeamsForForm();
$selectedTeam = null;
foreach ($teams as $t) {
    if ($t['id'] === $timId) {
        $selectedTeam = $t;
        break;
    }
}

if (!$selectedTeam) {
    $jsonFail(422, 'invalid_team', 'Tim mentoring tidak valid.');
    die("Tim mentoring tidak valid.");
}

$namaTim = $selectedTeam['nama_tim'];
if (empty($mentorNama)) {
    $mentorNama = $selectedTeam['mentor_nama'];
}

// 2. Pemrosesan Data Kehadiran Mentee
$kehadiranRaw = $_POST['kehadiran'] ?? []; // [mte_id => 'Hadir' | 'Izin' | 'Absen']
$tidakJelasRaw = $_POST['tidak_jelas'] ?? []; // [mte_id => '1']
$keteranganIzinRaw = $_POST['keterangan_izin'] ?? []; // [mte_id => [keterangan]]

$hadirList = [];
$izinList = [];
$absenList = [];
$izinKeterangan = [];
$attendanceForMaster = [];

foreach ($selectedTeam['mentees'] as $m) {
    $mId = $m['id'];
    $mNama = $m['nama_lengkap'];
    $statusK = $kehadiranRaw[$mId] ?? 'Hadir';
    // "Tidak jelas kabarnya" hanya valid bila mentee berstatus Absen.
    $isTidakJelas = ($statusK === 'Absen') && !empty($tidakJelasRaw[$mId]);

    if ($statusK === 'Hadir') {
        $hadirList[] = $mNama;
    } elseif ($statusK === 'Izin') {
        $izinList[] = $mNama;

        $notes = $keteranganIzinRaw[$mId] ?? [];
        if (!is_array($notes)) {
            $notes = [$notes];
        }
        foreach ($notes as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $izinKeterangan[$mNama] = $candidate;
                break;
            }
        }
    } else {
        $absenList[] = $mNama;
    }

    $attendanceForMaster[$mId] = [
        'status' => $statusK,
        'tidak_jelas_kabarnya' => $isTidakJelas,
    ];
}

// 3. Generate ID Laporan Unik (contoh: rep-20261120-xxxx)
$reportId = 'rep-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

// 4. Validasi & Optimasi Foto Dokumentasi (wajib; tidak ada placeholder)
$compressedImages = null;
if (isset($_FILES['dokumentasi']) && $_FILES['dokumentasi']['error'] === UPLOAD_ERR_OK) {
    try {
        $compressedImages = ImageProcessor::processAndCompressDual($_FILES['dokumentasi']['tmp_name'], 1280, 78);
    } catch (\Throwable $e) {
        error_log("Gagal kompresi foto dokumentasi: " . $e->getMessage());
    }
}

if (!$compressedImages || ($compressedImages['webp'] === null && $compressedImages['jpg'] === '')) {
    $jsonFail(422, 'foto', 'Foto dokumentasi wajib diunggah dalam format JPG/PNG/WEBP.');
    header('Location: ./?error=foto');
    exit;
}

// 5. Susun Payload Laporan Lengkap
$reportPayload = [
    'id' => $reportId,
    'tim_id' => $timId,
    'nama_tim' => $namaTim,
    'tanggal' => $tanggal,
    'jam_mulai' => $jamMulai,
    'jam_selesai' => $jamSelesai,
    'tempat' => $tempat,
    'tema' => $tema,
    'mentor' => $mentorNama,
    'moderator' => $moderator,
    'tilawah' => $tilawah,
    'tadabbur_petugas' => $tadabburPetugas,
    'tadabbur_surah_ayat' => $tadabburSurahAyat,
    'tadabbur_inti' => $tadabburInti,
    'kultum_petugas' => $kultumPetugas,
    'kultum_judul_inti' => $kultumJudulInti,
    'doa_petugas' => $doaPetugas,
    'hadir' => $hadirList,
    'izin' => $izinList,
    'izin_keterangan' => $izinKeterangan,
    'absen' => $absenList,
    'created_at' => date('c'),
];

// 6. Upload Foto Dokumentasi & OG Meta ke S3
$s3 = new S3Client();
$dokFolder = Config::get('storage.folders.dokumentasi', 'dokumentasi/');
$primaryExt = $compressedImages['webp'] !== null ? 'webp' : 'jpg';
$primaryMime = $primaryExt === 'webp' ? 'image/webp' : 'image/jpeg';
$primaryBody = $compressedImages['webp'] ?? $compressedImages['jpg'];
$dokS3Path = $dokFolder . $reportId . '.' . $primaryExt;
$dokUploaded = $s3->putObject($dokS3Path, $primaryBody, $primaryMime, false);

// Unggah juga salinan .jpg untuk kompatibilitas Cloudflare Worker & crawler lama
if ($dokUploaded && $primaryExt === 'webp' && $compressedImages['jpg'] !== '') {
    $s3->putObject($dokFolder . $reportId . '.jpg', $compressedImages['jpg'], 'image/jpeg', false);
}

// OG Meta (Public Read JSON ringan untuk WhatsApp Crawler via Worker)
$ogMetaFolder = Config::get('storage.folders.og_meta', 'og_meta/');
$ogMetaS3Path = $ogMetaFolder . $reportId . '.json';
$ogMetaPayload = [
    'title' => "Laporan Mentoring {$namaTim}",
    'description' => "Tema: {$tema} | Waktu: " . Watobot::formatTanggalIndonesia($tanggal) . " | Tempat: {$tempat}",
    'image' => $dokS3Path,
    'tim' => $namaTim,
];
if ($dokUploaded) {
    $s3->putObject($ogMetaS3Path, json_encode($ogMetaPayload, JSON_UNESCAPED_SLASHES), 'application/json', false);
}

// 7. Enkripsi dan Kompresi Data Laporan Privat
$cryptoResult = Crypto::encryptData($reportPayload);
$laporanFolder = Config::get('storage.folders.laporan_json', 'laporan_json/');
$laporanS3Path = $laporanFolder . $reportId . '.enc';
$laporanUploaded = $dokUploaded && $s3->putObject($laporanS3Path, $cryptoResult['ciphertext_raw'], 'application/octet-stream', false);

if (!$dokUploaded || !$laporanUploaded) {
    $jsonFail(502, 'storage', 'Gagal mengunggah laporan ke penyimpanan S3. Silakan coba lagi.');
    header('Location: ./?error=storage');
    exit;
}

// 8. Update Local Pointer Registry (pointer_laporan.json)
Storage::savePointer('laporan', [
    'id' => $reportId,
    'tim_id' => $timId,
    'nama_tim' => $namaTim,
    'tanggal' => $tanggal,
    'tema' => $tema,
    's3_path' => $laporanS3Path,
    'dokumentasi_path' => $dokS3Path,
    'created_at' => date('c'),
    'crypto' => [
        'key' => $cryptoResult['key_b64'],
        'iv' => $cryptoResult['iv_b64'],
        'tag' => $cryptoResult['tag_b64'],
    ],
]);

// 9. Update Status Keaktifan Mentee pada Master Data
MasterData::updateMenteeAttendance($timId, $attendanceForMaster);

// 10. Kirim Laporan ke WhatsApp Group via Watobot API (sesuai gender tim).
//     Kegagalan TIDAK menghentikan proses: pesan masuk antrean dan akan
//     dicoba ulang otomatis saat API bot kembali tersedia.
$botChannel = Watobot::resolveChannelForTeam($timId, $namaTim);
$botMessage = Watobot::buildMessageText($reportPayload);
$botQueued = false;
if (!Watobot::isEnabled($botChannel)) {
    $botResult = ['success' => false, 'disabled' => true];
} else {
    $botResult = Watobot::sendReport($botMessage, [], $botChannel);
    if (empty($botResult['success'])) {
        BotQueue::enqueue($reportId, $botMessage, [
            'nama_tim' => $namaTim,
            'tanggal' => $tanggal,
        ], $botChannel);
        if (!empty($botResult['api_invalid'])) {
            BotQueue::markApiInvalid(
                (string)($botResult['failure_reason'] ?? 'API bot kedaluwarsa'),
                (int)($botResult['http_code'] ?? 0),
                $botChannel
            );
        } else {
            BotQueue::markAttemptFailed(
                (string)($botResult['failure_reason'] ?? 'Gagal mengirim ke API bot'),
                (int)($botResult['http_code'] ?? 0),
                $botChannel
            );
        }
        $botQueued = true;
    }
}

// 11. Redirect ke Halaman Laporan Lengkap (view?id=<id_laporan>) atau kembalikan JSON jika AJAX/PWA
$query = 'view?id=' . urlencode($reportId) . '&sent=' . ($botResult['success'] ? '1' : '0');
if ($botQueued) {
    $query .= '&queued=1';
}
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'report_id' => $reportId,
        'view_url' => $query,
        'bot_sent' => !empty($botResult['success']),
        'bot_queued' => $botQueued,
        'message' => 'Laporan berhasil disimpan dan dipublikasikan.',
    ]);
    exit;
}
header('Location: ' . $query);
exit;
