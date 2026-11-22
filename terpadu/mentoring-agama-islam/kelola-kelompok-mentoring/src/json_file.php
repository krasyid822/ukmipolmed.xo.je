<?php
/**
 * src/json_file.php — Penulisan JSON Atomik & Penguncian Berkas
 * Semua berkas state lokal (pointer registry, antrean, konfigurasi) wajib ditulis
 * lewat helper ini agar tidak pernah meninggalkan berkas setengah jadi.
 */

declare(strict_types=1);

namespace Mentoring;

final class JsonFile {
    /** @var array<string, array{handle: resource, depth: int}> */
    private static array $locks = [];

    public static function encode(array $data, bool $pretty = true): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new \RuntimeException('Gagal serialisasi JSON: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Baca JSON. Mengembalikan null bila berkas tidak ada ATAU isinya rusak,
     * sehingga pemanggil bisa membedakan "belum ada" dari "korup".
     */
    public static function read(string $path): ?array {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Tulis JSON secara atomik: tulis ke berkas sementara, lalu rename.
     * Fallback ke penulisan langsung ber-LOCK_EX bila rename ditolak hosting.
     */
    public static function writeAtomic(string $path, array|string $data, bool $pretty = true): bool {
        try {
            $json = is_array($data) ? self::encode($data, $pretty) : $data;
        } catch (\Throwable $e) {
            error_log('JsonFile::writeAtomic gagal encode: ' . $e->getMessage());
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, $json);
        @fflush($handle);
        @fclose($handle);

        if ($written !== strlen($json)) {
            @unlink($tmp);
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

    /**
     * Jalankan callback di bawah kunci eksklusif. Bersifat reentrant dalam
     * satu proses, sehingga transaksi bersarang tidak saling mengunci.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withLock(string $lockPath, callable $fn) {
        if (isset(self::$locks[$lockPath])) {
            self::$locks[$lockPath]['depth']++;
            try {
                return $fn();
            } finally {
                self::$locks[$lockPath]['depth']--;
            }
        }

        $dir = dirname($lockPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Direktori lock tidak tersedia: ' . basename($lockPath));
        }

        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Tidak dapat membuka berkas lock: ' . basename($lockPath));
        }

        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            throw new \RuntimeException('Tidak dapat mengunci berkas: ' . basename($lockPath));
        }

        self::$locks[$lockPath] = ['handle' => $handle, 'depth' => 1];
        try {
            return $fn();
        } finally {
            unset(self::$locks[$lockPath]);
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
