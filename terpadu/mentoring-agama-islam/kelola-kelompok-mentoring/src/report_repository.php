<?php
/**
 * src/report_repository.php — Layanan Pengelolaan Laporan Mentoring
 * Membaca, memperbarui, menghapus, dan menyusun rekap laporan yang tersimpan
 * pada pointer_laporan.json + Blomp S3 (laporan_json/, og_meta/, dokumentasi/).
 */

declare(strict_types=1);

namespace Mentoring;

class ReportRepository {
    /**
     * Ringkasan seluruh laporan dari pointer lokal (tanpa mengunduh file S3).
     * Dipakai untuk daftar pada tab "Kelola Laporan" agar cepat dirender.
     */
    public static function summaries(): array {
        $pointers = Storage::getPointers('laporan');
        $summaries = [];

        foreach ($pointers as $p) {
            if (!is_array($p) || empty($p['id'])) {
                continue;
            }

            $summaries[] = [
                'id' => (string)$p['id'],
                'tim_id' => (string)($p['tim_id'] ?? ''),
                'nama_tim' => (string)($p['nama_tim'] ?? 'Tanpa Tim'),
                'tanggal' => (string)($p['tanggal'] ?? ''),
                'tema' => (string)($p['tema'] ?? ''),
                'dokumentasi_path' => (string)($p['dokumentasi_path'] ?? ''),
                'created_at' => (string)($p['created_at'] ?? ''),
            ];
        }

        usort($summaries, static function (array $a, array $b): int {
            $dateA = $a['tanggal'] !== '' ? $a['tanggal'] : substr($a['created_at'], 0, 10);
            $dateB = $b['tanggal'] !== '' ? $b['tanggal'] : substr($b['created_at'], 0, 10);
            return strcmp($dateB, $dateA) ?: strcmp($b['created_at'], $a['created_at']);
        });

        return $summaries;
    }

    /**
     * Unduh & dekripsi satu laporan lengkap berdasarkan ID.
     */
    public static function load(string $id): ?array {
        $pointer = Storage::findPointer('laporan', $id);
        if (!$pointer || empty($pointer['s3_path']) || empty($pointer['crypto'])) {
            return null;
        }

        $s3 = new S3Client();
        $raw = $s3->getObject((string)$pointer['s3_path']);
        if ($raw === null) {
            return null;
        }

        $report = Crypto::decryptData(
            $raw,
            (string)($pointer['crypto']['key'] ?? ''),
            (string)($pointer['crypto']['iv'] ?? ''),
            (string)($pointer['crypto']['tag'] ?? ''),
            true
        );

        if (!is_array($report)) {
            return null;
        }

        $report['id'] = $report['id'] ?? $id;
        $report['nama_tim'] = $report['nama_tim'] ?? ($pointer['nama_tim'] ?? 'Tanpa Tim');
        $report['tanggal'] = $report['tanggal'] ?? ($pointer['tanggal'] ?? '');
        $report['tema'] = $report['tema'] ?? ($pointer['tema'] ?? '');
        $report['hadir'] = array_values($report['hadir'] ?? []);
        $report['izin'] = array_values($report['izin'] ?? []);
        $report['izin_keterangan'] = is_array($report['izin_keterangan'] ?? null) ? $report['izin_keterangan'] : [];
        $report['absen'] = array_values($report['absen'] ?? []);

        return $report;
    }

    /**
     * Simpan ulang laporan hasil pengelolaan admin (enkripsi + og_meta + pointer).
     */
    public static function save(array $report): bool {
        $id = trim((string)($report['id'] ?? ''));
        if ($id === '') {
            return false;
        }

        $pointer = Storage::findPointer('laporan', $id) ?? [];

        $laporanFolder = Config::get('storage.folders.laporan_json', 'laporan_json/');
        $laporanPath = (string)($pointer['s3_path'] ?? ($laporanFolder . $id . '.enc'));

        $dokFolder = Config::get('storage.folders.dokumentasi', 'dokumentasi/');
        $dokPath = (string)($pointer['dokumentasi_path'] ?? ($dokFolder . $id . '.' . ImageProcessor::outputExtension()));

        $ogFolder = Config::get('storage.folders.og_meta', 'og_meta/');
        $ogPath = $ogFolder . $id . '.json';

        $s3 = new S3Client();

        // 1. Payload privat: enkripsi & kompresi
        $cryptoResult = Crypto::encryptData($report);
        if (!$s3->putObject($laporanPath, $cryptoResult['ciphertext_raw'], 'application/octet-stream', false)) {
            return false;
        }

        // 2. OG meta publik untuk crawler WhatsApp
        $ogMeta = [
            'title' => 'Laporan Mentoring ' . ($report['nama_tim'] ?? ''),
            'description' => 'Tema: ' . ($report['tema'] ?? '') . ' | Waktu: '
                . Watobot::formatTanggalIndonesia((string)($report['tanggal'] ?? date('Y-m-d')))
                . ' | Tempat: ' . ($report['tempat'] ?? ''),
            'image' => $dokPath,
            'tim' => $report['nama_tim'] ?? '',
        ];
        $s3->putObject($ogPath, json_encode($ogMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json', true);

        // 3. Perbarui pointer lokal
        return Storage::savePointer('laporan', [
            'id' => $id,
            'tim_id' => (string)($report['tim_id'] ?? ($pointer['tim_id'] ?? '')),
            'nama_tim' => (string)($report['nama_tim'] ?? ''),
            'tanggal' => (string)($report['tanggal'] ?? ''),
            'tema' => (string)($report['tema'] ?? ''),
            's3_path' => $laporanPath,
            'dokumentasi_path' => $dokPath,
            'created_at' => (string)($pointer['created_at'] ?? date('c')),
            'updated_at' => date('c'),
            'crypto' => [
                'key' => $cryptoResult['key_b64'],
                'iv' => $cryptoResult['iv_b64'],
                'tag' => $cryptoResult['tag_b64'],
            ],
        ]);
    }

    /**
     * Hapus satu laporan beserta objek S3-nya.
     */
    public static function delete(string $id): bool {
        $pointer = Storage::findPointer('laporan', $id);
        if (!$pointer) {
            return false;
        }

        $s3 = new S3Client();
        $ogFolder = Config::get('storage.folders.og_meta', 'og_meta/');

        $dokFolder = Config::get('storage.folders.dokumentasi', 'dokumentasi/');
        $objects = array_filter([
            (string)($pointer['s3_path'] ?? ''),
            (string)($pointer['dokumentasi_path'] ?? ''),
            $dokFolder . $id . '.webp',
            $dokFolder . $id . '.jpg',
            $ogFolder . $id . '.json',
        ]);

        foreach (array_unique($objects) as $key) {
            // Best effort: pointer tetap dihapus walau objek S3 tidak ditemukan.
            $s3->deleteObject($key);
        }

        return Storage::deletePointer('laporan', $id);
    }

    /**
     * Susun rekap dari daftar ringkasan yang sudah difilter.
     * Mengembalikan total laporan, agregat kehadiran, dan rekap per tim.
     */
    public static function recap(array $summaries): array {
        $recap = [
            'total_laporan' => 0,
            'total_hadir' => 0,
            'total_izin' => 0,
            'total_absen' => 0,
            'teams' => [],
            'rows' => [],
        ];

        foreach ($summaries as $summary) {
            $report = self::load((string)$summary['id']);
            if ($report === null) {
                continue;
            }

            $hadir = count($report['hadir'] ?? []);
            $izin = count($report['izin'] ?? []);
            $absen = count($report['absen'] ?? []);

            $recap['total_laporan']++;
            $recap['total_hadir'] += $hadir;
            $recap['total_izin'] += $izin;
            $recap['total_absen'] += $absen;

            $recap['rows'][] = array_merge($summary, [
                'hadir' => $hadir,
                'izin' => $izin,
                'absen' => $absen,
                'mentor' => (string)($report['mentor'] ?? ''),
            ]);

            $teamKey = (string)($report['nama_tim'] ?? 'Tanpa Tim');
            if (!isset($recap['teams'][$teamKey])) {
                $recap['teams'][$teamKey] = [
                    'nama_tim' => $teamKey,
                    'laporan' => 0,
                    'hadir' => 0,
                    'izin' => 0,
                    'absen' => 0,
                ];
            }

            $recap['teams'][$teamKey]['laporan']++;
            $recap['teams'][$teamKey]['hadir'] += $hadir;
            $recap['teams'][$teamKey]['izin'] += $izin;
            $recap['teams'][$teamKey]['absen'] += $absen;
        }

        uasort($recap['teams'], static fn(array $a, array $b): int => strcmp($a['nama_tim'], $b['nama_tim']));

        return $recap;
    }

    /**
     * Peta gender tim (Ikhwan/Akhwat) dari data master. Kunci berupa
     * `tim:<id>` dan `nama:<nama_tim>` agar laporan lama tanpa tim_id tetap terdeteksi.
     *
     * @return array<string,string>
     */
    public static function teamGenderMap(): array {
        $master = MasterData::loadAll();

        $mentorGender = [];
        foreach ($master['mentors'] ?? [] as $mentor) {
            $gender = (string)($mentor['gender'] ?? '');
            $mentorGender[(string)($mentor['id'] ?? '')] = match ($gender) {
                'Laki-laki' => 'Ikhwan',
                'Perempuan' => 'Akhwat',
                default => '',
            };
        }

        $map = [];
        foreach ($master['teams'] ?? [] as $team) {
            $teamId = (string)($team['id'] ?? '');
            $teamName = (string)($team['nama_tim'] ?? '');
            $gender = $mentorGender[(string)($team['mentor_id'] ?? '')] ?? '';

            if ($gender === '') {
                $ikhwan = 0;
                $akhwat = 0;
                foreach ($master['mentees'] ?? [] as $mentee) {
                    if ((string)($mentee['tim_id'] ?? '') !== $teamId) {
                        continue;
                    }
                    if (($mentee['gender'] ?? '') === 'Laki-laki') {
                        $ikhwan++;
                    } elseif (($mentee['gender'] ?? '') === 'Perempuan') {
                        $akhwat++;
                    }
                }
                if ($ikhwan > $akhwat) {
                    $gender = 'Ikhwan';
                } elseif ($akhwat > $ikhwan) {
                    $gender = 'Akhwat';
                }
            }

            if ($teamId !== '') {
                $map['tim:' . $teamId] = $gender;
            }
            if ($teamName !== '') {
                $map['nama:' . $teamName] = $gender;
            }
        }

        return $map;
    }

    /**
     * Persentase kehadiran dari agregat (0 jika belum ada data).
     */
    public static function attendanceRate(int $hadir, int $izin, int $absen): float {
        $total = $hadir + $izin + $absen;
        if ($total === 0) {
            return 0.0;
        }
        return round(($hadir / $total) * 100, 1);
    }

    /**
     * Pulihkan laporan mentoring beserta og_meta dan dokumentasinya dari bundel backup.
     *
     * @param array<int,array<string,mixed>> $pointers
     * @param array<string,string> $objects Base64-encoded S3 objects keyed by relative S3 path
     * @param array<string,string> $checksums SHA-256 hex keyed by relative S3 path
     * @return array{ok:bool,restored:int,media_restored:int,skipped:array<int,string>,error:?string}
     */
    public static function importFromBackup(array $pointers, array $objects, array $checksums, bool $replaceAll = false): array {
        $s3 = new S3Client();
        $ogFolder = Config::get('storage.folders.og_meta', 'og_meta/');
        $restoredPointers = [];
        $skipped = [];
        $mediaRestored = 0;

        foreach ($pointers as $pointer) {
            if (!is_array($pointer)) {
                continue;
            }
            $id = trim((string)($pointer['id'] ?? ''));
            $s3Path = (string)($pointer['s3_path'] ?? '');
            $crypto = $pointer['crypto'] ?? null;

            if ($id === '' || $s3Path === '' || !is_array($crypto)) {
                $skipped[] = $id !== '' ? $id : '(tanpa-id)';
                continue;
            }

            $b64 = $objects[$s3Path] ?? null;
            if (!is_string($b64) || $b64 === '') {
                $skipped[] = $id;
                continue;
            }

            $raw = base64_decode($b64, true);
            if ($raw === false) {
                $skipped[] = $id;
                continue;
            }

            if (isset($checksums[$s3Path]) && !hash_equals((string)$checksums[$s3Path], hash('sha256', $raw))) {
                $skipped[] = $id . ' (checksum tidak cocok)';
                continue;
            }

            $decrypted = Crypto::decryptData(
                $raw,
                (string)($crypto['key'] ?? ''),
                (string)($crypto['iv'] ?? ''),
                (string)($crypto['tag'] ?? ''),
                true
            );
            if (!is_array($decrypted)) {
                $skipped[] = $id . ' (gagal dekripsi)';
                continue;
            }

            if (!$s3->putObject($s3Path, $raw, 'application/octet-stream', false)) {
                $skipped[] = $id . ' (gagal unggah S3)';
                continue;
            }

            $dokPath = (string)($pointer['dokumentasi_path'] ?? '');
            if ($dokPath !== '' && is_string($objects[$dokPath] ?? null)) {
                $dokRaw = base64_decode($objects[$dokPath], true);
                if ($dokRaw !== false && (!isset($checksums[$dokPath]) || hash_equals((string)$checksums[$dokPath], hash('sha256', $dokRaw)))) {
                    $ext = strtolower(pathinfo($dokPath, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'webp' => 'image/webp',
                        'png' => 'image/png',
                        default => 'image/jpeg',
                    };
                    if ($s3->putObject($dokPath, $dokRaw, $mime, true)) {
                        $mediaRestored++;
                    }
                }
            }

            $ogPath = $ogFolder . $id . '.json';
            $ogRaw = null;
            if (is_string($objects[$ogPath] ?? null)) {
                $decodedOg = base64_decode($objects[$ogPath], true);
                if ($decodedOg !== false && (!isset($checksums[$ogPath]) || hash_equals((string)$checksums[$ogPath], hash('sha256', $decodedOg)))) {
                    $ogRaw = $decodedOg;
                }
            }
            if ($ogRaw === null) {
                $ogMeta = [
                    'title' => 'Laporan Mentoring ' . ($decrypted['nama_tim'] ?? ($pointer['nama_tim'] ?? '')),
                    'description' => 'Tema: ' . ($decrypted['tema'] ?? ($pointer['tema'] ?? '')) . ' | Waktu: '
                        . Watobot::formatTanggalIndonesia((string)($decrypted['tanggal'] ?? ($pointer['tanggal'] ?? date('Y-m-d'))))
                        . ' | Tempat: ' . ($decrypted['tempat'] ?? ''),
                    'image' => $dokPath,
                    'tim' => $decrypted['nama_tim'] ?? ($pointer['nama_tim'] ?? ''),
                ];
                $ogRaw = (string)json_encode($ogMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $s3->putObject($ogPath, $ogRaw, 'application/json', true);

            $restoredPointers[] = $pointer;
        }

        if ($replaceAll) {
            $ok = Storage::replacePointers('laporan', $restoredPointers);
        } else {
            try {
                $existing = Storage::getPointers('laporan');
            } catch (\Throwable $e) {
                $existing = [];
            }

            $byId = [];
            foreach ($existing as $p) {
                if (is_array($p) && !empty($p['id'])) {
                    $byId[(string)$p['id']] = $p;
                }
            }
            foreach ($restoredPointers as $p) {
                $byId[(string)$p['id']] = $p;
            }

            $ok = Storage::replacePointers('laporan', array_values($byId));
        }

        if (!$ok) {
            return [
                'ok' => false,
                'restored' => count($restoredPointers),
                'media_restored' => $mediaRestored,
                'skipped' => $skipped,
                'error' => 'Gagal memperbarui pointer_laporan.json.',
            ];
        }

        return [
            'ok' => true,
            'restored' => count($restoredPointers),
            'media_restored' => $mediaRestored,
            'skipped' => $skipped,
            'error' => null,
        ];
    }
}
