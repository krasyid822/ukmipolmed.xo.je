<?php
/**
 * src/storage.php — Pointer Registry & Hybrid Storage Manager
 * Mengelola pointer_laporan.json dan pointer_master.json secara atomik.
 */

declare(strict_types=1);

namespace Mentoring;

class Storage {
    private static function getPointerFile(string $type): string {
        $file = ($type === 'master') ? 'pointer_master.json' : 'pointer_laporan.json';
        return Config::getSecureDir() . '/' . $file;
    }

    /**
     * Baca seluruh pointer. Berkas yang rusak TIDAK dianggap kosong agar
     * penulisan berikutnya tidak menghapus riwayat yang masih ada.
     */
    public static function getPointers(string $type): array {
        $path = self::getPointerFile($type);
        if (!is_file($path)) {
            return [];
        }

        $data = JsonFile::read($path);
        if ($data === null) {
            throw new \RuntimeException('Registry pointer rusak: ' . basename($path));
        }

        return array_values(array_filter($data, 'is_array'));
    }

    public static function savePointer(string $type, array $pointerRecord): bool {
        $id = $pointerRecord['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return false;
        }

        $path = self::getPointerFile($type);
        return JsonFile::withLock($path . '.lock', static function () use ($path, $type, $id, $pointerRecord): bool {
            $pointers = self::getPointers($type);

            $found = false;
            foreach ($pointers as $idx => $p) {
                if (($p['id'] ?? '') === $id) {
                    $pointers[$idx] = array_merge($p, $pointerRecord);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $pointers[] = $pointerRecord;
            }

            return JsonFile::writeAtomic($path, $pointers);
        });
    }

    public static function findPointer(string $type, string $id): ?array {
        foreach (self::getPointers($type) as $p) {
            if (($p['id'] ?? '') === $id) {
                return $p;
            }
        }
        return null;
    }

    public static function deletePointer(string $type, string $id): bool {
        $path = self::getPointerFile($type);
        return JsonFile::withLock($path . '.lock', static function () use ($path, $type, $id): bool {
            $pointers = self::getPointers($type);
            $filtered = array_values(array_filter(
                $pointers,
                static fn(array $p): bool => ($p['id'] ?? '') !== $id
            ));

            if (count($filtered) === count($pointers)) {
                return false;
            }

            return JsonFile::writeAtomic($path, $filtered);
        });
    }

    /**
     * Tulis ulang registry pointer dari satu record tanpa membaca isi lama.
     * HANYA dipakai untuk pemulihan darurat (mis. pointer lama rusak dan
     * dipulihkan dari bundel backup), bukan alur penyimpanan normal.
     */
    public static function overwritePointer(string $type, array $pointerRecord): bool {
        $id = $pointerRecord['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return false;
        }

        $path = self::getPointerFile($type);
        return JsonFile::withLock($path . '.lock', static fn(): bool => JsonFile::writeAtomic($path, [$pointerRecord]));
    }

    /**
     * Ganti seluruh daftar pointer secara atomik di bawah lock.
     */
    public static function replacePointers(string $type, array $records): bool {
        $path = self::getPointerFile($type);
        $clean = array_values(array_filter($records, static fn($r): bool => is_array($r) && !empty($r['id'])));
        return JsonFile::withLock($path . '.lock', static fn(): bool => JsonFile::writeAtomic($path, $clean));
    }
}
