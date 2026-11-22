<?php
/**
 * src/profile_photo.php — Pengelola Foto Profil Mentor, Mentee, dan Kelompok
 *
 * Foto disimpan pada folder `profil/` di Blomp S3 dan hanya disajikan
 * lewat media.php untuk admin yang sudah login.
 * Setiap foto dipotong persegi (1:1) dan dikompres ke JPEG agar hemat ruang.
 */

declare(strict_types=1);

namespace Mentoring;

class ProfilePhoto {
    /** Entitas yang didukung: mentor, team (kelompok), mentee, pendaftar */
    public const ENTITY_TYPES = ['mentor', 'team', 'mentee', 'pendaftar'];

    public const MAX_BYTES = 8 * 1024 * 1024; // 8 MB
    public const TARGET_SIZE = 512;          // px (persegi)
    public const JPEG_QUALITY = 80;
    public const WEBP_QUALITY = 80;

    /**
     * Simpan foto profil dari file upload ($_FILES).
     * Mengembalikan path relatif S3, mis: "profil/mentor/mtr-001.jpg".
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function storeUpload(string $entityType, string $entityId, array $file): string {
        $prepared = self::prepareFromUpload($entityType, $file);
        return self::storePrepared($entityType, $entityId, $prepared['binary']);
    }

    /**
     * Validasi berkas upload menjadi JPEG persegi + hitung sidik logo.
     *
     * @return array{binary:string, sha256:string, phash:string, width:int, height:int}
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function prepareFromUpload(string $entityType, array $file): array {
        self::validateEntityType($entityType);

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Berkas foto tidak ditemukan.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new \InvalidArgumentException('Ukuran foto melebihi batas server.');
            }
            throw new \InvalidArgumentException('Gagal mengunggah foto (kode ' . $err . ').');
        }

        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Ukuran foto maksimal 8 MB.');
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new \InvalidArgumentException('Berkas yang diunggah bukan foto yang valid.');
        }

        $mime = strtolower($info['mime'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Format foto harus JPG, PNG, atau WEBP.');
        }

        // Logo kelompok tidak boleh terpotong (contain), foto orang dipotong persegi (cover)
        $mode = ($entityType === 'team') ? 'contain' : 'cover';

        $jpegBinary = self::toSquareJpeg($file['tmp_name'], $mime, $mode);

        return [
            'binary' => $jpegBinary,
            'sha256' => hash('sha256', $jpegBinary),
            'phash' => self::averageHash($jpegBinary),
            'width' => self::TARGET_SIZE,
            'height' => self::TARGET_SIZE,
        ];
    }

    /**
     * Unggah JPEG yang sudah disiapkan ke storage, mengembalikan path relatif.
     *
     * @throws \RuntimeException
     */
    public static function storePrepared(string $entityType, string $entityId, string $jpegBinary): string {
        self::validateEntityType($entityType);

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $entityId)) {
            throw new \InvalidArgumentException('ID entitas tidak valid.');
        }

        $folder = Config::get('storage.folders.profil', 'profil/');
        $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
        $mime = $ext === 'webp' ? 'image/webp' : 'image/jpeg';
        $relativePath = $folder . $entityType . '/' . $entityId . '.' . $ext;

        $s3 = new S3Client();
        if (!$s3->putObject($relativePath, $jpegBinary, $mime, false)) {
            throw new \RuntimeException('Gagal menyimpan foto ke storage.');
        }

        return $relativePath;
    }

    /**
     * Kerangka anti-logo-kembar: cari tim lain yang memakai logo identik/mirip.
     *
     * @param array $teams Daftar tim dari master data
     * @return array{team:array, type:string, distance:int}|null
     */
    public static function findDuplicateLogo(array $teams, string $sha256, string $phash, ?string $excludeTeamId = null): ?array {
        $threshold = (int)Config::get('app.logo_phash_threshold', 4);

        foreach ($teams as $team) {
            $teamId = $team['id'] ?? '';
            if ($teamId === '' || $teamId === $excludeTeamId) {
                continue;
            }

            $existingSha = (string)($team['logo_sha256'] ?? '');
            $existingPhash = (string)($team['logo_phash'] ?? '');

            if ($existingSha !== '' && hash_equals($existingSha, $sha256)) {
                return ['team' => $team, 'type' => 'identik', 'distance' => 0];
            }

            if ($existingPhash !== '' && $phash !== '') {
                $distance = self::hammingDistance($existingPhash, $phash);
                if ($distance <= $threshold) {
                    return ['team' => $team, 'type' => 'mirip', 'distance' => $distance];
                }
            }
        }

        return null;
    }

    /**
     * Average hash 8x8 (64-bit) sebagai sidik visual logo.
     */
    private static function averageHash(string $jpegBinary): string {
        $image = @imagecreatefromstring($jpegBinary);
        if (!$image) {
            return '';
        }

        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 8, 8, imagesx($image), imagesy($image));

        $values = [];
        $sum = 0;

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $values[] = $gray;
                $sum += $gray;
            }
        }

        $average = $sum / 64;
        $bits = '';
        foreach ($values as $value) {
            $bits .= ($value >= $average) ? '1' : '0';
        }

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }

        imagedestroy($image);
        imagedestroy($small);

        return $hex;
    }

    /**
     * Jarak Hamming antara dua hash heksadesimal (semakin kecil semakin mirip).
     */
    public static function hammingDistance(string $hashA, string $hashB): int {
        $a = hex2bin(str_pad($hashA, 16, '0', STR_PAD_LEFT));
        $b = hex2bin(str_pad($hashB, 16, '0', STR_PAD_LEFT));
        if ($a === false || $b === false) {
            return 64;
        }

        $distance = 0;
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $distance += substr_count(decbin(ord($a[$i]) ^ ord($b[$i])), '1');
        }

        return $distance;
    }

    /**
     * URL publik (atau proxy lokal saat development) untuk sebuah path foto.
     */
    public static function urlFor(?string $relativePath): ?string {
        if (!$relativePath) {
            return null;
        }

        return \mentoring_base_path(dirname(__DIR__)) . 'media?key=' . rawurlencode($relativePath);
    }

    private static function validateEntityType(string $entityType): void {
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException('Jenis entitas foto tidak dikenal.');
        }
    }

    /**
     * Konversi gambar apa pun (JPG/PNG/WEBP) menjadi JPEG persegi.
     * $mode = 'cover' (potong tengah, untuk selfie) atau 'contain' (logo utuh).
     */
    private static function toSquareJpeg(string $sourcePath, string $mime, string $mode = 'cover'): string {
        switch ($mime) {
            case 'image/jpeg':
                $src = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $src = @imagecreatefrompng($sourcePath);
                break;
            case 'image/webp':
                $src = @imagecreatefromwebp($sourcePath);
                break;
            default:
                $src = false;
        }

        if (!$src) {
            throw new \RuntimeException('Gagal membaca berkas foto.');
        }

        $width = imagesx($src);
        $height = imagesy($src);

        $target = self::TARGET_SIZE;
        $dst = imagecreatetruecolor($target, $target);

        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $target, $target, $white);

        if ($mode === 'contain') {
            // Logo: seluruh gambar masuk tanpa terpotong, sisa ruang diberi latar putih
            $scale = min($target / $width, $target / $height);
            $newWidth = max(1, (int)round($width * $scale));
            $newHeight = max(1, (int)round($height * $scale));
            $dstX = (int)(($target - $newWidth) / 2);
            $dstY = (int)(($target - $newHeight) / 2);

            imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $newWidth, $newHeight, $width, $height);
        } else {
            // Foto selfie: potong persegi di tengah
            $side = min($width, $height);
            $srcX = (int)(($width - $side) / 2);
            $srcY = (int)(($height - $side) / 2);

            imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $target, $target, $side, $side);
        }

        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($dst, null, self::WEBP_QUALITY);
        } else {
            imagejpeg($dst, null, self::JPEG_QUALITY);
        }
        $binary = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if (!is_string($binary) || $binary === '') {
            throw new \RuntimeException('Gagal memproses foto.');
        }

        return $binary;
    }
}
