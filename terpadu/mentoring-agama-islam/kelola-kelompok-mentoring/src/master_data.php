<?php
/**
 * src/master_data.php — Layanan Master Data (Mentors, Teams, Mentees)
 *
 * Prinsip penyimpanan:
 * - Fail-closed: data yang gagal dimuat tidak boleh ditimpa.
 * - Append-only: setiap penyimpanan membuat objek versi baru di Blomp S3;
 *   versi lama disimpan pada riwayat pointer sehingga bisa di-rollback.
 * - Satu jalur tulis: semua mutasi lewat transaction() dengan kunci eksklusif.
 */

declare(strict_types=1);

namespace Mentoring;

class MasterData {
    private const POINTER_ID = 'master_latest';

    public static function historyLimit(): int {
        return max(1, (int)Config::get('app.master_history_limit', 30));
    }

    private static function getLockFile(): string {
        return Config::getSecureDir() . '/master.lock';
    }

    /**
     * Muat data master untuk kebutuhan baca/tampilan. Bila pemuatan gagal,
     * kembalikan dataset kosong dan catat ke log. JANGAN dipakai sebagai
     * dasar penulisan — gunakan transaction() atau loadSnapshot().
     */
    public static function loadAll(): array {
        $snapshot = self::loadSnapshot();
        if ($snapshot['ok']) {
            return $snapshot['data'];
        }

        error_log('Master data gagal dimuat: ' . $snapshot['reason']);
        return MasterSchema::emptyDataset();
    }

    /**
     * Muat snapshot lengkap beserta status keberhasilannya.
     *
     * @return array{ok:bool,reason:string,data:array,revision:?string,summary:array,source:string}
     */
    public static function loadSnapshot(): array {
        try {
            $pointer = Storage::findPointer('master', self::POINTER_ID);
        } catch (\Throwable $e) {
            return self::failure('Pointer master tidak dapat dibaca: ' . $e->getMessage());
        }

        if ($pointer === null) {
            $data = MasterSchema::emptyDataset();
            return [
                'ok' => true,
                'reason' => '',
                'data' => $data,
                'revision' => null,
                'summary' => self::summarize($data),
                'source' => 'empty',
            ];
        }

        $s3Path = (string)($pointer['s3_path'] ?? '');
        $crypto = $pointer['crypto'] ?? null;
        if ($s3Path === '' || !is_array($crypto) || !isset($crypto['key'], $crypto['iv'], $crypto['tag'])) {
            return self::failure('Pointer master tidak lengkap (path atau kunci enkripsi hilang).');
        }

        try {
            $s3 = new S3Client();
            $raw = $s3->getObject($s3Path);
        } catch (\Throwable $e) {
            return self::failure('Gagal mengunduh master: ' . $e->getMessage());
        }

        if ($raw === null) {
            return self::failure('Objek master tidak ditemukan atau tidak dapat diunduh: ' . $s3Path);
        }

        $decrypted = Crypto::decryptData(
            $raw,
            (string)$crypto['key'],
            (string)$crypto['iv'],
            (string)$crypto['tag'],
            true
        );

        if (!is_array($decrypted)) {
            return self::failure('Master gagal didekripsi (kunci atau tag tidak cocok).');
        }

        $data = MasterSchema::normalize($decrypted);
        $revision = isset($pointer['revision']) ? (string)$pointer['revision'] : MasterSchema::fingerprint($data);

        return [
            'ok' => true,
            'reason' => '',
            'data' => $data,
            'revision' => $revision,
            'summary' => self::summarize($data),
            'source' => 's3',
        ];
    }

    /**
     * Jalankan mutasi master dalam satu transaksi (baca → ubah → validasi → simpan).
     * Callback mengembalikan array data master yang baru, atau null bila tidak ada
     * perubahan. Callback boleh melempar MasterDataException untuk kesalahan yang
     * ingin ditampilkan ke pengguna.
     *
     * @return array{ok:bool,error:?string,error_key:?string,context:array}
     */
    public static function transaction(callable $fn, string $source = 'transaction'): array {
        try {
            return JsonFile::withLock(self::getLockFile(), static function () use ($fn, $source): array {
                $snapshot = self::loadSnapshot();
                if (!$snapshot['ok']) {
                    throw new MasterDataException(
                        'Data master tidak dapat dimuat sehingga penyimpanan dibatalkan: ' . $snapshot['reason'],
                        'muat'
                    );
                }

                $updated = $fn($snapshot['data'], $snapshot);
                if ($updated === null) {
                    return ['ok' => true, 'error' => null, 'error_key' => null, 'context' => []];
                }

                if (!is_array($updated)) {
                    throw new \InvalidArgumentException('Callback transaksi wajib mengembalikan array master atau null.');
                }

                self::saveAll($updated, [
                    'expected_revision' => $snapshot['revision'],
                    'source' => $source,
                ]);

                return ['ok' => true, 'error' => null, 'error_key' => null, 'context' => []];
            });
        } catch (MasterDataException $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'error_key' => $e->errorKey(),
                'context' => $e->context(),
            ];
        } catch (\Throwable $e) {
            error_log('Transaksi master gagal: ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => 'Gagal menyimpan data master. Data lama tidak diubah.',
                'error_key' => 'simpan',
                'context' => [],
            ];
        }
    }

    /**
     * Simpan seluruh master data sebagai versi baru (tidak menimpa versi lama).
     *
     * Opsi:
     * - expected_revision: revision yang dipegang pemanggil (optimistic locking)
     * - allow_empty: izinkan penyimpanan dataset kosong (khusus restore/rollback)
     * - source: penanda asal perubahan untuk riwayat
     */
    public static function saveAll(array $data, array $options = []): bool {
        $expectedRevision = array_key_exists('expected_revision', $options)
            ? ($options['expected_revision'] !== null ? (string)$options['expected_revision'] : null)
            : null;
        $allowEmpty = !empty($options['allow_empty']);
        $source = (string)($options['source'] ?? 'unknown');

        return JsonFile::withLock(self::getLockFile(), static function () use ($data, $expectedRevision, $allowEmpty, $source): bool {
            $data = MasterSchema::normalize($data);

            $errors = MasterSchema::validate($data);
            if ($errors !== []) {
                throw new MasterDataException(
                    'Data master tidak lolos validasi: ' . implode(' ', array_slice($errors, 0, 3)),
                    'validasi',
                    ['errors' => $errors]
                );
            }

            try {
                $pointer = Storage::findPointer('master', self::POINTER_ID);
            } catch (\Throwable $e) {
                throw new MasterDataException('Pointer master tidak dapat dibaca: ' . $e->getMessage(), 'storage');
            }

            $currentRevision = is_array($pointer) && isset($pointer['revision'])
                ? (string)$pointer['revision']
                : null;
            $currentSummary = is_array($pointer) && is_array($pointer['summary'] ?? null)
                ? $pointer['summary']
                : ($pointer !== null ? ['total_mentors' => 1] : []);

            if ($expectedRevision !== null && $currentRevision !== null
                && !hash_equals($currentRevision, $expectedRevision)) {
                throw new MasterDataException(
                    'Data master sudah berubah sejak halaman dimuat. Muat ulang halaman lalu ulangi perubahan.',
                    'konflik'
                );
            }

            if (!$allowEmpty && self::isEmptyDataset($data) && !self::isEmptySummary($currentSummary)) {
                throw new MasterDataException(
                    'Penyimpanan dibatalkan: hasil berisi data kosong sedangkan master tersimpan tidak kosong.',
                    'kosong'
                );
            }

            $revision = MasterSchema::fingerprint($data);
            $crypto = Crypto::encryptData($data);
            $s3Path = 'master_json/master_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(3)) . '.enc';
            $summary = self::summarize($data);

            $s3 = new S3Client();
            if (!$s3->putObject($s3Path, $crypto['ciphertext_raw'], 'application/octet-stream', false)) {
                throw new MasterDataException(
                    'Gagal mengunggah versi master baru ke penyimpanan. Data lama tidak diubah.',
                    'storage'
                );
            }

            $history = [];
            if (is_array($pointer['history'] ?? null)) {
                foreach ($pointer['history'] as $entry) {
                    if (is_array($entry)) {
                        $history[] = $entry;
                    }
                }
            }
            if ($pointer !== null && $history === [] && !empty($pointer['s3_path']) && is_array($pointer['crypto'] ?? null)) {
                $history[] = self::historyEntryFromPointer($pointer);
            }

            array_unshift($history, [
                's3_path' => $s3Path,
                'crypto' => [
                    'key' => $crypto['key_b64'],
                    'iv' => $crypto['iv_b64'],
                    'tag' => $crypto['tag_b64'],
                ],
                'revision' => $revision,
                'summary' => $summary,
                'updated_at' => date('c'),
                'source' => $source,
            ]);

            $historyLimit = self::historyLimit();
            $pruned = array_slice($history, $historyLimit);
            $history = array_slice($history, 0, $historyLimit);

            $record = [
                'id' => self::POINTER_ID,
                's3_path' => $s3Path,
                'revision' => $revision,
                'updated_at' => date('c'),
                'source' => $source,
                'schema_version' => MasterSchema::VERSION,
                'crypto' => [
                    'key' => $crypto['key_b64'],
                    'iv' => $crypto['iv_b64'],
                    'tag' => $crypto['tag_b64'],
                ],
                'summary' => $summary,
                'history' => $history,
            ];

            if (!Storage::savePointer('master', $record)) {
                throw new MasterDataException(
                    'Versi baru berhasil diunggah tetapi pointer master gagal diperbarui. Data lama tetap aktif.',
                    'storage'
                );
            }

            $retained = [];
            foreach ($history as $entry) {
                $retained[(string)($entry['s3_path'] ?? '')] = true;
            }
            foreach ($pruned as $entry) {
                $oldPath = (string)($entry['s3_path'] ?? '');
                if ($oldPath === '' || isset($retained[$oldPath]) || $oldPath === $s3Path) {
                    continue;
                }
                try {
                    $s3->deleteObject($oldPath);
                } catch (\Throwable $e) {
                    error_log('Gagal menghapus versi master lama ' . $oldPath . ': ' . $e->getMessage());
                }
            }

            return true;
        });
    }

    /**
     * Pointer master yang sedang aktif (null bila belum ada).
     */
    public static function currentPointer(): ?array {
        return Storage::findPointer('master', self::POINTER_ID);
    }

    /**
     * Daftar versi master yang tersimpan (terbaru lebih dulu).
     */
    public static function history(): array {        $pointer = Storage::findPointer('master', self::POINTER_ID);
        if ($pointer === null) {
            return [];
        }

        $entries = [];
        if (is_array($pointer['history'] ?? null)) {
            foreach ($pointer['history'] as $entry) {
                if (is_array($entry) && !empty($entry['s3_path'])) {
                    $entry['is_current'] = ($entry['s3_path'] ?? '') === ($pointer['s3_path'] ?? '');
                    $entries[] = $entry;
                }
            }
        }

        if ($entries === [] && !empty($pointer['s3_path'])) {
            $entry = self::historyEntryFromPointer($pointer);
            $entry['is_current'] = true;
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Kembalikan master ke versi tertentu dalam riwayat (membuat versi baru,
     * tidak menghapus apa pun).
     *
     * @return array{ok:bool,error:?string}
     */
    public static function rollbackTo(string $revisionOrPath): array {
        $entry = null;
        foreach (self::history() as $candidate) {
            if (($candidate['revision'] ?? '') === $revisionOrPath
                || ($candidate['s3_path'] ?? '') === $revisionOrPath) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry === null) {
            return ['ok' => false, 'error' => 'Versi master yang dipilih tidak ditemukan pada riwayat.'];
        }

        $data = self::fetchVersion($entry);
        if ($data === null) {
            return ['ok' => false, 'error' => 'Versi master gagal diunduh atau didekripsi.'];
        }

        try {
            self::saveAll($data, ['allow_empty' => true, 'source' => 'rollback']);
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Susun bundel backup penuh (pointer + seluruh objek versi + salinan lokal)
     * sebagai array JSON yang bisa dipulihkan tanpa dependensi zip.
     *
     * @return array<string,mixed>
     */
    public static function exportBundle(): array {
        $pointer = Storage::findPointer('master', self::POINTER_ID);
        if ($pointer === null) {
            throw new MasterDataException('Belum ada data master yang tersimpan untuk dibackup.', 'backup_kosong');
        }

        $entries = self::history();
        $s3 = new S3Client();
        $objects = [];
        $missing = [];

        foreach ($entries as $entry) {
            $path = (string)($entry['s3_path'] ?? '');
            if ($path === '' || isset($objects[$path])) {
                continue;
            }
            $raw = $s3->getObject($path);
            if ($raw === null) {
                $missing[] = $path;
                continue;
            }
            $objects[$path] = base64_encode($raw);
        }

        $currentPath = (string)($pointer['s3_path'] ?? '');
        if ($currentPath === '' || !isset($objects[$currentPath])) {
            throw new MasterDataException(
                'Objek master terbaru tidak dapat diunduh; backup dibatalkan agar tidak menghasilkan berkas tidak lengkap.',
                'backup_gagal',
                ['missing' => $missing]
            );
        }

        $checksums = [];
        foreach ($objects as $path => $b64) {
            $checksums[$path] = hash('sha256', (string)base64_decode($b64, true));
        }

        return [
            'format' => 'mentoring-master-backup',
            'format_version' => 1,
            'created_at' => date('c'),
            'schema_version' => MasterSchema::VERSION,
            'pointer' => $pointer,
            'history' => $entries,
            'objects' => $objects,
            'checksums' => $checksums,
            'missing_objects' => $missing,
            'notes' => 'Bundel ini memuat pointer beserta kunci enkripsi dan ciphertext master. '
                . 'Jangan dibagikan. Pulihkan lewat master/restore.php.',
        ];
    }

    /**
     * Pulihkan master dari bundel backup. Data aktif diganti dengan isi bundel
     * melalui jalur simpan normal (versi lama tetap ada di riwayat).
     *
     * @return array{ok:bool,error:?string,revision:?string}
     */
    public static function importBundle(array $bundle): array {
        if (($bundle['format'] ?? '') !== 'mentoring-master-backup') {
            return ['ok' => false, 'error' => 'Berkas bukan bundel backup master yang dikenali.', 'revision' => null];
        }

        $pointer = $bundle['pointer'] ?? null;
        $objects = $bundle['objects'] ?? [];
        if (!is_array($objects)) {
            $objects = [];
        }

        $data = null;
        if (is_array($pointer) && !empty($pointer['s3_path']) && is_array($pointer['crypto'] ?? null)) {
            $path = (string)$pointer['s3_path'];
            $b64 = $objects[$path] ?? null;
            if (is_string($b64)) {
                $raw = base64_decode($b64, true);
                if ($raw === false) {
                    return ['ok' => false, 'error' => 'Objek master pada bundel rusak (base64 tidak valid).', 'revision' => null];
                }

                $expectedSum = $bundle['checksums'][$path] ?? null;
                if (is_string($expectedSum) && !hash_equals($expectedSum, hash('sha256', $raw))) {
                    return ['ok' => false, 'error' => 'Objek master pada bundel gagal verifikasi checksum.', 'revision' => null];
                }

                $data = Crypto::decryptData(
                    $raw,
                    (string)$pointer['crypto']['key'],
                    (string)$pointer['crypto']['iv'],
                    (string)$pointer['crypto']['tag'],
                    true
                );
            }
        }

        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Bundel tidak memuat data master yang dapat didekripsi.', 'revision' => null];
        }

        // Kasus darurat: pointer lokal rusak/hilang justru alasan utama restore.
        // Tulis ulang pointer dari bundel agar saveAll dapat mencatat versi baru.
        if (is_array($pointer) && !empty($pointer['s3_path'])) {
            try {
                $localPointer = Storage::findPointer('master', self::POINTER_ID);
                $pointerUnreadable = false;
            } catch (\Throwable $e) {
                $localPointer = null;
                $pointerUnreadable = true;
            }

            if ($pointerUnreadable || $localPointer === null) {
                $seed = $pointer;
                $seed['id'] = self::POINTER_ID;
                if (!Storage::overwritePointer('master', $seed)) {
                    return [
                        'ok' => false,
                        'error' => 'Pointer master lokal tidak dapat dipulihkan dari bundel backup.',
                        'revision' => null,
                    ];
                }
            }
        }

        try {
            self::saveAll($data, ['allow_empty' => true, 'source' => 'restore']);
            return ['ok' => true, 'error' => null, 'revision' => MasterSchema::fingerprint(MasterSchema::normalize($data))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'revision' => null];
        }
    }

    public static function getTeamsWithDetails(): array {
        $data = self::loadAll();
        $mentorsById = [];
        foreach ($data['mentors'] ?? [] as $m) {
            $mentorsById[$m['id']] = $m;
        }

        $menteesByTeam = [];
        foreach ($data['mentees'] ?? [] as $me) {
            $me['kelas_tampil'] = Academic::formatClass($me['kelas'] ?? '', $me['nim'] ?? '');
            $me['semester'] = Academic::semesterFromNim($me['nim'] ?? '');
            $me['stambuk'] = Academic::entryYearFromNim($me['nim'] ?? '');
            $menteesByTeam[$me['tim_id'] ?? ''][] = $me;
        }

        $result = [];
        foreach ($data['teams'] ?? [] as $t) {
            $mId = $t['mentor_id'] ?? '';
            $mentor = $mentorsById[$mId] ?? null;
            $result[] = [
                'id' => $t['id'],
                'nama_tim' => $t['nama_tim'],
                'mentor_id' => $mId,
                'mentor_nama' => $mentor['nama_lengkap'] ?? 'Belum ditentukan',
                'mentor_wa' => $mentor['nomor_whatsapp'] ?? '',
                'mentees' => $menteesByTeam[$t['id']] ?? [],
                'status' => $t['status'] ?? 'Aktif',
            ];
        }

        return $result;
    }

    /**
     * Proyeksi minimal untuk form publik & endpoint AJAX publik.
     * Hanya memuat data yang memang ditampilkan pada form; data pribadi
     * (WA, alamat, tanggal lahir, NIM mentah, dll.) tidak boleh bocor.
     */
    public static function getTeamsForForm(): array {
        $data = self::loadAll();
        $mentorsById = [];
        foreach ($data['mentors'] ?? [] as $m) {
            if (is_array($m) && isset($m['id'])) {
                $mentorsById[(string)$m['id']] = (string)($m['nama_lengkap'] ?? '');
            }
        }

        $menteesByTeam = [];
        foreach ($data['mentees'] ?? [] as $me) {
            if (!is_array($me)) {
                continue;
            }
            $teamId = (string)($me['tim_id'] ?? '');
            $menteesByTeam[$teamId][] = [
                'id' => (string)($me['id'] ?? ''),
                'nama_lengkap' => (string)($me['nama_lengkap'] ?? ''),
                'nim' => (string)($me['nim'] ?? ''),
                'kelas_tampil' => Academic::formatClass($me['kelas'] ?? '', $me['nim'] ?? ''),
                'status_keaktifan' => (string)($me['status_keaktifan'] ?? 'Aktif'),
            ];
        }

        $result = [];
        foreach ($data['teams'] ?? [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $teamId = (string)($t['id'] ?? '');
            $mentorId = (string)($t['mentor_id'] ?? '');
            $result[] = [
                'id' => $teamId,
                'nama_tim' => (string)($t['nama_tim'] ?? ''),
                'mentor_nama' => $mentorsById[$mentorId] ?? 'Belum ditentukan',
                'mentees' => $menteesByTeam[$teamId] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Proyeksi aman biodata mentor aktif untuk Showcase Form Pendaftaran Publik.
     * Tidak menyertakan data sensitif (nomor WA, NIM mentah, alamat, tanggal lahir).
     */
    public static function getMentorsForShowcase(): array {
        $data = self::loadAll();
        $teamsByMentor = [];
        foreach ($data['teams'] ?? [] as $t) {
            if (!is_array($t) || ($t['status'] ?? 'Aktif') !== 'Aktif') {
                continue;
            }
            $mId = (string)($t['mentor_id'] ?? '');
            if ($mId !== '') {
                $teamsByMentor[$mId][] = (string)($t['nama_tim'] ?? '');
            }
        }

        $result = [];
        foreach ($data['mentors'] ?? [] as $m) {
            if (!is_array($m) || ($m['status'] ?? 'Aktif') !== 'Aktif') {
                continue;
            }
            $id = (string)($m['id'] ?? '');
            $statusKesibukan = (string)($m['status_kesibukan'] ?? 'Kuliah');
            $detail = is_array($m['detail_kesibukan'] ?? null) ? $m['detail_kesibukan'] : [];

            $ringkasanParts = [];
            if ($statusKesibukan === 'Kuliah' || $statusKesibukan === 'Kuliah & Bekerja') {
                $kelas = Academic::formatClass($detail['kelas'] ?? '', $detail['nim'] ?? '');
                if ($kelas !== '') {
                    $ringkasanParts[] = 'Kelas ' . $kelas;
                }
            }
            if ($statusKesibukan === 'Bekerja' || $statusKesibukan === 'Kuliah & Bekerja') {
                $kerja = trim(($detail['profesi_jabatan'] ?? '') . ' — ' . ($detail['instansi_perusahaan'] ?? ''), ' —');
                if ($kerja !== '') {
                    $ringkasanParts[] = $kerja;
                }
            }

            $timList = $teamsByMentor[$id] ?? [];
            $result[] = [
                'id' => $id,
                'nama_lengkap' => (string)($m['nama_lengkap'] ?? ''),
                'gender' => (string)($m['gender'] ?? 'Laki-laki'),
                'status_kesibukan' => $statusKesibukan,
                'ringkasan_kesibukan' => implode(' • ', $ringkasanParts),
                'amanah_organisasi' => (string)($m['amanah_organisasi'] ?? 'Tidak Ada'),
                'jumlah_tim' => count($timList),
                'nama_tim_list' => $timList,
            ];
        }

        return $result;
    }

    /**
     * Perbarui status keaktifan mentee setelah laporan mentoring disimpan.
     * Aturan AGENTS.md:
     * - Absen 3x berturut-turut -> Peringatan
     * - Ditandai tidak jelas kabarnya -> Tidak Aktif
     * - Hadir/Izin mereset hitungan absen beruntun
     */
    public static function updateMenteeAttendance(string $teamId, array $attendanceList): void {
        $result = self::transaction(static function (array $master) use ($teamId, $attendanceList): ?array {
            $changed = false;

            foreach ($master['mentees'] as $index => $mentee) {
                if (($mentee['tim_id'] ?? '') !== $teamId) {
                    continue;
                }

                $menteeId = (string)($mentee['id'] ?? '');
                if ($menteeId === '' || !isset($attendanceList[$menteeId])) {
                    continue;
                }

                $status = (string)($attendanceList[$menteeId]['status'] ?? 'Hadir');
                $tidakJelas = !empty($attendanceList[$menteeId]['tidak_jelas_kabarnya']);
                // Penanda hanya berlaku saat Absen; Hadir/Izin otomatis membatalkannya.
                if ($tidakJelas && $status !== 'Absen') {
                    $tidakJelas = false;
                }

                $consecutive = (int)($mentee['consecutive_absent'] ?? 0);
                $previousStatus = (string)($mentee['status_keaktifan'] ?? 'Aktif');
                $wasTidakJelas = !empty($mentee['tidak_jelas_kabarnya']);

                if ($tidakJelas) {
                    $newStatus = 'Tidak Aktif';
                    $newFlag = true;
                } elseif ($status === 'Absen') {
                    $consecutive++;
                    // Tetap Tidak Aktif bila sebelumnya ditandai tidak jelas kabarnya.
                    $newStatus = $wasTidakJelas
                        ? 'Tidak Aktif'
                        : ($consecutive >= 3 ? 'Peringatan' : $previousStatus);
                    $newFlag = $wasTidakJelas;
                } else {
                    // Hadir/Izin: pulihkan ke Aktif bila sebelumnya bermasalah.
                    $consecutive = 0;
                    $newStatus = ($wasTidakJelas || $previousStatus === 'Peringatan')
                        ? 'Aktif'
                        : $previousStatus;
                    $newFlag = false;
                }

                $master['mentees'][$index]['consecutive_absent'] = $consecutive;
                $master['mentees'][$index]['tidak_jelas_kabarnya'] = $newFlag;
                $master['mentees'][$index]['status_keaktifan'] = $newStatus;
                $changed = true;
            }

            return $changed ? $master : null;
        }, 'attendance');

        if (!$result['ok']) {
            error_log('Gagal memperbarui status keaktifan mentee: ' . $result['error']);
        }
    }

    private static function fetchVersion(array $entry): ?array {
        $path = (string)($entry['s3_path'] ?? '');
        $crypto = $entry['crypto'] ?? null;
        if ($path === '' || !is_array($crypto) || !isset($crypto['key'], $crypto['iv'], $crypto['tag'])) {
            return null;
        }

        $s3 = new S3Client();
        $raw = $s3->getObject($path);
        if ($raw === null) {
            return null;
        }

        $data = Crypto::decryptData(
            $raw,
            (string)$crypto['key'],
            (string)$crypto['iv'],
            (string)$crypto['tag'],
            true
        );

        return is_array($data) ? $data : null;
    }

    private static function historyEntryFromPointer(array $pointer): array {
        return [
            's3_path' => (string)($pointer['s3_path'] ?? ''),
            'crypto' => is_array($pointer['crypto'] ?? null) ? $pointer['crypto'] : [],
            'revision' => isset($pointer['revision']) ? (string)$pointer['revision'] : null,
            'summary' => is_array($pointer['summary'] ?? null) ? $pointer['summary'] : [],
            'updated_at' => (string)($pointer['updated_at'] ?? ''),
            'source' => (string)($pointer['source'] ?? ''),
        ];
    }

    private static function failure(string $reason): array {
        return [
            'ok' => false,
            'reason' => $reason,
            'data' => MasterSchema::emptyDataset(),
            'revision' => null,
            'summary' => [],
            'source' => 'error',
        ];
    }

    private static function summarize(array $data): array {
        return [
            'total_mentors' => is_array($data['mentors'] ?? null) ? count($data['mentors']) : 0,
            'total_teams' => is_array($data['teams'] ?? null) ? count($data['teams']) : 0,
            'total_mentees' => is_array($data['mentees'] ?? null) ? count($data['mentees']) : 0,
            'total_registrants' => is_array($data['registrants'] ?? null) ? count($data['registrants']) : 0,
            'total_archived' => (is_array($data['archived_mentors'] ?? null) ? count($data['archived_mentors']) : 0)
                + (is_array($data['archived_mentees'] ?? null) ? count($data['archived_mentees']) : 0),
        ];
    }

    private static function isEmptyDataset(array $data): bool {
        foreach (['mentors', 'teams', 'mentees', 'archived_mentors', 'archived_mentees', 'registrants'] as $collection) {
            if (!empty($data[$collection])) {
                return false;
            }
        }
        return true;
    }

    private static function isEmptySummary(array $summary): bool {
        foreach (['total_mentors', 'total_teams', 'total_mentees', 'total_registrants'] as $key) {
            if ((int)($summary[$key] ?? 0) > 0) {
                return false;
            }
        }
        return true;
    }
}
