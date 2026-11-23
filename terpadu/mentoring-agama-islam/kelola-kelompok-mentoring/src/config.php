<?php
/**
 * src/config.php — System Configuration Loader
 * Memuat seluruh konfigurasi (termasuk kredensial S3/Blomp) dari
 * storage_secure/default.json.
 */

declare(strict_types=1);

namespace Mentoring;

class Config {
    private static ?array $config = null;
    private static ?array $s3Config = null;

    public static function getAppDir(): string {
        return dirname(__DIR__);
    }

    public static function getSecureDir(): string {
        return self::getAppDir() . '/storage_secure';
    }

    public static function load(): array {
        if (self::$config !== null) {
            return self::$config;
        }

        $configFile = self::getSecureDir() . '/default.json';
        if (!file_exists($configFile)) {
            throw new \RuntimeException("Config file not found: {$configFile}");
        }

        $raw = file_get_contents($configFile);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON in config file: {$configFile}");
        }

        self::$config = $data;
        return self::$config;
    }

    public static function get(string $key, $default = null) {
        $cfg = self::load();
        $parts = explode('.', $key);
        $curr = $cfg;
        foreach ($parts as $p) {
            if (!isset($curr[$p])) {
                return $default;
            }
            $curr = $curr[$p];
        }
        return $curr;
    }

    /**
     * Konfigurasi S3/Blomp yang sudah digabung ke default.json
     * (storage.s3), disinkronkan dari s3_config.php proyek utama.
     */
    public static function getS3(): array {
        if (self::$s3Config !== null) {
            return self::$s3Config;
        }

        $cfg = self::load();
        $s3 = $cfg['storage']['s3'] ?? [];
        self::$s3Config = is_array($s3) ? $s3 : [];

        return self::$s3Config;
    }

    /**
     * Simpan seluruh konfigurasi ke storage_secure/default.json secara atomik.
     */
    public static function save(array $data): bool {
        $configFile = self::getSecureDir() . '/default.json';

        $ok = JsonFile::withLock($configFile . '.lock', static function () use ($configFile, $data): bool {
            return JsonFile::writeAtomic($configFile, $data);
        });

        if ($ok) {
            self::$config = $data;
        }

        return $ok;
    }

    /**
     * Ubah satu nilai konfigurasi dengan notasi titik (mis. "watobot.api_key").
     */
    public static function set(string $key, $value): bool {
        $config = self::load();
        $parts = explode('.', $key);
        $cursor = &$config;

        foreach ($parts as $index => $part) {
            if ($index === count($parts) - 1) {
                $cursor[$part] = $value;
                break;
            }
            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }

        return self::save($config);
    }

    public static function isProduction(): bool {
        return (self::get('app.mode') === 'production');
    }

    public static function mentorShowcasePerPage(): int {
        return max(1, (int)self::get('app.mentor_showcase_per_page', 3));
    }
}
