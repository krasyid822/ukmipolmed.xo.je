<?php
// s3_helper.php — klien S3 minimal (SigV4, path-style) untuk Blomp.
// Fokus: PUT Object dengan ACL public-read. Tanpa SDK/Composer.

require_once __DIR__ . '/seo_cache.php';

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

// URL publik via proxy foto (karena Blomp menolak GET anonim).
function s3_photo_url($key)
{
    return 'https://ukmipolmed.my.id/foto?key=' . rawurlencode($key);
}

// Ubah URL foto absolut menjadi path relatif agar tetap tampil di host mana pun
// (mis. saat admin diuji di localhost atau domain lama).
function s3_to_relative_url($url)
{
    $url = trim((string) $url);
    if ($url === '') return '';
    // Absolute maupun relatif; sekaligus migrasi URL lama /foto.php?key=...
    // ke bentuk kanonik /foto?key=... agar tidak bergantung pada redirect.
    if (preg_match('~^(?:https?://[^/]+)?/foto(?:\.php)?(\?key=.*)$~i', $url, $m)) {
        return '/foto' . $m[1];
    }
    // Nilai lama bisa tersimpan sebagai key mentah S3.
    $prefix = function_exists('s3_prefix') ? s3_prefix() : '';
    if ($prefix !== '' && (strpos($url, $prefix . 'uploads/') === 0 || strpos($url, $prefix . 'assets/') === 0)) {
        return '/foto?key=' . rawurlencode($url);
    }
    return $url;
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
    $ok = ($code >= 200 && $code < 300) || $code === 404;
    if ($ok && strpos((string) $key, s3_prefix() . 'uploads/') === 0) {
        s3_uploads_cache_invalidate();
    }
    return $ok;
}

// PUT object; return public URL atau null jika gagal.
function s3_put_binary($bin, $key, $mime = 'application/octet-stream', &$debug = null, $public = true)
{
    if (!s3_available()) return null;
    if ($bin === '' || $bin === null) return null;

    $quota = s3_quota_bytes();
    if ($quota > 0) {
        // Saat cache belum ada/basi, satu kali hitung ulang lebih aman daripada
        // menganggap pemakaian tak terbatas. Bila tetap tak diketahui, tolak PUT.
        $usage = s3_usage_stats();
        if (!is_array($usage) || !isset($usage['bytes_used'])) {
            $debug = [
                'code' => 0,
                'body' => '',
                'err' => 'usage_unknown',
                'limit' => $quota,
            ];
            return null;
        }
        if ((int) $usage['bytes_used'] + strlen($bin) > $quota) {
            $debug = [
                'code' => 0,
                'body' => '',
                'err' => 'quota_exceeded',
                'used' => (int) $usage['bytes_used'],
                'limit' => $quota,
            ];
            return null;
        }
    }

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

    $aclHeader = $public ? ('x-amz-acl:public-read' . "\n") : '';
    $canonicalHeaders =
        'content-type:' . $contentType . "\n" .
        'host:' . $hostHeader . "\n" .
        $aclHeader .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = $public
        ? 'content-type;host;x-amz-acl;x-amz-content-sha256;x-amz-date'
        : 'content-type;host;x-amz-content-sha256;x-amz-date';
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
    $headers = ['Content-Type: ' . $contentType];
    if ($public) $headers[] = 'x-amz-acl: public-read';
    $headers[] = 'x-amz-content-sha256: ' . $payloadHash;
    $headers[] = 'x-amz-date: ' . $amzDate;
    $headers[] = 'Authorization: ' . $auth;

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
    if ($code >= 200 && $code < 300) {
        s3_usage_add(strlen($bin), 1, (string) $key);
        if (strpos((string) $key, s3_prefix() . 'uploads/') === 0) {
            s3_uploads_cache_invalidate();
        }
        return $url;
    }
    return null;
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

// Bersihkan SVG dari elemen/atribut berbahaya sebelum disimpan.
function s3_sanitize_svg_fallback($svg)
{
    $svg = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $svg);
    $svg = preg_replace('#<\s*foreignObject\b[^>]*>.*?<\s*/\s*foreignObject\s*>#is', '', $svg);
    $svg = preg_replace('#\son[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>"\']+)#is', '', $svg);
    $svg = preg_replace('#(href|xlink:href)\s*=\s*(["\'])\s*javascript:[^"\']*\2#is', '', $svg);
    return $svg;
}

function s3_sanitize_svg($svg)
{
    if (!is_string($svg) || $svg === '') return $svg;
    if (!class_exists('DOMDocument')) return s3_sanitize_svg_fallback($svg);

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$dom->documentElement) return s3_sanitize_svg_fallback($svg);

    $allowed = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'defs', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'pattern', 'symbol', 'use', 'title', 'desc',
    ];

    $walk = function (DOMElement $element) use (&$walk, $allowed) {
        if (!in_array(strtolower($element->tagName), $allowed, true)) {
            if ($element->parentNode) $element->parentNode->removeChild($element);
            return;
        }
        foreach (iterator_to_array($element->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = strtolower(trim((string) $attr->nodeValue));
            $isLink = ($name === 'href' || $name === 'xlink:href');
            if (strpos($name, 'on') === 0 || $name === 'style' || strpos($value, 'javascript:') !== false) {
                $element->removeAttributeNode($attr);
            } elseif ($isLink && strpos($value, '#') !== 0) {
                $element->removeAttributeNode($attr);
            }
        }
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) $walk($child);
        }
    };
    $walk($dom->documentElement);

    $out = $dom->saveXML($dom->documentElement);
    return is_string($out) && $out !== '' ? $out : s3_sanitize_svg_fallback($svg);
}

// List objek bucket via ListObjectsV2 (1000 objek/req).
// Return ['ok'=>bool,'objects'=>[['key'=>string,'size'=>int,'last_modified'=>int]],'error'=>?string].
function s3_list_objects($prefix = null)
{
    if (!s3_available()) return ['ok' => false, 'error' => 's3_unavailable', 'objects' => []];
    $cfg = s3_config();
    $endpoint = rtrim((string) $cfg['endpoint'], '/');
    $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);
    if (!$host) return ['ok' => false, 'error' => 'invalid_endpoint', 'objects' => []];
    $hostHeader = $port ? ($host . ':' . $port) : $host;

    $region = (string) $cfg['region'];
    $bucket = (string) $cfg['bucket'];
    $canonicalUri = '/' . rawurlencode($bucket);
    $objects = [];
    $token = null;
    $pages = 0;

    do {
        $params = ['list-type' => '2', 'max-keys' => '1000'];
        if ($prefix !== null && $prefix !== '') $params['prefix'] = $prefix;
        if ($token !== null) $params['continuation-token'] = $token;
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $canonicalQuery = implode('&', $pairs);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', '');
        $canonicalHeaders =
            'host:' . $hostHeader . "\n" .
            'x-amz-content-sha256:' . $payloadHash . "\n" .
            'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "GET\n" . $canonicalUri . "\n" . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        $credScope = $dateStamp . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credScope . "\n" . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret_key'], true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $auth = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $credScope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $ch = curl_init($scheme . '://' . $hostHeader . $canonicalUri . '?' . $canonicalQuery);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
                'Authorization: ' . $auth,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) {
            return ['ok' => false, 'error' => 'http_' . $code, 'objects' => $objects];
        }

        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return ['ok' => false, 'error' => 'xml_parse', 'objects' => $objects];
        }

        foreach ($xml->Contents as $c) {
            $objects[] = [
                'key' => (string) $c->Key,
                'size' => (int) $c->Size,
                'last_modified' => (int) strtotime((string) $c->LastModified),
            ];
        }

        $truncated = strtolower((string) $xml->IsTruncated) === 'true';
        $next = (string) ($xml->NextContinuationToken ?? '');
        if (!$truncated || $next === '' || $next === $token) break;
        $token = $next;
    } while (++$pages < 1000);

    return ['ok' => true, 'objects' => $objects, 'error' => null];
}

// Hitung pemakaian bucket via ListObjectsV2 (1000 objek/req).
// Return ['bytes_used'=>int,'objects'=>int,'updated_at'=>string,'at'=>int] atau null.
function s3_usage_fetch($prefix = null)
{
    $res = s3_list_objects($prefix);
    if (empty($res['ok'])) return null;
    $bytes = 0;
    $objects = 0;
    $keys = [];
    foreach ($res['objects'] as $o) {
        $bytes += (int) $o['size'];
        $objects++;
        // Simpan ukuran per key agar PUT berulang ke key yang sama (content
        // hash/overwrite) dihitung sebagai delta, bukan menambah ukuran penuh.
        if ($prefix === null) $keys[(string) $o['key']] = (int) $o['size'];
    }
    $out = [
        'bytes_used' => $bytes,
        'objects' => $objects,
        'updated_at' => date('c'),
        'at' => time(),
    ];
    if ($prefix === null) $out['keys'] = $keys;
    return $out;
}

// Hanya membaca cache pemakaian (tanpa request jaringan).
function s3_usage_cached()
{
    $cacheFile = seo_cache_path('s3-usage.json');
    if (!is_readable($cacheFile)) return null;
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    return is_array($cached) ? $cached : null;
}

// Versi cache (default 6 jam) agar tidak list bucket tiap request.
// $ttl = 0 memaksa hitung ulang.
function s3_usage_stats($ttl = 21600)
{
    $cacheFile = seo_cache_path('s3-usage.json');
    $cached = null;
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (!is_array($cached)) {
            $cached = null;
        } elseif (isset($cached['bytes_used'], $cached['at']) && (int) $cached['at'] + $ttl > time()) {
            return $cached;
        }
    }
    $fresh = s3_usage_fetch();
    if ($fresh === null) return $cached;
    @file_put_contents($cacheFile, json_encode($fresh, JSON_UNESCAPED_SLASHES), LOCK_EX);
    seo_cache_gc(basename($cacheFile));
    return $fresh;
}

// Satu-satunya sumber batas kapasitas: default.json "s3.max_bytes".
// 0 = tidak ada batas (hanya bila konfigurasi tidak terbaca/tidak valid).
function s3_quota_bytes()
{
    static $q = null;
    if ($q !== null) return $q;
    $q = 0;
    $p = __DIR__ . '/default.json';
    if (is_readable($p)) {
        $cfg = json_decode((string) @file_get_contents($p), true);
        if (is_array($cfg) && isset($cfg['s3']['max_bytes'])) {
            $v = (int) $cfg['s3']['max_bytes'];
            if ($v > 0) $q = $v;
        }
    }
    return $q;
}

// Satu kolam penyimpanan untuk seluruh data runtime (data.json, insights.json,
// komentar, unggahan). Batas tidak lagi per-file statis, tetapi sisa kapasitas
// S3 yang dihitung dinamis saat dipakai.
// $allowFetch=false hanya memakai cache agar halaman web tidak menunggu S3.
function s3_storage_pool(bool $allowFetch = true)
{
    static $memo = [];
    $key = $allowFetch ? 'live' : 'cache';
    if (array_key_exists($key, $memo)) return $memo[$key];

    $quota = s3_quota_bytes();
    if ($quota <= 0) return $memo[$key] = null;

    $usage = $allowFetch ? s3_usage_stats() : s3_usage_cached();
    if (!is_array($usage) || !isset($usage['bytes_used'])) return $memo[$key] = null;

    $used = max(0, (int) $usage['bytes_used']);
    $remaining = max(0, $quota - $used);
    return $memo[$key] = [
        'source' => 's3',
        'mode' => 'dynamic',
        'used_bytes' => $used,
        'limit_bytes' => $quota,
        'remaining_bytes' => $remaining,
        'percent_used' => $quota > 0 ? round(($used / $quota) * 100, 2) : null,
        'objects' => (int) ($usage['objects'] ?? 0),
        'updated_at' => $usage['updated_at'] ?? null,
    ];
}

// Sisa kapasitas efektif; 0 berarti tidak diketahui/tidak terbatas.

// Tambah pemakaian di cache setelah PUT sukses. Timestamp fetch tidak diubah
// agar recount penuh tetap terjadi saat TTL habis (koreksi drift akibat DELETE).
// $key membuat perhitungan menjadi delta terhadap ukuran lama, sehingga
// overwrite / re-upload key content-hash tidak menambah ukuran penuh berulang.
function s3_usage_add($bytes, $objects = 1, $key = null)
{
    $bytes = max(0, (int) $bytes);
    $key = is_string($key) && $key !== '' ? $key : null;
    $fh = @fopen(seo_cache_path('s3-usage.json'), 'c+');
    if (!$fh) return;
    if (flock($fh, LOCK_EX)) {
        $c = json_decode((string) stream_get_contents($fh), true);
        if (is_array($c) && isset($c['bytes_used'])) {
            $keys = is_array($c['keys'] ?? null) ? $c['keys'] : [];
            $delta = $bytes;
            $objectDelta = (int) $objects;
            if ($key !== null) {
                if (array_key_exists($key, $keys)) {
                    $delta = $bytes - (int) $keys[$key];
                    $objectDelta = 0;
                }
                $keys[$key] = $bytes;
                $c['keys'] = $keys;
            }
            $c['bytes_used'] = max(0, (int) $c['bytes_used'] + $delta);
            $c['objects'] = max(0, (int) ($c['objects'] ?? 0) + $objectDelta);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($c, JSON_UNESCAPED_SLASHES));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    seo_cache_gc('s3-usage.json');
}

// Cache pemakaian khusus prefix uploads (gambar/avatar) di S3.
function s3_uploads_cache_path()
{
    return seo_cache_path('s3-uploads.json');
}

// Paksa hitung ulang uploads pada pembacaan berikutnya (dipanggil setelah PUT/DELETE).
function s3_uploads_cache_invalidate()
{
    @unlink(s3_uploads_cache_path());
}

// $ttl default 6 jam. $allowFetch=false hanya memakai cache.
function s3_uploads_stats($ttl = 21600, bool $allowFetch = true)
{
    $prefix = s3_prefix() . 'uploads/';
    $cacheFile = s3_uploads_cache_path();
    $cached = null;
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (!is_array($cached)) $cached = null;
    }
    if ($cached !== null && (string) ($cached['prefix'] ?? '') !== $prefix) $cached = null;
    if ($cached !== null && (int) ($cached['at'] ?? 0) + $ttl > time()) return $cached;
    if (!$allowFetch) return $cached;
    $fresh = s3_usage_fetch($prefix);
    if ($fresh === null) return $cached;
    $fresh['prefix'] = $prefix;
    @file_put_contents($cacheFile, json_encode($fresh, JSON_UNESCAPED_SLASHES), LOCK_EX);
    seo_cache_gc(basename($cacheFile));
    return $fresh;
}

// Simpan gambar data: URI ke S3 (prefix uploads), return public URL atau null.
function s3_put_data_uri($dataUri){
    if (!is_string($dataUri) || stripos($dataUri, 'data:') !== 0) return null;
    if (!preg_match('#^data:([a-z0-9.+-]+/[a-z0-9.+-]+);base64,(.*)$#is', $dataUri, $m)) return null;
    $mime = strtolower($m[1]);
    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') return null;
    if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
        list($bin, $mime) = s3_optimize_image($bin, $mime);
    } elseif ($mime === 'image/svg+xml') {
        $bin = s3_sanitize_svg($bin);
    }
    $ext = s3_ext_for_mime($mime);
    $key = s3_prefix() . 'uploads/' . sha1($bin) . '.' . $ext;
    $ok = s3_put_binary($bin, $key, $mime);
    return $ok ? s3_photo_url($key) : null;
}
