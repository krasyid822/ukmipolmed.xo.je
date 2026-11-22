<?php
/**
 * src/admin_auth.php — Proteksi Halaman Master dengan Sesi Admin Proyek Utama
 *
 * Modul mentoring berada di dalam direktori proyek utama (mis. /terpadu/mai/mentoring/),
 * sehingga cookie sesi PHP-nya dipakai bersama dengan halaman /admin proyek utama.
 * Guard ini memastikan pengunjung /master benar-benar sudah login sebagai admin
 * (bukan penulis/writer) dan sesinya belum diinvalidasi lewat `session_version`.
 */

declare(strict_types=1);

namespace Mentoring;

class AdminAuth {
    /** Maksimal kedalaman pencarian root proyek utama dari direktori aplikasi. */
    private const MAX_DEPTH = 6;

    /**
     * Cari root proyek utama dengan menelusuri direktori induk.
     * Root proyek utama ditandai oleh components/bootstrap.php + data/default.json.
     */
    public static function mainProjectRoot(): ?string {
        $dir = Config::getAppDir();

        for ($i = 0; $i < self::MAX_DEPTH; $i++) {
            $parent = dirname($dir);
            if ($parent === $dir || $parent === '' || $parent === '/') {
                break;
            }
            $dir = $parent;

            if (
                is_file($dir . '/components/bootstrap.php')
                && (is_file($dir . '/data.json') || is_file($dir . '/default.json'))
            ) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Apakah proteksi diaktifkan? Dapat dimatikan lewat
     * `app.master_requires_admin` = false pada storage_secure/default.json.
     */
    public static function enabled(): bool {
        return (bool)Config::get('app.master_requires_admin', true);
    }

    /**
     * Baca konfigurasi proyek utama. `data.json` lokal bisa berupa pointer S3,
     * sehingga pembacaan harus lewat store proyek utama agar nilai asli
     * (termasuk `session_version`) yang dipakai.
     */
    private static function readMainConfig(string $root, string $file): ?array {
        $path = $root . '/' . $file;
        if (!is_readable($path)) {
            return null;
        }

        if ($file === 'data.json' && is_file($root . '/store.php')) {
            if (!function_exists('store_read_json')) {
                require_once $root . '/store.php';
            }
            if (function_exists('store_read_json')) {
                $data = @store_read_json($path);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Baca `session_version` dari konfigurasi proyek utama (data.json, fallback default.json).
     */
    public static function expectedSessionVersion(string $root): int {
        foreach (['data.json', 'default.json'] as $file) {
            $data = self::readMainConfig($root, $file);
            if (is_array($data) && isset($data['session_version'])) {
                return max(1, (int)$data['session_version']);
            }
        }

        return 1;
    }

    /**
     * Apakah permintaan saat ini berasal dari admin proyek utama yang sah.
     */
    public static function isAdmin(): bool {
        if (!self::enabled()) {
            return true;
        }

        self::startSession();

        if (empty($_SESSION['admin_logged_in'])) {
            return false;
        }

        // Penulis (writer) hanya boleh mengakses panel penulis, bukan data master.
        if (($_SESSION['role'] ?? 'admin') === 'writer') {
            return false;
        }

        $root = self::mainProjectRoot();
        if ($root === null) {
            return false;
        }

        $sessionVersion = (int)($_SESSION['session_version'] ?? 0);
        return $sessionVersion === self::expectedSessionVersion($root);
    }

    /**
     * Hentikan eksekusi halaman bila bukan admin (HTTP 403 + tautan login).
     */
    public static function guard(): void {
        if (self::isAdmin()) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }

        $loginUrl = '/admin';
        $appRoot = \mentoring_base_path(Config::getAppDir());
        ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akses Ditolak — Data Master Mentoring</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #0b1120;
            color: #e2e8f0;
            font-family: "Segoe UI", Tahoma, sans-serif;
        }
        .deny-card {
            width: 100%;
            max-width: 520px;
            padding: 28px;
            border: 1px solid #ef4444;
            border-radius: 16px;
            background: rgba(239, 68, 68, 0.08);
        }
        .deny-code {
            display: inline-block;
            margin-bottom: 12px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(239, 68, 68, 0.18);
            color: #fca5a5;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
        }
        h1 { margin: 0 0 10px; font-size: 1.4rem; color: #fecaca; }
        p { margin: 0 0 12px; line-height: 1.6; font-size: 0.92rem; color: #cbd5e1; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        a.btn {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
            background: #ef4444;
            color: #fff;
        }
        a.btn.secondary { background: #1e293b; color: #cbd5e1; border: 1px solid #334155; }
        small { color: #94a3b8; font-size: 0.78rem; }
    </style>
</head>
<body>
<?php \mentoring_loading_overlay(); ?>
    <div class="deny-card">
        <span class="deny-code">403 · AKSES DITOLAK</span>
        <h1>Halaman ini khusus admin</h1>
        <p>
            Anda belum login sebagai admin proyek utama, atau sesi Anda sudah berakhir.
            Data master mentoring tidak dapat diakses tanpa sesi admin yang sah.
        </p>
        <p>
            Silakan login terlebih dahulu di halaman admin proyek utama, lalu buka kembali
            halaman ini.
        </p>
        <small>Jika Anda login sebagai penulis, gunakan panel penulis — bukan modul mentoring.</small>
        <div class="actions">
            <a class="btn" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES) ?>">Login Admin</a>
            <a class="btn secondary" href="<?= htmlspecialchars($appRoot, ENT_QUOTES) ?>">Kembali ke Form Pelaporan</a>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }

    private static function startSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
    }
}
