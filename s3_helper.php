<?php
// s3_helper.php — klien S3 minimal (SigV4, path-style) untuk Blomp.
// Fokus: PUT Object dengan ACL public-read. Tanpa SDK/Composer.

function s3_config()
{
    static $c = null;
    if ($c !== null) return $c;
    $p = __DIR__ . '/s3_config.php';
    if (!is_readable($p)) return null;
    $cfg = include $p;
    $c = is_array($cfg) ? $cfg : null;
    return $c;
}

function s3_available()
{
    return function_exists('curl_init') && function_exists('hash_hmac') && s3_config() !== null;
}

function s3_prefix()
{
    $cfg = s3_config();
    $p = trim((string) ($cfg['prefix'] ?? 'ukmipolmed'), '/');
    return $p !== '' ? $p . '/' : '';
}

function s3_object_url($key)
{
    $cfg = s3_config();
    if (!$cfg) return '';
    $endpoint = rtrim((string) $cfg['endpoint'], '/');
    $segs = explode('/', ltrim($key, '/'));
    $enc = [];
    foreach ($segs as $s) $enc[] = rawurlencode($s);
    return $endpoint . '/' . rawurlencode((string) $cfg['bucket']) . '/' . implode('/', $enc);
}

// URL publik via proxy foto.php (karena Blomp menolak GET anonim).
function s3_photo_url($key)
{
    return 'https://ukmipolmed.my.id/foto.php?key=' . rawurlencode($key);
}

function s3_ext_for_mime($mime)
{
    $m = strtolower((string) $mime);
    if (strpos($m, 'jpeg') !== false || strpos($m, 'jpg') !== false) return 'jpg';
    if (strpos($m, 'png') !== false) return 'png';
    if (strpos($m, 'gif') !== false) return 'gif';
    if (strpos($m, 'webp') !== false) return 'webp';
    if (strpos($m, 'svg') !== false) return 'svg';
    return 'bin';
}

// GET objek via SigV4 (signed). Return ['code'=>int,'body'=>string|null].
function s3_get_object($key)
{
    if (!s3_available()) return ['code' => 0, 'body' => null];
    $cfg = s3_config();
    $endpoint = rtrim((string) $cfg['endpoint'], '/');
    $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);
    if (!$host) return ['code' => 0, 'body' => null];
    $hostHeader = $port ? ($host . ':' . $port) : $host;

    $segs = explode('/', trim((string) $cfg['bucket'] . '/' . $key, '/'));
    $enc = [];
    foreach ($segs as $s) $enc[] = rawurlencode($s);
    $canonicalUri = '/' . implode('/', $enc);

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', '');
    $canonicalHeaders =
        'host:' . $hostHeader . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "GET\n" . $canonicalUri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

    $region = (string) $cfg['region'];
    $credScope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credScope . "\n" . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $auth = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $credScope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $url = $scheme . '://' . $hostHeader . $canonicalUri;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Authorization: ' . $auth,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? $body : null];
}

// Hapus objek (signed DELETE). Return true jika 2xx atau 404.
function s3_delete_object($key)
{
    if (!s3_available()) return false;
    $cfg = s3_config();
    $endpoint = rtrim((string) $cfg['endpoint'], '/');
    $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);
    if (!$host) return false;
    $hostHeader = $port ? ($host . ':' . $port) : $host;

    $segs = explode('/', trim((string) $cfg['bucket'] . '/' . $key, '/'));
    $enc = [];
    foreach ($segs as $s) $enc[] = rawurlencode($s);
    $canonicalUri = '/' . implode('/', $enc);

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', '');
    $canonicalHeaders = 'host:' . $hostHeader . "\n" . 'x-amz-content-sha256:' . $payloadHash . "\n" . 'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "DELETE\n" . $canonicalUri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

    $region = (string) $cfg['region'];
    $credScope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credScope . "\n" . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $auth = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $credScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $url = $scheme . '://' . $hostHeader . $canonicalUri;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => [
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Authorization: ' . $auth,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) || $code === 404;
}

// PUT object; return public URL atau null jika gagal.
function s3_put_binary($bin, $key, $mime = 'application/octet-stream', &$debug = null)
{
    if (!s3_available()) return null;
    if ($bin === '' || $bin === null) return null;
    $cfg = s3_config();
    $endpoint = rtrim((string) $cfg['endpoint'], '/');
    $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);
    if (!$host) return null;
    $hostHeader = $port ? ($host . ':' . $port) : $host;

    // Canonical URI: /bucket/key (path-style), tiap segmen di-encode
    $segs = explode('/', trim((string) $cfg['bucket'] . '/' . $key, '/'));
    $enc = [];
    foreach ($segs as $s) $enc[] = rawurlencode($s);
    $canonicalUri = '/' . implode('/', $enc);

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash('sha256', $bin);
    $contentType = $mime;

    $canonicalHeaders =
        'content-type:' . $contentType . "\n" .
        'host:' . $hostHeader . "\n" .
        'x-amz-acl:public-read' . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'content-type;host;x-amz-acl;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "PUT\n" . $canonicalUri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

    $region = (string) $cfg['region'];
    $credScope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credScope . "\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $auth = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $credScope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $url = $scheme . '://' . $hostHeader . $canonicalUri;
    $headers = [
        'Content-Type: ' . $contentType,
        'x-amz-acl: public-read',
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $amzDate,
        'Authorization: ' . $auth,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $bin,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $debug = ['code' => $code, 'body' => is_string($resp) ? substr($resp, 0, 300) : '', 'err' => $curlErr];
    return ($code >= 200 && $code < 300) ? $url : null;
}

// Optimasi gambar: resize maks 1400px, encode WebP (fallback JPEG). GIF/SVG dilewati.
function s3_optimize_image($bin, $mime)
{
    if (!function_exists('imagecreatefromstring')) return [$bin, $mime];
    $im = @imagecreatefromstring($bin);
    if ($im === false) return [$bin, $mime];
    $w = imagesx($im);
    $h = imagesy($im);
    $maxDim = 1400;
    if ($w > $maxDim || $h > $maxDim) {
        $s = min($maxDim / $w, $maxDim / $h);
        $nw = max(1, (int) round($w * $s));
        $nh = max(1, (int) round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
    }
    ob_start();
    if (function_exists('imagewebp')) {
        imagewebp($im, null, 82);
        $out = ob_get_clean();
        $newMime = 'image/webp';
    } else {
        imagejpeg($im, null, 82);
        $out = ob_get_clean();
        $newMime = 'image/jpeg';
    }
    imagedestroy($im);
    if (!is_string($out) || $out === '') return [$bin, $mime];
    // pakai hasil optimasi hanya jika lebih kecil
    if (strlen($out) >= strlen($bin)) return [$bin, $mime];
    return [$out, $newMime];
}

// Simpan gambar data: URI ke S3 (prefix uploads), return public URL atau null.
function s3_put_data_uri($dataUri)
{
    if (!is_string($dataUri) || stripos($dataUri, 'data:') !== 0) return null;
    if (!preg_match('#^data:([a-z0-9.+-]+/[a-z0-9.+-]+);base64,(.*)$#is', $dataUri, $m)) return null;
    $mime = strtolower($m[1]);
    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') return null;
    if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
        list($bin, $mime) = s3_optimize_image($bin, $mime);
    }
    $ext = s3_ext_for_mime($mime);
    $key = s3_prefix() . 'uploads/' . sha1($bin) . '.' . $ext;
    $ok = s3_put_binary($bin, $key, $mime);
    return $ok ? s3_photo_url($key) : null;
}
