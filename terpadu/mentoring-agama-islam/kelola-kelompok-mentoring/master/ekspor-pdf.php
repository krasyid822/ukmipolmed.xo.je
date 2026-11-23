<?php
/**
 * master/ekspor-pdf.php — Ekspor Rekap Laporan ke PDF
 * Memuat seluruh laporan individual sesuai filter periode/tim, lalu menyusun
 * PDF A4 dengan foto dokumentasi yang sudah dikompres ulang (JPEG kecil).
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\ReportPdf;
use Mentoring\ReportRepository;
use Mentoring\S3Client;

AdminAuth::guard();
@set_time_limit(180);

$toArray = static function ($value): array {
    if (!is_array($value)) {
        $value = $value === null ? [] : [$value];
    }
    $result = [];
    foreach ($value as $item) {
        $item = trim((string)$item);
        if ($item !== '') {
            $result[] = $item;
        }
    }
    return array_values(array_unique($result));
};

$laporanBulan = array_values(array_filter(
    $toArray($_GET['laporan_bulan'] ?? []),
    static fn(string $ym): bool => (bool)preg_match('/^\d{4}-\d{2}$/', $ym)
));
$laporanTim = $toArray($_GET['laporan_tim'] ?? []);
$laporanGender = (string)($_GET['laporan_gender'] ?? '');
if (!in_array($laporanGender, ['Ikhwan', 'Akhwat'], true)) {
    $laporanGender = '';
}
$roleGenderLabel = AdminAuth::allowedGenderLabel();
if ($roleGenderLabel !== null) {
    $laporanGender = $roleGenderLabel;
}
$teamGenders = ReportRepository::teamGenderMap();

$summaries = ReportRepository::summaries();
$filtered = array_values(array_filter($summaries, static function (array $s) use ($laporanBulan, $laporanTim, $laporanGender, $teamGenders): bool {
    $tanggal = $s['tanggal'] !== '' ? $s['tanggal'] : substr($s['created_at'], 0, 10);
    if ($laporanBulan !== [] && !in_array(substr($tanggal, 0, 7), $laporanBulan, true)) {
        return false;
    }
    if ($laporanTim !== [] && !in_array('nama:' . $s['nama_tim'], $laporanTim, true)) {
        return false;
    }
    if ($laporanGender !== '') {
        $gender = $teamGenders['tim:' . $s['tim_id']] ?? $teamGenders['nama:' . $s['nama_tim']] ?? '';
        if ($gender !== $laporanGender) {
            return false;
        }
    }
    return true;
}));

$bulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$periodeLabel = 'Semua Periode';
if (count($laporanBulan) === 1) {
    [$tahun, $bulan] = array_pad(explode('-', $laporanBulan[0], 2), 2, '');
    $periodeLabel = ($bulanIndo[(int)$bulan] ?? $bulan) . ' ' . $tahun;
} elseif (count($laporanBulan) > 1) {
    $periodeLabel = count($laporanBulan) . ' periode';
}

$timNames = [];
foreach ($summaries as $s) {
    $timNames['nama:' . $s['nama_tim']] = $s['nama_tim'];
}
$timLabel = 'Semua Tim';
if (count($laporanTim) === 1) {
    $timLabel = (string)($timNames[$laporanTim[0]] ?? (str_starts_with($laporanTim[0], 'nama:') ? substr($laporanTim[0], 5) : $laporanTim[0]));
} elseif (count($laporanTim) > 1) {
    $timLabel = count($laporanTim) . ' tim';
}
$genderLabel = $laporanGender !== '' ? $laporanGender : 'Gabungan';

$subtitle = 'Periode: ' . $periodeLabel . ' • Tim: ' . $timLabel . ' • Gender: ' . $genderLabel . ' • ' . count($filtered) . ' laporan';
$pdf = new ReportPdf('Rekap Laporan Mentoring', $subtitle);
$s3 = new S3Client();

foreach ($filtered as $summary) {
    $report = ReportRepository::load((string)$summary['id']);
    if ($report === null) {
        continue;
    }

    $jpeg = null;
    $imageWidth = 0;
    $imageHeight = 0;
    $dokPath = (string)($summary['dokumentasi_path'] ?? '');

    if ($dokPath !== '') {
        $rawImage = $s3->getObject($dokPath);
        if (is_string($rawImage) && $rawImage !== '') {
            $compressed = ReportPdf::compressImage($rawImage, 900, 55);
            if ($compressed !== null) {
                $jpeg = $compressed['data'];
                $imageWidth = $compressed['width'];
                $imageHeight = $compressed['height'];
            }
        }
    }

    $pdf->addReport($report, $jpeg, $imageWidth, $imageHeight);
}

if ($filtered === []) {
    $pdf->addNotice('Belum ada laporan pada filter periode/tim/gender yang dipilih.');
}

$pdfData = $pdf->output();
$filename = 'rekap-mentoring-' . date('Ymd') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfData));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $pdfData;
exit;
