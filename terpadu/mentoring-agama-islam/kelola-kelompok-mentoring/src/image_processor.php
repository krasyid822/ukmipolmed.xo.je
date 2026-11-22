<?php
/**
 * src/image_processor.php — Server-side image optimizer & thumbnail generator
 * Resize max width 1280px, format JPEG 75-80% quality
 */

declare(strict_types=1);

namespace Mentoring;

class ImageProcessor {
    public static function outputExtension(): string {
        return function_exists('imagewebp') ? 'webp' : 'jpg';
    }

    public static function outputMime(): string {
        return function_exists('imagewebp') ? 'image/webp' : 'image/jpeg';
    }

    /**
     * Optimasi dan kompres gambar dari file path sementara ke WebP (fallback JPEG)
     */
    public static function processAndCompress(string $sourcePath, int $maxWidth = 1280, int $quality = 78): string {
        $dual = self::processAndCompressDual($sourcePath, $maxWidth, $quality);
        return $dual['webp'] ?? $dual['jpg'];
    }

    /**
     * Optimasi dan kompres gambar sekaligus ke WebP (bila didukung) dan JPEG
     * dari satu kali pembacaan & resize GD resource.
     *
     * @return array{webp:?string, jpg:string}
     */
    public static function processAndCompressDual(string $sourcePath, int $maxWidth = 1280, int $quality = 78): array {
        if (!file_exists($sourcePath)) {
            throw new \InvalidArgumentException("File gambar tidak ditemukan: {$sourcePath}");
        }

        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            throw new \InvalidArgumentException("File bukan gambar yang valid");
        }

        $mime = $imageInfo['mime'];
        $width = $imageInfo[0];
        $height = $imageInfo[1];

        // Buat GD image resource berdasarkan tipe mime
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $src = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $src = imagecreatefrompng($sourcePath);
                break;
            case 'image/webp':
                $src = imagecreatefromwebp($sourcePath);
                break;
            default:
                throw new \InvalidArgumentException("Format gambar tidak didukung: {$mime}");
        }

        if (!$src) {
            throw new \RuntimeException("Gagal membaca gambar menggunakan GD");
        }

        // Kalkulasi ukuran baru jika lebar lebih dari maxWidth
        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int)round(($height / $width) * $newWidth);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);

        // Buat background putih untuk transparansi PNG jika ada
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $white);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $webpData = null;
        if (function_exists('imagewebp')) {
            ob_start();
            imagewebp($dst, null, $quality);
            $webpOut = ob_get_clean();
            if (is_string($webpOut) && $webpOut !== '') {
                $webpData = $webpOut;
            }
        }

        ob_start();
        imagejpeg($dst, null, $quality);
        $jpgData = (string)ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return [
            'webp' => $webpData,
            'jpg' => $jpgData,
        ];
    }
}
