<?php
/**
 * src/academic.php — Helper Akademik (Kelas & Semester)
 *
 * Kelas cukup diisi program studi + huruf kelas, mis. "TRPL-A" (tanpa angka
 * semester). Angka semester dihitung otomatis dari dua digit awal NIM yang
 * menunjukkan tahun stambuk, mis. NIM 2305181077 → stambuk 2023.
 */

declare(strict_types=1);

namespace Mentoring;

class Academic {
    /**
     * Tahun stambuk dari dua digit awal NIM (mis. "2305181077" → 2023).
     */
    public static function entryYearFromNim(?string $nim): ?int {
        $digits = preg_replace('/\D+/', '', (string)$nim) ?? '';
        if (strlen($digits) < 2) {
            return null;
        }

        $shortYear = (int)substr($digits, 0, 2);
        $year = 2000 + $shortYear;

        // Jaga nilai wajar: stambuk tidak boleh melewati tahun berjalan + 1.
        if ($year > (int)date('Y') + 1) {
            return null;
        }

        return $year;
    }

    /**
     * Semester berjalan berdasarkan NIM dan tanggal acuan.
     *
     * Tahun akademik dimulai September (semester ganjil) dan berganti ke
     * semester genap pada Maret. Contoh: stambuk 2023 pada November 2026
     * berada di semester 7.
     */
    public static function semesterFromNim(?string $nim, ?int $timestamp = null): ?int {
        $entryYear = self::entryYearFromNim($nim);
        if ($entryYear === null) {
            return null;
        }

        $timestamp ??= time();
        $year = (int)date('Y', $timestamp);
        $month = (int)date('n', $timestamp);

        if ($month >= 9) {
            $academicYear = $year;
            $period = 1; // ganjil
        } elseif ($month <= 2) {
            $academicYear = $year - 1;
            $period = 1; // ganjil (Januari–Februari masih semester ganjil)
        } else {
            $academicYear = $year - 1;
            $period = 2; // genap
        }

        $semester = ($academicYear - $entryYear) * 2 + $period;

        return max(1, $semester);
    }

    /**
     * Normalisasi kelas: buang angka semester dari nama kelas.
     * "TRPL-7A" / "TRPL 7A" / "TRPL7A" → "TRPL-A"; "TRPL-A" tetap "TRPL-A".
     */
    public static function normalizeClass(?string $kelas): string {
        $value = strtoupper(preg_replace('/\s+/', '', trim((string)$kelas)) ?? '');
        if ($value === '') {
            return '';
        }

        if (preg_match('/^([A-Z]+)-(\d*)([A-Z][A-Z0-9]*)$/', $value, $m)) {
            return $m[1] . '-' . $m[3];
        }

        // Batas prodi/kelas hanya jelas bila ada angka di antaranya ("TRPL7A").
        if (preg_match('/^([A-Z]+)(\d+)([A-Z][A-Z0-9]*)$/', $value, $m)) {
            return $m[1] . '-' . $m[3];
        }

        // Kode prodi murni tanpa huruf kelas (mis. "TRPL", "MI") dibiarkan apa adanya.
        return $value;
    }

    /**
     * Kelas lengkap untuk ditampilkan, dengan semester hasil hitungan NIM.
     * Kelas "TRPL-A" + NIM stambuk 2023 → "TRPL-7A".
     */
    public static function formatClass(?string $kelas, ?string $nim): string {
        $base = self::normalizeClass($kelas);
        if ($base === '') {
            return '';
        }

        $semester = self::semesterFromNim($nim);
        if ($semester === null) {
            return $base;
        }

        if (preg_match('/^([A-Z]+)-([A-Z][A-Z0-9]*)$/', $base, $m)) {
            return $m[1] . '-' . $semester . $m[2];
        }

        return $base;
    }
}
