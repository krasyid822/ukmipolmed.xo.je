<?php
/**
 * src/watobot.php — Integrasi WhatsApp Bot Reporting Watobot.xyz
 * Menangani format pesan persis sesuai template AGENTS.md Bagian 5B
 */

declare(strict_types=1);

namespace Mentoring;

class Watobot {
    /**
     * Konversi tanggal YYYY-MM-DD menjadi format teks Indonesia: "Kamis, 19 November 2026"
     */
    public static function formatTanggalIndonesia(string $dateStr): string {
        $timestamp = strtotime($dateStr);
        if (!$timestamp) {
            $timestamp = time();
        }

        $hari = [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
        ];

        $bulan = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $dayName = $hari[date('l', $timestamp)] ?? date('l', $timestamp);
        $dayNum = date('j', $timestamp);
        $monthName = $bulan[(int)date('n', $timestamp)] ?? date('F', $timestamp);
        $yearNum = date('Y', $timestamp);

        return "{$dayName}, {$dayNum} {$monthName} {$yearNum}";
    }

    /**
     * Menyusun format teks pesan bot persis sesuai spesifikasi Bagian 5B
     */
    public static function buildMessageText(array $report): string {
        $reportId = rawurlencode((string)($report['id'] ?? ''));
        $redirectorBase = trim((string)Config::get('app.worker_redirector_url', ''));

        // Bila Cloudflare Worker belum dipasang, gunakan URL laporan langsung
        // agar pesan tidak berisi link placeholder yang mati.
        if ($redirectorBase !== '') {
            $reportUrl = rtrim($redirectorBase, '/') . '/?id=' . $reportId;
        } else {
            $baseUrl = rtrim((string)Config::get('app.base_url', ''), '/');
            $reportUrl = $baseUrl . '/view?id=' . $reportId;
        }

        $tglIndo = self::formatTanggalIndonesia($report['tanggal'] ?? date('Y-m-d'));
        $jamMulai = str_replace(':', '.', $report['jam_mulai'] ?? '16.00');
        $jamSelesai = str_replace(':', '.', $report['jam_selesai'] ?? '17.30');

        $waktuFormatted = "{$tglIndo} ({$jamMulai} sd {$jamSelesai})";

        // Susun daftar kehadiran dengan indentasi tepat 5 spasi
        $formatList = function(array $names, array $notes = []): string {
            if (empty($names)) {
                return "     -";
            }
            $lines = [];
            foreach ($names as $idx => $name) {
                $no = $idx + 1;
                $note = trim((string)($notes[$name] ?? ''));
                $suffix = $note !== '' ? " ({$note})" : '';
                $lines[] = "     {$no}. {$name}{$suffix}";
            }
            return implode("\n", $lines);
        };

        $izinKeterangan = is_array($report['izin_keterangan'] ?? null) ? $report['izin_keterangan'] : [];

        $hadirFormatted = $formatList($report['hadir'] ?? []);
        $izinFormatted = $formatList($report['izin'] ?? [], $izinKeterangan);
        $absenFormatted = $formatList($report['absen'] ?? []);

        $moderatorText = !empty($report['moderator']) ? $report['moderator'] : '-';
        $tilawahText = !empty($report['tilawah']) ? $report['tilawah'] : '-';
        
        $tadabburText = '-';
        if (!empty($report['tadabbur_petugas'])) {
            $tadabburDetail = trim(($report['tadabbur_surah_ayat'] ?? '') . ' - ' . ($report['tadabbur_inti'] ?? ''), ' -');
            $tadabburText = !empty($tadabburDetail) ? "{$report['tadabbur_petugas']} [ {$tadabburDetail} ]" : $report['tadabbur_petugas'];
        }

        $kultumText = '-';
        if (!empty($report['kultum_petugas'])) {
            $kultumDetail = trim($report['kultum_judul_inti'] ?? '');
            $kultumText = !empty($kultumDetail) ? "{$report['kultum_petugas']} [ {$kultumDetail} ]" : $report['kultum_petugas'];
        }

        $doaText = !empty($report['doa_petugas']) ? $report['doa_petugas'] : '-';

        $msg = "{$reportUrl}\n";
        $msg .= "Laporan Mentoring\n\n";
        $msg .= "Nama Tim: {$report['nama_tim']}\n\n";
        $msg .= "Waktu: {$waktuFormatted}\n";
        $msg .= "Tempat: {$report['tempat']}\n\n";
        $msg .= "Tema: {$report['tema']}\n\n";
        $msg .= "Mentor: {$report['mentor']}\n";
        $msg .= "Moderator: {$moderatorText}\n";
        $msg .= "Tilawah: {$tilawahText}\n";
        $msg .= "Tadabbur: {$tadabburText}\n";
        $msg .= "Kultum: {$kultumText}\n";
        $msg .= "Do'a: {$doaText}\n\n";
        $msg .= "Hadir:\n{$hadirFormatted}\n\n";
        $msg .= "Izin:\n{$izinFormatted}\n\n";
        $msg .= "Absen:\n{$absenFormatted}\n\n";
        $msg .= "~Pesan otomatis oleh bot.";

        return $msg;
    }

    /**
     * Deteksi apakah respons API menandakan kunci API kedaluwarsa/tidak sah.
     * Watobot dapat membalas HTTP 200 dengan status=false untuk kunci expired,
     * sehingga pemeriksaan tidak boleh hanya bergantung pada kode HTTP.
     */
    private static function detectApiInvalid(int $httpCode, $response): bool {
        if (in_array($httpCode, [401, 403], true)) {
            return true;
        }

        $body = is_string($response) ? $response : '';
        if ($body === '') {
            return false;
        }

        $decoded = json_decode($body, true);
        $message = '';
        if (is_array($decoded)) {
            $message = (string)($decoded['message'] ?? $decoded['error'] ?? $decoded['msg'] ?? '');
        }
        if ($message === '') {
            $message = $body;
        }

        $lower = strtolower($message);
        $keywords = ['expired', 'kedaluwarsa', 'kadaluarsa', 'invalid api', 'api key', 'apikey', 'unauthorized', 'invalid credential', 'kredensial'];
        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apakah payload respons API menyatakan kegagalan meski HTTP 2xx.
     */
    private static function responseHasFailure($response): bool {
        if (!is_string($response) || $response === '') {
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return false;
        }

        return ($decoded['status'] ?? null) === false
            || ($decoded['success'] ?? null) === false
            || (string)($decoded['status'] ?? '') === 'error';
    }

    /**
     * Deteksi respons "duplicate_message": API menolak mengirim ulang karena
     * pesan yang identik sudah pernah masuk ke grup. Ini berarti pesan SUDAH
     * terkirim, sehingga harus diperlakukan sebagai sukses (idempoten).
     */
    private static function isDuplicateResponse($response): bool {
        if (!is_string($response) || $response === '') {
            return false;
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $reason = strtolower((string)($decoded['reason'] ?? $decoded['message'] ?? $decoded['error'] ?? ''));
            if (str_contains($reason, 'duplicate')) {
                return true;
            }
            if (!empty($decoded['skipped']) && str_contains($reason, 'duplikat')) {
                return true;
            }
        }

        return str_contains(strtolower($response), 'duplicate_message');
    }

    public static function normalizeChannel(?string $channel): string {
        $val = strtolower(trim((string)$channel));
        if (in_array($val, ['akhwat', 'perempuan', 'p'], true)) {
            return 'akhwat';
        }
        return 'ikhwan';
    }

    public static function channelLabel(string $channel): string {
        return self::normalizeChannel($channel) === 'akhwat' ? 'Akhwat' : 'Ikhwan';
    }

    public static function resolveChannelForTeam(string $timId, string $namaTim = ''): string {
        $map = ReportRepository::teamGenderMap();
        $gender = '';
        if ($timId !== '' && isset($map['tim:' . $timId])) {
            $gender = $map['tim:' . $timId];
        } elseif ($namaTim !== '' && isset($map['nama:' . $namaTim])) {
            $gender = $map['nama:' . $namaTim];
        }
        return self::normalizeChannel($gender);
    }

    public static function channelConfig(string $channel): array {
        $ch = self::normalizeChannel($channel);
        $label = self::channelLabel($ch);
        $chCfg = Config::get('watobot.channels.' . $ch, []);
        if (!is_array($chCfg)) {
            $chCfg = [];
        }

        $defaultEnabled = false;
        $enabled = array_key_exists('enabled', $chCfg) ? (bool)$chCfg['enabled'] : $defaultEnabled;

        $apiUrl = trim((string)($chCfg['api_url'] ?? Config::get('watobot.api_url', 'https://api.watobot.xyz/api/message')));
        if ($apiUrl === '') {
            $apiUrl = 'https://api.watobot.xyz/api/message';
        }

        $apiKey = trim((string)($chCfg['api_key'] ?? ($ch === 'ikhwan' ? Config::get('watobot.api_key', '') : '')));
        $groupId = trim((string)($chCfg['group_id'] ?? ($ch === 'ikhwan' ? Config::get('watobot.group_id', '120363271558091003@g.us') : '')));
        $timeout = (int)($chCfg['timeout'] ?? Config::get('watobot.timeout', 30));

        return [
            'channel' => $ch,
            'label' => $label,
            'enabled' => $enabled,
            'api_url' => $apiUrl,
            'api_key' => $apiKey,
            'group_id' => $groupId,
            'timeout' => $timeout > 0 ? $timeout : 30,
        ];
    }

    public static function isEnabled(string $channel): bool {
        return (bool)self::channelConfig($channel)['enabled'];
    }

    /**
     * Mengirim pesan ke grup via Watobot API.
     *
     * Hasil selalu menyertakan `api_invalid` agar pemanggil dapat
     * menjadwalkan ulang pengiriman tanpa menghentikan proses utama.
     */
    public static function sendReport(string $messageText, array $overrides = [], ?string $channel = null): array {
        $cfg = self::channelConfig($channel ?? 'ikhwan');

        if (!$cfg['enabled'] && empty($overrides['ignore_disabled'])) {
            return [
                'success' => false,
                'disabled' => true,
                'http_code' => 0,
                'response' => null,
                'error' => '',
                'api_invalid' => false,
                'duplicate' => false,
                'failure_reason' => 'Fitur bot ' . $cfg['label'] . ' sedang dimatikan.',
            ];
        }

        $apiUrl = (string)($overrides['api_url'] ?? $cfg['api_url']);
        $apiKey = trim((string)($overrides['api_key'] ?? $cfg['api_key']));
        $groupId = trim((string)($overrides['group_id'] ?? $cfg['group_id']));

        if ($groupId === '') {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => 'ID grup WhatsApp bot ' . $cfg['label'] . ' belum diatur',
                'api_invalid' => false,
                'duplicate' => false,
                'failure_reason' => 'ID grup WhatsApp bot ' . $cfg['label'] . ' belum diatur',
            ];
        }

        if ($apiKey === '') {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => 'API key bot ' . $cfg['label'] . ' belum diatur',
                'api_invalid' => true,
                'duplicate' => false,
                'failure_reason' => 'API key bot ' . $cfg['label'] . ' belum diatur',
            ];
        }

        $payload = [
            'to' => $groupId,
            'message' => $messageText,
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            // Pengiriman nyata ke grup bisa memakan >15 detik (cold start /
            // antrean di sisi Watobot), jadi beri ruang lebih longgar.
            CURLOPT_TIMEOUT => (int)($overrides['timeout'] ?? $cfg['timeout']),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $res = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $apiInvalid = self::detectApiInvalid($httpCode, $res);
        $httpOk = ($httpCode >= 200 && $httpCode < 300);
        $duplicate = self::isDuplicateResponse($res);
        $success = $httpOk && !$apiInvalid
            && ($duplicate || (!self::responseHasFailure($res) && $res !== false));

        $failureReason = '';
        if (!$success) {
            if ($err !== '') {
                $failureReason = 'Koneksi ke API bot gagal: ' . $err;
            } elseif (is_string($res) && $res !== '') {
                $decoded = json_decode($res, true);
                $failureReason = is_array($decoded)
                    ? (string)($decoded['message'] ?? $decoded['error'] ?? $res)
                    : $res;
            } else {
                $failureReason = 'API bot tidak merespons (HTTP ' . $httpCode . ')';
            }
            $failureReason = mb_substr(trim($failureReason), 0, 400);
        }

        return [
            'success' => $success,
            'http_code' => $httpCode,
            'response' => $res,
            'error' => $err,
            'api_invalid' => $apiInvalid,
            'duplicate' => $duplicate,
            'failure_reason' => $failureReason,
        ];
    }
}
