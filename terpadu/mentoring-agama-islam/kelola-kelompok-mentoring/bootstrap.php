<?php
/**
 * bootstrap.php — Autoloader dan Inisialisasi Aplikasi
 */

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'Mentoring\\';
    $baseDir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    
    // Konversi CamelCase ke snake_case untuk nama file di src/
    $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $relativeClass));
    $file = $baseDir . $snake . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Hitung URL path sebuah direktori relatif terhadap document root.
 * Contoh hasil: "/master/" (lokal) atau "/terpadu/mai/mentoring/master/" (produksi).
 */
function mentoring_base_path(string $dir): string
{
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $realDir = realpath($dir) ?: $dir;

    $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
    $realDir = rtrim(str_replace('\\', '/', $realDir), '/');

    if ($docRoot !== '' && str_starts_with($realDir, $docRoot)) {
        $sub = substr($realDir, strlen($docRoot));
        return rtrim($sub, '/') . '/';
    }

    // Fallback: hitung dari SCRIPT_NAME & SCRIPT_FILENAME terhadap root aplikasi (__DIR__)
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__) ?: __DIR__), '/');
    $scriptFilename = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptFilename !== '' && $scriptName !== '' && str_starts_with($scriptFilename, $appRoot)) {
        $relScript = substr($scriptFilename, strlen($appRoot)); // mis. "/master/index.php"
        if ($relScript !== '' && str_ends_with($scriptName, $relScript)) {
            $appBase = substr($scriptName, 0, -strlen($relScript));
            $subFromApp = str_starts_with($realDir, $appRoot) ? substr($realDir, strlen($appRoot)) : '';
            return rtrim($appBase . $subFromApp, '/') . '/';
        }
    }

    // Fallback terakhir (mis. CLI): ambil path dari konfigurasi app.base_url
    try {
        $cfgBase = (string)\Mentoring\Config::get('app.base_url', '/');
        $cfgPath = (string)(parse_url($cfgBase, PHP_URL_PATH) ?: '/');
        $subFromApp = str_starts_with($realDir, $appRoot) ? substr($realDir, strlen($appRoot)) : '';
        return rtrim(rtrim($cfgPath, '/') . $subFromApp, '/') . '/';
    } catch (\Throwable $e) {
        return '/';
    }
}

/**
 * URL path root aplikasi berdasarkan request aktual, bukan DOCUMENT_ROOT.
 * Ini tetap benar saat aplikasi dipetakan lewat alias/rewrite, dan saat
 * PHP built-in server tidak menyediakan DOCUMENT_ROOT yang sesuai.
 *
 * @param string $scriptSuffix Path skrip saat ini tanpa leading slash,
 *                             mis. "master/pengaturan" atau "setup-biometric".
 */
function mentoring_request_app_base(string $scriptSuffix): ?string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    $suffix = '/' . trim($scriptSuffix, '/');
    $pattern = '#^(.*?)' . preg_quote($suffix, '#') . '(?:\.php)?/?$#i';
    if (!preg_match($pattern, $path, $matches)) {
        return null;
    }

    $base = $matches[1];
    return $base === '' ? '/' : rtrim($base, '/') . '/';
}

/**
 * Origin (skema + host) dari request saat ini.
 */
function mentoring_request_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * URL absolut root aplikasi, aman untuk dibagikan (mis. link WhatsApp).
 */
function mentoring_absolute_app_base(string $scriptSuffix): string
{
    $path = mentoring_request_app_base($scriptSuffix) ?? mentoring_base_path(__DIR__);
    return mentoring_request_origin() . $path;
}

/**
 * Redirect 303 ke halaman form dengan kode kesalahan dari transaksi master.
 *
 * @param array{ok:bool,error:?string,error_key:?string,context:array} $result
 */
function mentoring_redirect_master_error(string $location, array $result): void
{
    $key = (string)($result['error_key'] ?? 'simpan');
    $query = 'error=' . urlencode($key);
    if (!empty($result['error'])) {
        $query .= '&pesan=' . urlencode((string)$result['error']);
    }

    foreach (($result['context'] ?? []) as $name => $value) {
        if (is_string($value) || is_int($value)) {
            $query .= '&' . urlencode((string)$name) . '=' . urlencode((string)$value);
        }
    }

    $separator = str_contains($location, '?') ? '&' : '?';
    header('Location: ' . $location . $separator . $query, true, 303);
    exit;
}

/**
 * Pesan kesalahan form dari query string (`error` + opsional `pesan`).
 */
function mentoring_form_error(): string
{
    $key = (string)($_GET['error'] ?? '');
    if ($key === '') {
        return '';
    }

    $pesan = trim((string)($_GET['pesan'] ?? ''));
    if ($pesan !== '') {
        return $pesan;
    }

    return match ($key) {
        'wajib' => 'Kolom bertanda wajib belum diisi.',
        'foto' => 'Foto gagal diproses. Gunakan JPG/PNG/WEBP maksimal 8 MB.',
        'konflik' => 'Data master sudah berubah. Muat ulang halaman lalu ulangi perubahan.',
        'validasi' => 'Data tidak lolos validasi skema master.',
        'kosong' => 'Penyimpanan dibatalkan karena data master kosong.',
        'muat' => 'Data master gagal dimuat sehingga penyimpanan dibatalkan.',
        'storage' => 'Penyimpanan gagal. Data lama tidak diubah.',
        'notfound' => 'Data tidak ditemukan atau sudah dihapus.',
        default => 'Data gagal disimpan. Data lama tidak diubah.',
    };
}

/**
 * Render komponen loading overlay (diadaptasi dari proyek utama).
 */
function mentoring_loading_overlay(): void
{
    require __DIR__ . '/src/loading_overlay.php';
}
