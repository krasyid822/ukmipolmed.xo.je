<?php
/**
 * src/bot_queue.php — Antrean & Penjadwalan Ulang Pengiriman Bot WhatsApp
 *
 * Saat API Watobot kedaluwarsa / gagal dihubungi, laporan TIDAK boleh
 * menggagalkan proses submit. Pesan disimpan di antrean lokal terenkripsi
 * (storage_secure/bot_queue.json) lalu dicoba kembali secara bertahap
 * (backoff) setiap kali halaman admin dibuka atau dipicu manual.
 */

declare(strict_types=1);

namespace Mentoring;

class BotQueue {
    private const QUEUE_FILE = 'bot_queue.json';
    private const STATE_FILE = 'bot_state.json';

    /** Jeda percobaan ulang dalam detik berdasarkan jumlah percobaan. */
    private const BACKOFF = [60, 300, 900, 1800, 3600, 7200];

    private static function path(string $file): string {
        return Config::getSecureDir() . '/' . $file;
    }

    private static function readJson(string $file, array $default): array {
        $path = self::path($file);
        if (!is_file($path)) {
            return $default;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    private static function writeJson(string $file, array $data): bool {
        $path = self::path($file);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }

        if (@rename($tmp, $path)) {
            return true;
        }

        // Fallback bila rename lintas device tidak diizinkan hosting.
        $ok = @file_put_contents($path, $json, LOCK_EX) !== false;
        @unlink($tmp);
        return $ok;
    }

    // -----------------------------------------------------------------------
    // Status API bot
    // -----------------------------------------------------------------------

    public static function state(): array {
        $default = [
            'api_invalid' => false,
            'api_invalid_at' => null,
            'last_error' => null,
            'last_http_code' => null,
            'last_attempt_at' => null,
            'last_success_at' => null,
        ];

        return array_merge($default, self::readJson(self::STATE_FILE, []));
    }

    private static function saveState(array $state): bool {
        return self::writeJson(self::STATE_FILE, $state);
    }

    /**
     * Catat bahwa API bot ditolak (kedaluwarsa / tidak valid).
     */
    public static function markApiInvalid(string $reason, int $httpCode = 0): void {
        $state = self::state();
        $state['api_invalid'] = true;
        $state['api_invalid_at'] = $state['api_invalid_at'] ?? date('c');
        $state['last_error'] = $reason;
        $state['last_http_code'] = $httpCode;
        $state['last_attempt_at'] = date('c');
        self::saveState($state);
    }

    /**
     * Catat kegagalan koneksi biasa (bukan karena API kedaluwarsa).
     */
    public static function markAttemptFailed(string $reason, int $httpCode = 0): void {
        $state = self::state();
        $state['last_error'] = $reason;
        $state['last_http_code'] = $httpCode;
        $state['last_attempt_at'] = date('c');
        self::saveState($state);
    }

    /**
     * Kunci baru disimpan tetapi belum terbukti sah maupun salah
     * (mis. timeout/jaringan). Status "kedaluwarsa" lama harus dibersihkan,
     * namun galat koneksi tetap dicatat untuk percobaan ulang.
     */
    public static function markKeyUnverified(string $reason, int $httpCode = 0): void {
        $state = self::state();
        $state['api_invalid'] = false;
        $state['api_invalid_at'] = null;
        $state['last_error'] = $reason;
        $state['last_http_code'] = $httpCode;
        $state['last_attempt_at'] = date('c');
        self::saveState($state);
    }

    /**
     * Tandai API sehat kembali (dipanggil setelah kirim sukses / kunci baru).
     */
    public static function markApiOk(): void {
        $state = self::state();
        $state['api_invalid'] = false;
        $state['api_invalid_at'] = null;
        $state['last_error'] = null;
        $state['last_http_code'] = 200;
        $state['last_success_at'] = date('c');
        self::saveState($state);
    }

    // -----------------------------------------------------------------------
    // Antrean pesan
    // -----------------------------------------------------------------------

    public static function items(): array {
        $data = self::readJson(self::QUEUE_FILE, ['items' => []]);
        $items = $data['items'] ?? [];
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private static function saveItems(array $items): bool {
        return self::writeJson(self::QUEUE_FILE, [
            'updated_at' => date('c'),
            'items' => array_values($items),
        ]);
    }

    public static function pendingCount(): int {
        return count(self::items());
    }

    private static function backoffSeconds(int $attempts): int {
        $idx = max(0, min(count(self::BACKOFF) - 1, $attempts - 1));
        return self::BACKOFF[$idx];
    }

    /**
     * Masukkan pesan ke antrean. Pesan untuk laporan yang sama akan
     * diperbarui (bukan digandakan) agar tidak dobel saat dikirim ulang.
     */
    public static function enqueue(string $reportId, string $message, array $meta = []): string {
        $items = self::items();
        $now = time();

        foreach ($items as $idx => $item) {
            if ((string)($item['report_id'] ?? '') === $reportId) {
                $items[$idx]['message'] = $message;
                $items[$idx]['meta'] = array_merge((array)($item['meta'] ?? []), $meta);
                $items[$idx]['updated_at'] = date('c');
                $items[$idx]['status'] = 'pending';
                $items[$idx]['last_error'] = null;
                $items[$idx]['last_http_code'] = null;
                $items[$idx]['next_attempt_at'] = $now + self::backoffSeconds(max(1, (int)($item['attempts'] ?? 1)));
                self::saveItems($items);
                return (string)($item['id'] ?? '');
            }
        }

        $id = 'bot-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $items[] = [
            'id' => $id,
            'report_id' => $reportId,
            'message' => $message,
            'meta' => $meta,
            'attempts' => 1,
            'status' => 'pending',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'last_attempt_at' => date('c'),
            'next_attempt_at' => $now + self::backoffSeconds(1),
            'last_error' => null,
            'last_http_code' => null,
        ];
        self::saveItems($items);
        return $id;
    }

    public static function remove(string $id): bool {
        $items = self::items();
        $filtered = array_values(array_filter(
            $items,
            static fn(array $item): bool => (string)($item['id'] ?? '') !== $id
        ));

        if (count($filtered) === count($items)) {
            return false;
        }

        return self::saveItems($filtered);
    }

    /**
     * Proses pesan yang sudah jatuh tempo.
     *
     * @param bool $force Abaikan jadwal backoff (dipakai setelah admin
     *                    memasukkan API key baru / tombol "Kirim Sekarang").
     * @return array<int, array<string, mixed>> Hasil per pesan.
     */
    public static function processDue(int $limit = 3, bool $force = false, array $overrides = []): array {
        $items = self::items();
        if ($items === []) {
            return [];
        }

        // Kunci proses agar dua request tidak mengirim pesan yang sama.
        $lock = @fopen(self::path('bot_queue.lock'), 'c');
        if ($lock === false) {
            return [];
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            return [];
        }

        $now = time();
        $results = [];
        $processed = 0;
        $changed = false;

        foreach ($items as $idx => $item) {
            if ($processed >= $limit) {
                break;
            }

            $nextAttempt = (int)($item['next_attempt_at'] ?? 0);
            if (!$force && $nextAttempt > $now) {
                continue;
            }

            $reportId = (string)($item['report_id'] ?? '');

            // Rekonsiliasi data lama: bila percobaan sebelumnya sudah ditolak
            // sebagai duplicate_message, pesan sebenarnya SUDAH sampai di grup.
            // Jangan kirim ulang; selesaikan item ini.
            $lastError = strtolower((string)($item['last_error'] ?? ''));
            $updatedAt = strtotime((string)($item['updated_at'] ?? '')) ?: 0;
            $lastAttemptAt = strtotime((string)($item['last_attempt_at'] ?? '')) ?: 0;
            if (str_contains($lastError, 'duplicate_message') && $updatedAt <= $lastAttemptAt) {
                unset($items[$idx]);
                $changed = true;
                $processed++;
                self::markApiOk();
                $results[] = [
                    'report_id' => $reportId,
                    'success' => true,
                    'message' => 'Sudah terkirim sebelumnya (duplikat diabaikan)',
                ];
                continue;
            }

            $result = Watobot::sendReport((string)($item['message'] ?? ''), $overrides);
            $processed++;
            $changed = true;

            if (!empty($result['success'])) {
                unset($items[$idx]);
                self::markApiOk();
                $results[] = [
                    'report_id' => $reportId,
                    'success' => true,
                    'message' => 'Terkirim',
                ];
                continue;
            }

            $attempts = (int)($item['attempts'] ?? 1) + 1;
            $reason = (string)($result['failure_reason'] ?? $result['error'] ?? 'Gagal mengirim ke API bot');
            $httpCode = (int)($result['http_code'] ?? 0);

            $items[$idx]['attempts'] = $attempts;
            $items[$idx]['last_attempt_at'] = date('c');
            $items[$idx]['next_attempt_at'] = time() + self::backoffSeconds($attempts);
            $items[$idx]['last_error'] = $reason;
            $items[$idx]['last_http_code'] = $httpCode;

            if (!empty($result['api_invalid'])) {
                self::markApiInvalid($reason, $httpCode);
            } else {
                self::markAttemptFailed($reason, $httpCode);
            }

            $results[] = [
                'report_id' => $reportId,
                'success' => false,
                'api_invalid' => !empty($result['api_invalid']),
                'http_code' => $httpCode,
                'message' => $reason,
                'next_attempt_at' => $items[$idx]['next_attempt_at'],
            ];

            // Jangan menghabiskan waktu mencoba sisa antrean bila kuncinya
            // jelas ditolak atau koneksi tidak tersedia sama sekali.
            if (!empty($result['api_invalid']) || $httpCode === 0) {
                break;
            }
        }

        if ($changed) {
            self::saveItems($items);
        }

        @flock($lock, LOCK_UN);
        @fclose($lock);
        return $results;
    }
}
