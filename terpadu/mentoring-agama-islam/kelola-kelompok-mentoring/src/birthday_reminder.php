<?php
/**
 * src/birthday_reminder.php — Pengingat Ulang Tahun Mentor & Mentee
 */

declare(strict_types=1);

namespace Mentoring;

final class BirthdayReminder {
    public const SUSPEND_COOKIE = 'mai_birthday_suspend_until';
    public const MAX_SUSPEND_SECONDS = 604800; // 7 hari

    public static function getSuspendUntil(?int $now = null): ?int {
        $raw = $_COOKIE[self::SUSPEND_COOKIE] ?? '';
        if ($raw === '' || !ctype_digit((string)$raw)) {
            return null;
        }
        $until = (int)$raw;
        $current = $now ?? time();
        return $until > $current ? $until : null;
    }

    public static function isSuspended(?int $now = null): bool {
        return self::getSuspendUntil($now) !== null;
    }

    public static function setSuspend(int $seconds, ?int $now = null): int {
        if ($seconds <= 0 || $seconds > self::MAX_SUSPEND_SECONDS) {
            throw new \InvalidArgumentException('Durasi tunda harus antara 1 detik hingga maksimal 7 hari.');
        }
        $until = ($now ?? time()) + $seconds;
        if (!headers_sent()) {
            setcookie(self::SUSPEND_COOKIE, (string)$until, [
                'expires' => $until,
                'path' => '/',
                'samesite' => 'Lax',
                'httponly' => true,
            ]);
        }
        $_COOKIE[self::SUSPEND_COOKIE] = (string)$until;
        return $until;
    }

    public static function clearSuspend(): void {
        if (!headers_sent()) {
            setcookie(self::SUSPEND_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'samesite' => 'Lax',
                'httponly' => true,
            ]);
        }
        unset($_COOKIE[self::SUSPEND_COOKIE]);
    }

    /**
     * Ambil daftar mentor & mentee aktif yang berulang tahun hari ini.
     *
     * @return list<array{id:string,nama_lengkap:string,role:string,usia:int,tanggal_lahir:string,nama_tim:string}>
     */
    public static function getTodayBirthdays(array $master, ?\DateTimeImmutable $today = null): array {
        $today ??= new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta'));
        $todayMonth = (int)$today->format('n');
        $todayDay = (int)$today->format('j');
        $todayYear = (int)$today->format('Y');
        $isLeapYear = (int)$today->format('L') === 1;

        $teamMap = [];
        foreach ($master['teams'] ?? [] as $team) {
            if (!is_array($team)) {
                continue;
            }
            $teamId = (string)($team['id'] ?? '');
            $teamName = trim((string)($team['nama_tim'] ?? ''));
            if ($teamId !== '' && $teamName !== '') {
                $teamMap[$teamId] = $teamName;
            }
        }

        $results = [];

        foreach ($master['mentors'] ?? [] as $mentor) {
            if (!is_array($mentor)) {
                continue;
            }
            if (($mentor['status'] ?? 'Aktif') === 'Non-aktif') {
                continue;
            }
            $item = self::matchBirthday($mentor, 'Mentor', '', $todayYear, $todayMonth, $todayDay, $isLeapYear);
            if ($item !== null) {
                $results[] = $item;
            }
        }

        foreach ($master['mentees'] ?? [] as $mentee) {
            if (!is_array($mentee)) {
                continue;
            }
            if (($mentee['status_keaktifan'] ?? 'Aktif') === 'Tidak Aktif') {
                continue;
            }
            $timId = (string)($mentee['tim_id'] ?? '');
            $namaTim = $timId !== '' ? ($teamMap[$timId] ?? '') : '';
            $item = self::matchBirthday($mentee, 'Mentee', $namaTim, $todayYear, $todayMonth, $todayDay, $isLeapYear);
            if ($item !== null) {
                $results[] = $item;
            }
        }

        usort($results, static function (array $a, array $b): int {
            if ($a['role'] !== $b['role']) {
                return $a['role'] === 'Mentor' ? -1 : 1;
            }
            return strcasecmp($a['nama_lengkap'], $b['nama_lengkap']);
        });

        return $results;
    }

    /**
     * Render banner HTML pengingat ulang tahun. Mengembalikan string kosong bila tidak ada yang ultah
     * atau pengingat sedang ditunda.
     *
     * @param list<array{id:string,nama_lengkap:string,role:string,usia:int,tanggal_lahir:string,nama_tim:string}> $birthdays
     */
    public static function renderBanner(array $birthdays, string $detailUrl = 'ultah', bool $respectSuspend = true): string {
        if ($birthdays === [] || ($respectSuspend && self::isSuspended())) {
            return '';
        }

        $count = count($birthdays);
        if ($count > 1) {
            $href = htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8');
            return '<a class="birthday-banner birthday-banner-link" href="' . $href . '" aria-label="Lihat semua yang berulang tahun hari ini">'
                . '<div class="birthday-banner-header" style="margin-bottom: 0;">'
                . '<div class="birthday-banner-title">'
                . '<span aria-hidden="true">🎂</span>'
                . '<span>Ada ' . $count . ' orang berulang tahun hari ini!</span>'
                . '</div>'
                . '</div>'
                . '</a>';
        }
        $countBadge = $count > 1
            ? '<span class="birthday-count">' . $count . '</span>'
            : '';

        $itemsHtml = '';
        foreach ($birthdays as $person) {
            $name = htmlspecialchars($person['nama_lengkap'], ENT_QUOTES, 'UTF-8');
            $role = htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8');
            $usia = (int)$person['usia'];
            $teamHtml = $person['nama_tim'] !== ''
                ? '<span class="birthday-team">' . htmlspecialchars($person['nama_tim'], ENT_QUOTES, 'UTF-8') . '</span>'
                : '';

            $itemsHtml .= '<div class="birthday-item">'
                . '<div class="birthday-person">'
                . '<span class="birthday-name">' . $name . '</span>'
                . '<span class="birthday-meta">'
                . '<span class="birthday-role">' . $role . '</span>'
                . $teamHtml
                . '</span>'
                . '</div>'
                . '<span class="birthday-age">Usia ' . $usia . ' tahun</span>'
                . '</div>';
        }

        return '<div class="birthday-banner" role="region" aria-label="Pengingat Ulang Tahun">'
            . '<div class="birthday-banner-header">'
            . '<div class="birthday-banner-title">'
            . '<span aria-hidden="true">🎂</span>'
            . '<span>Pengingat Ulang Tahun Hari Ini</span>'
            . $countBadge
            . '</div>'
            . '<button type="button" class="birthday-close-btn" aria-label="Tutup pengingat ulang tahun" onclick="this.closest(\'.birthday-banner\').remove()">&times;</button>'
            . '</div>'
            . '<div class="birthday-list">' . $itemsHtml . '</div>'
            . '</div>';
    }

    private static function matchBirthday(
        array $row,
        string $role,
        string $namaTim,
        int $todayYear,
        int $todayMonth,
        int $todayDay,
        bool $isLeapYear
    ): ?array {
        $tgl = trim((string)($row['tanggal_lahir'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tgl, $m)) {
            return null;
        }

        $birthYear = (int)$m[1];
        $birthMonth = (int)$m[2];
        $birthDay = (int)$m[3];
        if (!checkdate($birthMonth, $birthDay, $birthYear)) {
            return null;
        }

        $isMatch = ($birthMonth === $todayMonth && $birthDay === $todayDay)
            || (!$isLeapYear && $birthMonth === 2 && $birthDay === 29 && $todayMonth === 2 && $todayDay === 28);
        if (!$isMatch) {
            return null;
        }

        $usia = $todayYear - $birthYear;
        if ($usia < 0) {
            return null;
        }

        $nama = trim((string)($row['nama_lengkap'] ?? ''));
        if ($nama === '') {
            return null;
        }

        return [
            'id' => (string)($row['id'] ?? ''),
            'nama_lengkap' => $nama,
            'role' => $role,
            'usia' => $usia,
            'tanggal_lahir' => $tgl,
            'nama_tim' => $namaTim,
        ];
    }
}
