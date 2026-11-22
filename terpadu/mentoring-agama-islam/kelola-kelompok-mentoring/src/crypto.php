<?php
/**
 * src/crypto.php — Pipeline Optimasi, Kompresi, dan Enkripsi/Dekripsi
 * Sesuai spesifikasi AGENTS.md:
 * Minifikasi JSON -> Kompresi (gzencode level 9) -> Enkripsi (AES-256-GCM)
 */

declare(strict_types=1);

namespace Mentoring;

class Crypto {
    private const CIPHER = 'aes-256-gcm';
    private const KEY_LENGTH = 32; // 256 bits
    private const IV_LENGTH = 12;  // 96 bits for GCM
    private const TAG_LENGTH = 16; // 128 bits

    /**
     * Mengompresi dan mengenkripsi data PHP array/objek menjadi ciphertext base64
     * Mengembalikan array dengan payload terenkripsi dan metadata kunci (key, iv, tag)
     */
    public static function encryptData(array $data): array {
        // 1. Minifikasi JSON
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Gagal melakukan serialisasi JSON: " . json_last_error_msg());
        }

        // 2. Kompresi gzencode level 9
        $compressed = gzencode($json, 9);
        if ($compressed === false) {
            throw new \RuntimeException("Gagal mengompresi payload menggunakan gzip");
        }

        // 3. Generate secure random key & IV
        $key = random_bytes(self::KEY_LENGTH);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        // 4. Enkripsi openssl AES-256-GCM
        $ciphertext = openssl_encrypt(
            $compressed,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException("Gagal melakukan enkripsi AES-256-GCM: " . openssl_error_string());
        }

        return [
            'ciphertext_raw' => $ciphertext,
            'ciphertext_b64' => base64_encode($ciphertext),
            'key_b64'        => base64_encode($key),
            'iv_b64'         => base64_encode($iv),
            'tag_b64'        => base64_encode($tag),
            'compressed_len' => strlen($compressed),
            'original_len'   => strlen($json),
        ];
    }

    /**
     * Mendekripsi ciphertext raw atau base64 dengan metadata kunci (key, iv, tag)
     * lalu mengekstrak dekompresi gzip dan mengembalikan array
     */
    public static function decryptData(
        string $ciphertextRawOrB64,
        string $keyB64,
        string $ivB64,
        string $tagB64,
        bool $isRawCiphertext = false
    ): ?array {
        $key = base64_decode($keyB64);
        $iv = base64_decode($ivB64);
        $tag = base64_decode($tagB64);

        $ciphertext = $isRawCiphertext ? $ciphertextRawOrB64 : base64_decode($ciphertextRawOrB64);

        $decryptedGz = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decryptedGz === false) {
            return null; // Enkripsi rusak atau tag tidak cocok
        }

        $json = @gzdecode($decryptedGz);
        if ($json === false) {
            return null;
        }

        return json_decode($json, true);
    }
}
