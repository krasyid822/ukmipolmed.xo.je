<?php
/**
 * src/backup_service.php — Layanan Backup & Restore Sistem Menyeluruh
 *
 * Mencakup:
 * - Data Master (pointer_master.json + seluruh versi master_json/*.enc)
 * - Data Laporan (pointer_laporan.json + laporan_json/*.enc + og_meta/*.json)
 * - Media S3 (dokumentasi laporan & foto profil mentor/tim/mentee/pendaftar)
 * - State Lokal (biometric_setup.json, bot_queue.json, dan opsional default.json)
 *
 * Tetap kompatibel mundur dengan format lama `mentoring-master-backup`.
 */

declare(strict_types=1);

namespace Mentoring;

final class BackupService {
    public const FULL_FORMAT = 'mentoring-full-backup';
    public const FULL_VERSION = 2;

    /**
     * Ringkasan seluruh data yang tersimpan untuk ditampilkan di UI backup.
     *
     * @return array<string,mixed>
     */
    public static function summary(): array {
        $history = [];
        try {
            $history = MasterData::history();
        } catch (\Throwable $e) {
            $history = [];
        }

        $reportPointers = [];
        try {
            $reportPointers = Storage::getPointers('laporan');
        } catch (\Throwable $e) {
            $reportPointers = [];
        }

        $dokPaths = [];
        foreach ($reportPointers as $p) {
            $path = trim((string)($p['dokumentasi_path'] ?? ''));
            if ($path !== '') {
                $dokPaths[$path] = true;
            }
        }

        $profilPaths = self::collectProfilePaths();
        $secureDir = Config::getSecureDir();

        return [
            'master_versions' => count($history),
            'master_updated_at' => (string)($history[0]['updated_at'] ?? ''),
            'total_reports' => count($reportPointers),
            'total_dokumentasi' => count($dokPaths),
            'total_profil' => count($profilPaths),
            'has_biometric' => is_file($secureDir . '/biometric_setup.json'),
            'has_bot_queue' => is_file($secureDir . '/bot_queue.json'),
            'has_config' => is_file($secureDir . '/default.json'),
        ];
    }

    /**
     * Kumpulkan seluruh path foto profil unik dari data master aktif.
     *
     * @return array<int,string>
     */
    private static function collectProfilePaths(): array {
        try {
            $master = MasterData::loadAll();
        } catch (\Throwable $e) {
            return [];
        }

        $paths = [];
        foreach (['mentors', 'teams', 'mentees', 'registrants'] as $group) {
            foreach ($master[$group] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['foto_profil', 'foto', 'photo_path'] as $field) {
                    $val = trim((string)($item[$field] ?? ''));
                    if ($val !== '' && !str_starts_with($val, 'http://') && !str_starts_with($val, 'https://')) {
                        $paths[$val] = true;
                    }
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * Streaming unduhan bundel backup menyeluruh langsung ke php://output.
     *
     * @param array<string,bool> $options
     */
    public static function streamFullBackup(array $options = []): void {
        @set_time_limit(0);

        $includeMaster = $options['include_master'] ?? true;
        $includeReports = $options['include_reports'] ?? true;
        $includeMedia = $options['include_media'] ?? true;
        $includeLocalState = $options['include_local_state'] ?? true;
        $includeConfig = $options['include_config'] ?? false;

        $s3 = new S3Client();

        // Fail-closed: bila master dipilih dan pointer ada, pastikan objek terbaru bisa diunduh
        $masterPointer = null;
        $masterHistory = [];
        $currentMasterRaw = null;
        if ($includeMaster) {
            $masterPointer = MasterData::currentPointer();
            if ($masterPointer !== null) {
                $currentPath = (string)($masterPointer['s3_path'] ?? '');
                if ($currentPath === '') {
                    throw new \RuntimeException('Pointer master tidak memuat lokasi objek terbaru.');
                }
                $currentMasterRaw = $s3->getObject($currentPath);
                if ($currentMasterRaw === null) {
                    throw new \RuntimeException('Objek master terbaru tidak dapat diunduh; backup dibatalkan.');
                }
                $masterHistory = MasterData::history();
            }
        }

        $reportPointers = [];
        if ($includeReports) {
            $reportPointers = Storage::getPointers('laporan');
        }

        $secureDir = Config::getSecureDir();
        $localState = [];
        if ($includeLocalState) {
            $bio = JsonFile::read($secureDir . '/biometric_setup.json');
            if ($bio !== null) {
                $localState['biometric_setup'] = $bio;
            }
            $queue = JsonFile::read($secureDir . '/bot_queue.json');
            if ($queue !== null) {
                $localState['bot_queue'] = $queue;
            }
        }
        if ($includeConfig) {
            $cfg = JsonFile::read($secureDir . '/default.json');
            if ($cfg !== null) {
                $localState['default_config'] = $cfg;
            }
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $encode = static fn($value): string => (string)json_encode($value, $flags);

        $filename = 'mentoring-backup-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, private');

        echo '{"format":' . $encode(self::FULL_FORMAT);
        echo ',"format_version":' . self::FULL_VERSION;
        echo ',"created_at":' . $encode(date('c'));
        echo ',"schema_version":' . MasterSchema::VERSION;
        echo ',"app":"kelola-kelompok-mentoring"';
        echo ',"includes":' . $encode([
            'master' => $includeMaster,
            'reports' => $includeReports,
            'media' => $includeMedia,
            'local_state' => $includeLocalState,
            'config' => $includeConfig,
        ]);
        echo ',"master":' . $encode([
            'pointer' => $masterPointer,
            'history' => $masterHistory,
        ]);
        echo ',"reports":' . $encode([
            'pointers' => $reportPointers,
        ]);
        echo ',"local_state":' . $encode((object)$localState);
        echo ',"notes":' . $encode(
            'Bundel backup sistem menyeluruh. Memuat kunci enkripsi dan data sensitif. '
            . 'Jangan dibagikan. Pulihkan lewat master/restore.php.'
        );

        echo ',"objects":{';
        $checksums = [];
        $missing = [];
        $first = true;

        $streamObject = static function (string $path, ?string $preloaded = null) use ($s3, &$checksums, &$missing, &$first, $encode): void {
            if ($path === '' || isset($checksums[$path])) {
                return;
            }
            $raw = $preloaded ?? $s3->getObject($path);
            if ($raw === null) {
                $missing[] = $path;
                return;
            }
            $checksums[$path] = hash('sha256', $raw);
            echo ($first ? '' : ',') . $encode($path) . ':' . $encode(base64_encode($raw));
            $first = false;
        };

        if ($includeMaster && $masterPointer !== null) {
            $currentPath = (string)($masterPointer['s3_path'] ?? '');
            foreach ($masterHistory as $entry) {
                $path = (string)($entry['s3_path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $streamObject($path, $path === $currentPath ? $currentMasterRaw : null);
            }
        }

        if ($includeReports) {
            $ogFolder = Config::get('storage.folders.og_meta', 'og_meta/');
            foreach ($reportPointers as $p) {
                $id = (string)($p['id'] ?? '');
                $s3Path = (string)($p['s3_path'] ?? '');
                if ($s3Path !== '') {
                    $streamObject($s3Path);
                }
                if ($id !== '') {
                    $streamObject($ogFolder . $id . '.json');
                }
                if ($includeMedia) {
                    $dokPath = (string)($p['dokumentasi_path'] ?? '');
                    if ($dokPath !== '') {
                        $streamObject($dokPath);
                    }
                }
            }
        }

        if ($includeMedia) {
            foreach (self::collectProfilePaths() as $profilPath) {
                $streamObject($profilPath);
            }
        }

        echo '}';
        echo ',"checksums":' . $encode((object)$checksums);
        echo ',"missing_objects":' . $encode($missing);
        echo '}';
        exit;
    }

    /**
     * Pulihkan bundel backup (mendukung format penuh v2 maupun format master v1).
     *
     * @param array<string,mixed> $bundle
     * @param array<string,bool> $options
     * @return array{ok:bool,error:?string,summary:array<string,int>,warnings:array<int,string>}
     */
    public static function restoreBundle(array $bundle, array $options = []): array {
        $format = (string)($bundle['format'] ?? '');

        if ($format === 'mentoring-master-backup') {
            $res = MasterData::importBundle($bundle);
            return [
                'ok' => (bool)$res['ok'],
                'error' => $res['error'] ?? null,
                'summary' => ['master' => !empty($res['ok']) ? 1 : 0, 'reports' => 0, 'media' => 0, 'local_state' => 0],
                'warnings' => [],
            ];
        }

        if ($format !== self::FULL_FORMAT) {
            return [
                'ok' => false,
                'error' => 'Format berkas backup tidak dikenali.',
                'summary' => [],
                'warnings' => [],
            ];
        }

        $objects = is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];
        $checksums = is_array($bundle['checksums'] ?? null) ? $bundle['checksums'] : [];
        $replaceReports = !empty($options['replace_reports']);
        $restoreConfig = !empty($options['restore_config']);

        $summary = [
            'master' => 0,
            'reports' => 0,
            'media' => 0,
            'local_state' => 0,
        ];
        $warnings = [];

        // 1. Pulihkan Data Master (jika ada)
        $masterSection = is_array($bundle['master'] ?? null) ? $bundle['master'] : [];
        if (!empty($masterSection['pointer']) && is_array($masterSection['pointer'])) {
            $masterPayload = [
                'format' => 'mentoring-master-backup',
                'format_version' => 1,
                'pointer' => $masterSection['pointer'],
                'history' => is_array($masterSection['history'] ?? null) ? $masterSection['history'] : [],
                'objects' => $objects,
                'checksums' => $checksums,
            ];
            $mRes = MasterData::importBundle($masterPayload);
            if (empty($mRes['ok'])) {
                return [
                    'ok' => false,
                    'error' => 'Pemulihan data master gagal: ' . ($mRes['error'] ?? 'kesalahan tidak diketahui'),
                    'summary' => $summary,
                    'warnings' => $warnings,
                ];
            }
            $summary['master'] = 1;
        }

        // 2. Pulihkan Data Laporan + OG Meta + Dokumentasi
        $reportSection = is_array($bundle['reports'] ?? null) ? $bundle['reports'] : [];
        $reportPointers = is_array($reportSection['pointers'] ?? null) ? $reportSection['pointers'] : [];
        if ($reportPointers !== []) {
            $rRes = ReportRepository::importFromBackup($reportPointers, $objects, $checksums, $replaceReports);
            if (empty($rRes['ok'])) {
                return [
                    'ok' => false,
                    'error' => 'Pemulihan laporan gagal: ' . ($rRes['error'] ?? 'kesalahan tidak diketahui'),
                    'summary' => $summary,
                    'warnings' => $warnings,
                ];
            }
            $summary['reports'] = (int)($rRes['restored'] ?? 0);
            $summary['media'] += (int)($rRes['media_restored'] ?? 0);
            if (!empty($rRes['skipped'])) {
                $warnings[] = 'Sebagian laporan dilewati: ' . implode(', ', $rRes['skipped']);
            }
        }

        // 3. Pulihkan Foto Profil di S3
        $profilPrefix = Config::get('storage.folders.profil', 'profil/');
        $s3 = new S3Client();
        foreach ($objects as $path => $b64) {
            if (!is_string($path) || !str_starts_with($path, $profilPrefix) || !is_string($b64)) {
                continue;
            }
            $raw = base64_decode($b64, true);
            if ($raw === false) {
                continue;
            }
            if (isset($checksums[$path]) && !hash_equals((string)$checksums[$path], hash('sha256', $raw))) {
                $warnings[] = 'Checksum foto profil tidak cocok: ' . $path;
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'webp' => 'image/webp',
                'png' => 'image/png',
                default => 'image/jpeg',
            };
            if ($s3->putObject($path, $raw, $mime, true)) {
                $summary['media']++;
            }
        }

        // 4. Pulihkan State Lokal
        $localState = is_array($bundle['local_state'] ?? null) ? $bundle['local_state'] : [];
        $secureDir = Config::getSecureDir();

        if (is_array($localState['biometric_setup'] ?? null)) {
            if (JsonFile::writeAtomic($secureDir . '/biometric_setup.json', $localState['biometric_setup'])) {
                $summary['local_state']++;
            } else {
                $warnings[] = 'Gagal menulis ulang biometric_setup.json.';
            }
        }

        if (is_array($localState['bot_queue'] ?? null)) {
            if (JsonFile::writeAtomic($secureDir . '/bot_queue.json', $localState['bot_queue'])) {
                $summary['local_state']++;
            } else {
                $warnings[] = 'Gagal menulis ulang bot_queue.json.';
            }
        }

        if ($restoreConfig && is_array($localState['default_config'] ?? null)) {
            if (JsonFile::writeAtomic($secureDir . '/default.json', $localState['default_config'])) {
                $summary['local_state']++;
            } else {
                $warnings[] = 'Gagal menulis ulang default.json.';
            }
        }

        return [
            'ok' => true,
            'error' => null,
            'summary' => $summary,
            'warnings' => $warnings,
        ];
    }

    /**
     * Format ukuran byte menjadi string yang mudah dibaca.
     */
    public static function formatBytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        foreach ($units as $idx => $unit) {
            if ($value < 1024 || $idx === count($units) - 1) {
                return number_format($value, 2, '.', '') . ' ' . $unit;
            }
            $value /= 1024;
        }
        return number_format($value, 2, '.', '') . ' GB';
    }

    /**
     * Hitung perkiraan ukuran berkas backup (.json) tanpa mengunduh isi objek S3.
     *
     * @param array<string,bool> $options
     * @return array<string,mixed>
     */
    public static function estimateSize(array $options = []): array {
        $includeMaster = $options['include_master'] ?? true;
        $includeReports = $options['include_reports'] ?? true;
        $includeMedia = $options['include_media'] ?? true;
        $includeLocalState = $options['include_local_state'] ?? true;
        $includeConfig = $options['include_config'] ?? false;

        $s3 = new S3Client();
        $secureDir = Config::getSecureDir();

        $seen = [];
        $objectCount = 0;
        $missingCount = 0;
        $breakdown = [
            'master' => 0,
            'reports' => 0,
            'media' => 0,
            'local_state' => 0,
            'config' => 0,
        ];

        $measureS3 = static function (string $path, string $bucket) use ($s3, &$seen, &$objectCount, &$missingCount, &$breakdown): void {
            if ($path === '' || isset($seen[$path])) {
                return;
            }
            $seen[$path] = true;
            $size = $s3->getObjectSize($path);
            if ($size === null) {
                $missingCount++;
                return;
            }
            $objectCount++;
            // Base64 overhead (~4/3) + JSON key + entry checksum SHA-256
            $breakdown[$bucket] += ((int)ceil($size / 3) * 4) + strlen($path) + 120;
        };

        if ($includeMaster) {
            $pmPath = $secureDir . '/pointer_master.json';
            if (is_file($pmPath)) {
                $breakdown['master'] += (int)@filesize($pmPath);
            }
            try {
                foreach (MasterData::history() as $entry) {
                    $measureS3((string)($entry['s3_path'] ?? ''), 'master');
                }
            } catch (\Throwable $e) {
                // Abaikan jika pointer master belum ada
            }
        }

        if ($includeReports || $includeMedia) {
            $reportPointers = [];
            try {
                $reportPointers = Storage::getPointers('laporan');
            } catch (\Throwable $e) {
                $reportPointers = [];
            }

            if ($includeReports) {
                $plPath = $secureDir . '/pointer_laporan.json';
                if (is_file($plPath)) {
                    $breakdown['reports'] += (int)@filesize($plPath);
                }
                $ogFolder = Config::get('storage.folders.og_meta', 'og_meta/');
                foreach ($reportPointers as $p) {
                    $id = (string)($p['id'] ?? '');
                    $measureS3((string)($p['s3_path'] ?? ''), 'reports');
                    if ($id !== '') {
                        $measureS3($ogFolder . $id . '.json', 'reports');
                    }
                }
            }

            if ($includeMedia) {
                foreach ($reportPointers as $p) {
                    $dokPath = (string)($p['dokumentasi_path'] ?? '');
                    if ($dokPath !== '') {
                        $measureS3($dokPath, 'media');
                    }
                }
                foreach (self::collectProfilePaths() as $profilPath) {
                    $measureS3($profilPath, 'media');
                }
            }
        }

        if ($includeLocalState) {
            foreach (['biometric_setup.json', 'bot_queue.json'] as $file) {
                $fPath = $secureDir . '/' . $file;
                if (is_file($fPath)) {
                    $breakdown['local_state'] += (int)@filesize($fPath);
                }
            }
        }

        if ($includeConfig) {
            $cfgPath = $secureDir . '/default.json';
            if (is_file($cfgPath)) {
                $breakdown['config'] += (int)@filesize($cfgPath);
            }
        }

        $totalBytes = 512 + array_sum($breakdown);

        return [
            'ok' => true,
            'total_bytes' => $totalBytes,
            'total_formatted' => self::formatBytes($totalBytes),
            'breakdown' => $breakdown,
            'object_count' => $objectCount,
            'missing_count' => $missingCount,
        ];
    }
}
