<?php
/**
 * src/master_schema.php — Skema & Validasi Data Master
 * Satu-satunya sumber kebenaran bentuk data master (mentors, teams, mentees,
 * beserta arsipnya) sebelum disimpan ke Blomp S3.
 */

declare(strict_types=1);

namespace Mentoring;

final class MasterSchema {
    public const VERSION = 1;

    public const COLLECTIONS = [
        'mentors',
        'teams',
        'mentees',
        'archived_mentors',
        'archived_mentees',
        'registrants',
    ];

    public const REGISTRANT_STATUS = ['Menunggu', 'Disetujui', 'Cadangan', 'Ditolak'];
    public const MENTORING_EXPERIENCE = ['Belum pernah mentoring', 'Sudah pernah mentoring', 'Alumni Pesantren'];

    private const GENDERS = ['Laki-laki', 'Perempuan'];
    private const MENTOR_STATUS = ['Aktif', 'Non-aktif'];
    private const TEAM_STATUS = ['Aktif', 'Non-aktif'];
    private const MENTEE_STATUS = ['Aktif', 'Peringatan', 'Tidak Aktif'];
    private const BUSY_STATUS = ['Kuliah', 'Bekerja', 'Kuliah & Bekerja'];
    private const SCHEDULES = ['Pagi', 'Sore', 'Tidak Menentu'];

    public static function emptyDataset(): array {
        return [
            'schema_version' => self::VERSION,
            'mentors' => [],
            'teams' => [],
            'mentees' => [],
            'archived_mentors' => [],
            'archived_mentees' => [],
            'registrants' => [],
        ];
    }

    /**
     * Lengkapi struktur minimal, bersihkan UTF-8, dan normalisasi nomor WA.
     * Tidak pernah membuang data tak dikenal; validasi yang memutuskan.
     */
    public static function normalize(array $data): array {
        foreach (self::COLLECTIONS as $collection) {
            if (!array_key_exists($collection, $data)) {
                $data[$collection] = [];
            }
        }

        if (!isset($data['schema_version'])) {
            $data['schema_version'] = self::VERSION;
        }

        foreach (self::COLLECTIONS as $collection) {
            if (!is_array($data[$collection])) {
                continue;
            }
            foreach ($data[$collection] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $data[$collection][$index] = self::scrub($row);
                if (isset($row['nomor_whatsapp'])) {
                    $data[$collection][$index]['nomor_whatsapp'] = self::normalizePhone((string)$row['nomor_whatsapp']);
                }
                if (in_array($collection, ['registrants', 'mentees', 'archived_mentees'], true) && isset($row['nama_lengkap'])) {
                    $data[$collection][$index]['nama_lengkap'] = self::normalizeName((string)$row['nama_lengkap']);
                }
                if (in_array($collection, ['registrants', 'mentees', 'archived_mentees', 'mentors', 'archived_mentors'], true)) {
                    foreach (['tempat_lahir', 'alamat_sekarang', 'alamat_orang_tua'] as $textField) {
                        if (isset($row[$textField]) && is_string($row[$textField])) {
                            $data[$collection][$index][$textField] = self::normalizeName($row[$textField]);
                        }
                    }
                }
            }
        }

        // Rekonsiliasi status Disetujui pada pendaftar: hanya sah jika benar-benar terdaftar sebagai mentee & punya tim.
        $validTeamIds = [];
        foreach ($data['teams'] ?? [] as $team) {
            if (is_array($team) && !empty($team['id'])) {
                $validTeamIds[(string)$team['id']] = true;
            }
        }
        $menteeTeamMap = [];
        foreach (['mentees', 'archived_mentees'] as $mCol) {
            foreach ($data[$mCol] ?? [] as $mentee) {
                if (is_array($mentee) && !empty($mentee['id'])) {
                    $menteeTeamMap[(string)$mentee['id']] = (string)($mentee['tim_id'] ?? '');
                }
            }
        }
        foreach ($data['registrants'] ?? [] as $index => $reg) {
            if (!is_array($reg)) {
                continue;
            }
            $assignedMenteeId = trim((string)($reg['assigned_mentee_id'] ?? ''));
            $menteeTimId = $assignedMenteeId !== '' ? ($menteeTeamMap[$assignedMenteeId] ?? '') : '';
            if ($assignedMenteeId !== '' && $menteeTimId !== '' && isset($validTeamIds[$menteeTimId])) {
                $data['registrants'][$index]['status_pendaftaran'] = 'Disetujui';
                $data['registrants'][$index]['assigned_tim_id'] = $menteeTimId;
            } else {
                $data['registrants'][$index]['assigned_mentee_id'] = '';
                $data['registrants'][$index]['assigned_tim_id'] = '';
                if (($reg['status_pendaftaran'] ?? '') === 'Disetujui') {
                    $data['registrants'][$index]['status_pendaftaran'] = 'Menunggu';
                }
            }
        }

        return $data;
    }

    /**
     * @return list<string> Daftar kesalahan; array kosong berarti valid.
     */
    public static function validate(array $data): array {
        $errors = [];

        foreach (self::COLLECTIONS as $collection) {
            if (!isset($data[$collection])) {
                $errors[] = "Koleksi {$collection} tidak ada.";
            } elseif (!is_array($data[$collection])) {
                $errors[] = "Koleksi {$collection} harus berupa daftar.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach (self::COLLECTIONS as $collection) {
            $seen = [];
            foreach ($data[$collection] as $i => $row) {
                $label = $collection . '[' . $i . ']';
                if (!is_array($row)) {
                    $errors[] = "{$label} bukan objek.";
                    continue;
                }

                $id = $row['id'] ?? null;
                if (!is_string($id) || trim($id) === '') {
                    $errors[] = "{$label} tidak memiliki id.";
                } elseif (isset($seen[$id])) {
                    $errors[] = "ID duplikat '{$id}' pada {$collection}.";
                } else {
                    $seen[$id] = true;
                }

                if ($collection === 'mentors' || $collection === 'archived_mentors') {
                    self::checkEnum($errors, $label, $row, 'gender', self::GENDERS);
                    self::checkEnum($errors, $label, $row, 'status', self::MENTOR_STATUS);
                    self::checkEnum($errors, $label, $row, 'status_kesibukan', self::BUSY_STATUS);
                    $detail = $row['detail_kesibukan'] ?? null;
                    if (is_array($detail)) {
                        self::checkEnum($errors, $label . '.detail_kesibukan', $detail, 'jadwal_kuliah', self::SCHEDULES);
                    }
                    self::checkDate($errors, $label, $row, 'tanggal_lahir');
                    self::checkPhone($errors, $label, $row);
                }

                if ($collection === 'teams') {
                    self::checkEnum($errors, $label, $row, 'status', self::TEAM_STATUS);
                    $nama = $row['nama_tim'] ?? '';
                    if (!is_string($nama) || trim($nama) === '') {
                        $errors[] = "{$label} tidak memiliki nama_tim.";
                    }
                }

                if ($collection === 'mentees' || $collection === 'archived_mentees') {
                    self::checkEnum($errors, $label, $row, 'gender', self::GENDERS);
                    self::checkEnum($errors, $label, $row, 'status_keaktifan', self::MENTEE_STATUS);
                    self::checkEnum($errors, $label, $row, 'jadwal_kuliah', self::SCHEDULES);
                    self::checkDate($errors, $label, $row, 'tanggal_lahir');
                    self::checkPhone($errors, $label, $row);
                }

                if ($collection === 'registrants') {
                    self::checkEnum($errors, $label, $row, 'gender', self::GENDERS);
                    self::checkEnum($errors, $label, $row, 'jadwal_kuliah', self::SCHEDULES);
                    self::checkEnum($errors, $label, $row, 'status_pendaftaran', self::REGISTRANT_STATUS);
                    self::checkEnum($errors, $label, $row, 'pengalaman_mentoring', self::MENTORING_EXPERIENCE);
                    self::checkDate($errors, $label, $row, 'tanggal_lahir');
                    self::checkPhone($errors, $label, $row);
                    if (($row['status_pendaftaran'] ?? '') === 'Disetujui') {
                        $assignedMenteeId = trim((string)($row['assigned_mentee_id'] ?? ''));
                        $assignedTimId = trim((string)($row['assigned_tim_id'] ?? ''));
                        if ($assignedMenteeId === '' || $assignedTimId === '') {
                            $errors[] = "{$label} berstatus Disetujui tetapi belum terhubung ke mentee dan tim.";
                        }
                    }
                }
            }
        }

        // Integritas relasi hanya untuk data aktif (arsip boleh kehilangan relasi).
        $mentorIds = self::ids($data['mentors']);
        $teamById = [];
        foreach ($data['teams'] as $team) {
            if (is_array($team) && isset($team['id'])) {
                $teamById[(string)$team['id']] = $team;
            }
        }

        foreach ($data['teams'] as $i => $team) {
            if (!is_array($team)) {
                continue;
            }
            $mentorId = (string)($team['mentor_id'] ?? '');
            if ($mentorId === '') {
                $errors[] = "teams[{$i}] belum memiliki mentor_id.";
            } elseif (!isset($mentorIds[$mentorId])) {
                $errors[] = "teams[{$i}] merujuk mentor '{$mentorId}' yang tidak ada.";
            }
        }

        foreach ($data['mentees'] as $i => $mentee) {
            if (!is_array($mentee)) {
                continue;
            }
            $timId = (string)($mentee['tim_id'] ?? '');
            $mentorId = (string)($mentee['mentor_id'] ?? '');

            if ($timId === '' || !isset($teamById[$timId])) {
                $errors[] = "mentees[{$i}] merujuk tim '{$timId}' yang tidak ada.";
                continue;
            }
            if ($mentorId !== '' && !isset($mentorIds[$mentorId])) {
                $errors[] = "mentees[{$i}] merujuk mentor '{$mentorId}' yang tidak ada.";
            }
            $teamMentor = (string)($teamById[$timId]['mentor_id'] ?? '');
            if ($mentorId !== '' && $teamMentor !== '' && $mentorId !== $teamMentor) {
                $errors[] = "mentees[{$i}] mentor_id tidak sama dengan mentor tim '{$timId}'.";
            }
        }

        return $errors;
    }

    /**
     * Validasi input form Tambah Tim Mentoring.
     */
    public static function validateTeamInput(string $namaTim, string $mentorId): ?string {
        $namaTim = trim($namaTim);
        $mentorId = trim($mentorId);

        if ($namaTim === '' || $mentorId === '') {
            return 'Nama tim dan mentor pendamping wajib diisi.';
        }

        $len = mb_strlen($namaTim, 'UTF-8');
        if ($len < 2 || $len > 80 || !preg_match('/\p{L}/u', $namaTim)) {
            return 'Nama tim harus 2–80 karakter dan mengandung huruf.';
        }

        return null;
    }

    /**
     * Validasi input form Tambah Mentor / Mentee.
     */
    public static function validatePersonInput(array $input, bool $isMentor): ?string {
        $nama = trim((string)($input['nama_lengkap'] ?? ''));
        $namaLen = mb_strlen($nama, 'UTF-8');
        if ($nama === '' || $namaLen < 2 || $namaLen > 120 || !preg_match('/\p{L}.*\p{L}/u', $nama)) {
            return 'Nama lengkap wajib diisi (2–120 karakter dan minimal mengandung 2 huruf).';
        }

        $waRaw = trim((string)($input['nomor_whatsapp'] ?? ''));
        $waNorm = self::normalizePhone($waRaw);
        if ($waRaw === '' || !preg_match('/^62\d{8,15}$/', $waNorm)) {
            return 'Nomor WhatsApp tidak valid. Gunakan nomor Indonesia yang benar, mis. 6281234567890 atau 081234567890.';
        }

        $gender = trim((string)($input['gender'] ?? ''));
        if (!in_array($gender, self::GENDERS, true)) {
            return 'Gender tidak valid.';
        }

        $tempatLahir = trim((string)($input['tempat_lahir'] ?? ''));
        if ($tempatLahir !== '') {
            $tlLen = mb_strlen($tempatLahir, 'UTF-8');
            if ($tlLen < 2 || $tlLen > 80 || !preg_match('/\p{L}/u', $tempatLahir)) {
                return 'Tempat lahir harus 2–80 karakter dan mengandung huruf.';
            }
        }

        $tanggalLahir = trim((string)($input['tanggal_lahir'] ?? ''));
        if ($tanggalLahir !== '') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggalLahir, $m)
                || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return 'Tanggal lahir tidak valid (gunakan format YYYY-MM-DD).';
            }
            $year = (int)$m[1];
            $maxYear = (int)date('Y') - 10;
            if ($year < 1940 || $year > $maxYear) {
                return "Tahun lahir harus antara 1940 sampai {$maxYear}.";
            }
        }

        $nim = trim((string)($input['nim'] ?? ''));
        if ($nim !== '' && !preg_match('/^\d{6,15}$/', $nim)) {
            return 'NIM harus berupa angka 6–15 digit tanpa huruf atau spasi.';
        }

        $kelasRaw = trim((string)($input['kelas'] ?? ''));
        if ($kelasRaw !== '') {
            $kelasNorm = Academic::normalizeClass($kelasRaw);
            if (!preg_match('/^[A-Z]{2,10}(-[A-Z][A-Z0-9]*)?$/', $kelasNorm)) {
                return 'Format kelas tidak valid. Gunakan format prodi + kelas, mis. TRPL-A.';
            }
        }

        $jadwalKuliah = trim((string)($input['jadwal_kuliah'] ?? ''));
        if (!in_array($jadwalKuliah, self::SCHEDULES, true)) {
            return 'Jadwal kuliah tidak valid.';
        }

        if ($isMentor) {
            $statusKesibukan = trim((string)($input['status_kesibukan'] ?? ''));
            if (!in_array($statusKesibukan, self::BUSY_STATUS, true)) {
                return 'Status kesibukan tidak valid.';
            }
        }

        foreach (['alamat_sekarang' => 'Alamat sekarang', 'alamat_orang_tua' => 'Alamat orang tua'] as $field => $label) {
            $val = trim((string)($input[$field] ?? ''));
            if ($val !== '') {
                $len = mb_strlen($val, 'UTF-8');
                if ($len < 4 || $len > 250 || !preg_match('/\p{L}/u', $val)) {
                    return "{$label} harus 4–250 karakter dan mengandung huruf.";
                }
            }
        }

        return null;
    }

    /**
     * Sidik jari isi data master (dipakai sebagai revision optimistik).
     */
    public static function fingerprint(array $data): string {
        try {
            $json = self::encodeCanonical($data);
        } catch (\Throwable $e) {
            return 'sha256:' . hash('sha256', serialize($data));
        }

        return 'sha256:' . hash('sha256', $json);
    }

    public static function normalizeName(string $name): string {
        $clean = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
        if ($clean === '') {
            return '';
        }

        if (function_exists('mb_convert_case')) {
            return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords(strtolower($clean));
    }

    public static function normalizePhone(string $number): string {
        if (trim($number) === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($digits === '') {
            return '';
        }

        // Awalan internasional "00" setara "+"
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '62')) {
            // Buang nol trunk yang terbawa setelah kode negara, mis. "+62 (0)812…"
            $rest = ltrim(substr($digits, 2), '0');
            return $rest === '' ? '62' : '62' . $rest;
        }

        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }
        if (str_starts_with($digits, '8')) {
            return '62' . $digits;
        }

        return $digits;
    }

    private static function encodeCanonical(array $data): string {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('JSON tidak dapat diserialisasi.');
        }
        return $json;
    }

    /** @return array<string,true> */
    private static function ids(array $rows): array {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[(string)$row['id']] = true;
            }
        }
        return $ids;
    }

    private static function checkEnum(array &$errors, string $label, array $row, string $field, array $allowed): void {
        if (!array_key_exists($field, $row)) {
            return;
        }
        $value = $row[$field];
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $errors[] = "{$label}.{$field} bernilai tidak valid.";
        }
    }

    private static function checkDate(array &$errors, string $label, array $row, string $field): void {
        if (!isset($row[$field]) || $row[$field] === '') {
            return;
        }
        $value = (string)$row[$field];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[] = "{$label}.{$field} harus berformat YYYY-MM-DD.";
        }
    }

    private static function checkPhone(array &$errors, string $label, array $row): void {
        if (!isset($row['nomor_whatsapp']) || $row['nomor_whatsapp'] === '') {
            return;
        }
        if (!preg_match('/^62\d{8,15}$/', (string)$row['nomor_whatsapp'])) {
            $errors[] = "{$label}.nomor_whatsapp harus diawali 62 dan berisi 8-15 digit.";
        }
    }

    private static function scrub(mixed $value): mixed {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::scrub($item);
            }
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $converted = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($value, 'UTF-8', 'UTF-8')
                : (function_exists('iconv') ? iconv('UTF-8', 'UTF-8//IGNORE', $value) : $value);
            return is_string($converted) ? $converted : $value;
        }

        return $value;
    }
}
