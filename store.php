<?php
// store.php — penyimpanan data JSON di S3 dengan pointer lokal.
//
// Lokal (proyek/FTP) hanya menyimpan pointer kecil, contoh data.json:
//   {"_store":"s3","_key":"ukmipolmed/data/data.json","size":63333,"updated_at":"..."}
//
// Payload sebenarnya ada di S3 pada prefix "data/". Cache L1 di seo-cache/
// dipakai agar pembacaan tetap cepat saat S3 lambat; sumber kebenaran tetap S3.

require_once __DIR__ . '/seo_cache.php';
require_once __DIR__ . '/s3_helper.php';

define('STORE_TTL', 21600);

function store_name($path)
{
    return basename((string) $path, '.json');
}

function store_key($name)
{
    return s3_prefix() . 'data/' . $name . '.json';
}


function store_cache_file($name)
{
    return seo_cache_path($name . '.cache.json');
}

// Payload besar disimpan terkompresi (.gz) agar tetap muat di batas cache.
function store_cache_file_gz($name)
{
    return store_cache_file($name) . '.gz';
}

function store_cache_actual_file($name)
{
    $gz = store_cache_file_gz($name);
    if (is_readable($gz)) return $gz;
    return store_cache_file($name);
}

function store_cache_size($name)
{
    $file = store_cache_actual_file($name);
    return is_readable($file) ? (int) filesize($file) : 0;
}

// Satu lock untuk semua nama: lebih sedikit inode daripada .lock per file.
function store_lock_file($name = null)
{
    return seo_cache_path('store.lock');
}

// Lock harus re-entrant per proses. flock() terikat pada file descriptor,
// bukan proses, sehingga membuka store.lock dua kali lalu mengunci keduanya
// membuat request mengunci dirinya sendiri (deadlock) — ini yang membuat
// server lokal macet. Helper ini memakai satu handle dengan hitungan kedalaman.
function &store_lock_state()
{
    static $state = ['handle' => null, 'depth' => 0];
    return $state;
}

function store_lock_acquire()
{
    $state = &store_lock_state();
    if ($state['depth'] > 0) {
        $state['depth']++;
        return true;
    }
    if (!function_exists('flock')) return false;

    $handle = @fopen(store_lock_file(), 'c');
    if (!$handle) return false;
    if (!@flock($handle, LOCK_EX)) {
        @fclose($handle);
        return false;
    }
    $state['handle'] = $handle;
    $state['depth'] = 1;
    return true;
}

function store_lock_release()
{
    $state = &store_lock_state();
    if ($state['depth'] <= 0) return;
    $state['depth']--;
    if ($state['depth'] === 0 && $state['handle']) {
        @flock($state['handle'], LOCK_UN);
        @fclose($state['handle']);
        $state['handle'] = null;
    }
}

// Memo per-request: satu file tidak perlu dibaca ulang dari disk/S3.
function &store_memo()
{
    static $memo = [];
    return $memo;
}

// Buang memo satu path agar pembacaan berikutnya menyusun ulang data
// (dipakai setelah penulisan shard yang tidak melewati store_save_raw).
function store_memo_forget($path)
{
    $memo = &store_memo();
    unset($memo[(string) $path]);
    if (basename((string) $path) === 'data.json' && function_exists('data_store_prefix')) {
        $prefix = data_store_prefix();
        foreach (array_keys($memo) as $k) {
            if (strpos($k, '@key:' . $prefix) === 0 || strpos($k, '@keyex:' . $prefix) === 0 || strpos($k, '@json:' . $prefix) === 0) {
                unset($memo[$k]);
            }
        }
        if (function_exists('data_store_index_by_slug')) {
            data_store_index_by_slug(true);
        }
    }
}

function store_memo_forget_key($key)
{
    $memo = &store_memo();
    $key = (string) $key;
    unset($memo['@key:' . $key], $memo['@keyex:' . $key], $memo['@json:' . $key]);
}

function store_decode($raw)
{
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function store_is_stub($data)
{
    return is_array($data) && (($data['_store'] ?? '') === 's3');
}

function store_read_local($path)
{
    if (!is_readable($path)) return null;
    return store_decode((string) @file_get_contents($path));
}

function store_stub_write($path, $key, $bytes, $updatedAt = null)
{
    $stub = [
        '_store' => 's3',
        '_key' => $key,
        'size' => (int) $bytes,
        'updated_at' => $updatedAt ?: date('c'),
    ];
    $json = json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function store_cache_read($name, $key)
{
    $files = [store_cache_file_gz($name), store_cache_file($name)];
    foreach ($files as $file) {
        if (!is_readable($file)) continue;
        $raw = (string) @file_get_contents($file);
        if (substr($file, -3) === '.gz') {
            if (!function_exists('gzdecode')) continue;
            $decoded = @gzdecode($raw);
            if ($decoded === false) continue;
            $raw = $decoded;
        }
        $c = store_decode($raw);
        if ($c === null || ($c['key'] ?? '') !== $key) continue;
        return $c;
    }
    return null;
}

function store_cache_write($name, $key, $data)
{
    $plainFile = store_cache_file($name);
    $gzFile = store_cache_file_gz($name);
    $payload = json_encode([
        'at' => time(),
        'key' => $key,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) return false;

    $file = $plainFile;
    $maxFile = seo_cache_max_file_bytes();
    // Cache "lazy" untuk payload besar: kalau JSON mentah melewati batas,
    // simpan versi gzip-nya. Pembacaan tetap transparan dan hanya menyentuh
    // S3 saat cache benar-benar tidak ada/kedaluwarsa.
    if (strlen($payload) > $maxFile && function_exists('gzencode')) {
        $gz = @gzencode($payload, 6);
        if (is_string($gz) && $gz !== '' && strlen($gz) < strlen($payload)) {
            $payload = $gz;
            $file = $gzFile;
        }
    }

    // Kalau setelah dikompres masih melewati batas, jangan paksa cache:
    // data tetap di S3, dan hosting tidak ikut membengkak. Cache lama harus
    // dibuang agar request berikutnya tidak menyajikan data basi.
    if (strlen($payload) > $maxFile) {
        @unlink($plainFile);
        @unlink($gzFile);
        seo_cache_gc();
        return false;
    }

    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        @unlink($plainFile);
        @unlink($gzFile);
        return false;
    }
    // Hanya satu varian yang disimpan agar tidak dobel inode. Varian lama
    // dibuang sebelum publish supaya pembaca tidak melihat campuran data.
    @unlink($file === $plainFile ? $gzFile : $plainFile);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        @unlink($plainFile);
        @unlink($gzFile);
        return false;
    }
    seo_cache_gc(basename($file));
    return true;
}

// Baca JSON: legacy lokal penuh, pointer+S3, atau cache lokal.
function store_read_json($path, $default = null, $ttl = STORE_TTL)
{
    $memo = &store_memo();
    if (array_key_exists($path, $memo)) {
        return $memo[$path];
    }

    $name = store_name($path);
    $key = store_key($name);
    $local = store_read_local($path);

    if (is_array($local) && !store_is_stub($local)) {
        // Masih data lokal penuh (belum dimigrasikan) — pakai apa adanya.
        return $memo[$path] = $local;
    }

    // data.json yang sudah dipecah: baca pecahan, bukan file tunggal.
    if (basename((string) $path) === 'data.json'
        && function_exists('data_store_is_sharded')
        && data_store_is_sharded()) {
        $complete = true;
        $data = data_store_read_all($complete);
        store_read_status_mark($path, $complete);
        return $memo[$path] = $data;
    }
    if (basename((string) $path) === 'assessments.json'
        && function_exists('assessments_store_is_sharded')
        && assessments_store_is_sharded()) {
        $complete = true;
        $data = assessments_store_read_all($complete);
        store_read_status_mark($path, $complete);
        return $memo[$path] = $data;
    }

    $cache = store_cache_read($name, $key);
    if ($cache !== null && (int) ($cache['at'] ?? 0) + $ttl > time()) {
        $data = is_array($cache['data'] ?? null) ? $cache['data'] : $default;
        return $memo[$path] = $data;
    }

    if (s3_available()) {
        $res = s3_get_object($key);
        if ((int) ($res['code'] ?? 0) === 200) {
            $data = store_decode($res['body'] ?? null);
            if (is_array($data)) {
                store_cache_write($name, $key, $data);
                if (!store_is_stub($local)) {
                    store_stub_write($path, $key, strlen((string) $res['body']));
                }
                return $memo[$path] = $data;
            }
        }
    }

    if ($cache !== null && is_array($cache['data'] ?? null)) {
        return $memo[$path] = $cache['data'];
    }
    return $memo[$path] = $default;
}

// Penanda kelengkapan baca terakhir per path. Dipakai agar penyimpanan tidak
// menimpa data hanya karena satu pecahan gagal dibaca (S3 error/timeout).
function store_read_status_mark($path, bool $complete)
{
    $GLOBALS['store_read_status'][(string) $path] = $complete;
}

function store_read_status_complete($path)
{
    return $GLOBALS['store_read_status'][(string) $path] ?? true;
}

// Baca objek S3 generik dengan status eksplisit:
//   ['data'=>mixed,'status'=>'ok'|'missing'|'error','cached'=>bool,'stale'=>bool]
function store_read_key_ex($name, $key, $default = null, $ttl = STORE_TTL)
{
    $memo = &store_memo();
    $memoKey = '@keyex:' . $key;
    if (array_key_exists($memoKey, $memo)) return $memo[$memoKey];

    $cache = store_cache_read($name, $key);
    if ($cache !== null && (int) ($cache['at'] ?? 0) + $ttl > time()) {
        $data = is_array($cache['data'] ?? null) ? $cache['data'] : $default;
        return $memo[$memoKey] = ['data' => $data, 'status' => 'ok', 'cached' => true, 'stale' => false];
    }

    if (s3_available()) {
        $res = s3_get_object($key);
        $code = (int) ($res['code'] ?? 0);
        if ($code === 200) {
            $data = store_decode($res['body'] ?? null);
            if (is_array($data)) {
                store_cache_write($name, $key, $data);
                return $memo[$memoKey] = ['data' => $data, 'status' => 'ok', 'cached' => false, 'stale' => false];
            }
            return $memo[$memoKey] = ['data' => $default, 'status' => 'error', 'cached' => false, 'stale' => false];
        }
        if ($code === 404) {
            return $memo[$memoKey] = ['data' => $default, 'status' => 'missing', 'cached' => false, 'stale' => false];
        }
    }

    if ($cache !== null && is_array($cache['data'] ?? null)) {
        return $memo[$memoKey] = ['data' => $cache['data'], 'status' => 'ok', 'cached' => true, 'stale' => true];
    }
    return $memo[$memoKey] = ['data' => $default, 'status' => 'error', 'cached' => false, 'stale' => false];
}

// Baca objek S3 generik (dipakai pecahan data_json) dengan cache L1.
function store_read_key($name, $key, $default = null, $ttl = STORE_TTL)
{
    $memo = &store_memo();
    $memoKey = '@key:' . $key;
    if (array_key_exists($memoKey, $memo)) return $memo[$memoKey];

    $cache = store_cache_read($name, $key);
    if ($cache !== null && (int) ($cache['at'] ?? 0) + $ttl > time()) {
        $data = is_array($cache['data'] ?? null) ? $cache['data'] : $default;
        return $memo[$memoKey] = $data;
    }

    if (s3_available()) {
        $res = s3_get_object($key);
        if ((int) ($res['code'] ?? 0) === 200) {
            $data = store_decode($res['body'] ?? null);
            if (is_array($data)) {
                store_cache_write($name, $key, $data);
                return $memo[$memoKey] = $data;
            }
        }
    }

    if ($cache !== null && is_array($cache['data'] ?? null)) {
        return $memo[$memoKey] = $cache['data'];
    }
    return $memo[$memoKey] = $default;
}

// Tulis objek S3 generik (tanpa pointer lokal).
function store_save_key($name, $key, $data, $lock = true)
{
    if (!is_array($data) || !s3_available()) return false;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $handle = null;
    if ($lock && function_exists('flock')) {
        if (store_lock_acquire()) $handle = true;
    }

    $debug = null;
    $url = s3_put_binary($json, $key, 'application/json', $debug, false);
    if ($url === null) {
        if ($handle) store_lock_release();
        return false;
    }

    store_cache_write($name, $key, $data);
    $memo = &store_memo();
    $memo['@key:' . $key] = $data;
    $memo['@keyex:' . $key] = ['data' => $data, 'status' => 'ok', 'cached' => true, 'stale' => false];
    $memo['@json:' . $key] = $json;

    if ($handle) store_lock_release();
    return true;
}

// Tulis JSON (string sudah ter-encode). Payload ke S3, lalu cache + pointer lokal.
function store_save_raw($path, $json, $lock = true)
{
    if (!is_string($json) || $json === '') return false;
    $data = store_decode($json);
    if ($data === null) return false;
    // Jangan menimpa data yang terakhir dibaca dalam kondisi tidak lengkap.
    if (!store_read_status_complete($path)) return false;
    if (!s3_available()) return false;

    $name = store_name($path);
    $key = store_key($name);

    // Kunci global juga mengunci read-modify-write sharded (data.json,
    // assessments.json) agar penulis paralel tidak saling menimpa.
    $handle = null;
    if ($lock && function_exists('flock')) {
        if (store_lock_acquire()) $handle = true;
    }

    $release = function () use (&$handle) {
        if ($handle) {
            store_lock_release();
            $handle = null;
        }
    };

    if (basename((string) $path) === 'data.json'
        && function_exists('data_store_is_sharded')
        && data_store_is_sharded()) {
        $ok = data_store_write_full($data);
        $release();
        return $ok;
    }
    if (basename((string) $path) === 'assessments.json'
        && function_exists('assessments_store_is_sharded')
        && assessments_store_is_sharded()) {
        $ok = assessments_store_write_full($data);
        $release();
        return $ok;
    }

    $debug = null;
    $url = s3_put_binary($json, $key, 'application/json', $debug, false);

    if ($url === null) {
        $release();
        return false;
    }

    store_cache_write($name, $key, $data);
    store_stub_write($path, $key, strlen($json));
    $memo = &store_memo();
    $memo[$path] = $data;

    $release();
    return true;
}

// Tulis JSON dari array/objek PHP.
function store_save_json($path, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return store_save_raw($path, $json);
}

// Migrasi satu file: unggah isi lokal penuh ke S3 lalu ubah file lokal jadi pointer.
function store_migrate_file($path)
{
    $name = store_name($path);
    $key = store_key($name);
    $local = store_read_local($path);
    if ($local === null) return ['ok' => false, 'error' => 'unreadable_or_invalid'];
    if (store_is_stub($local)) return ['ok' => true, 'skipped' => true];
    if (!s3_available()) return ['ok' => false, 'error' => 's3_unavailable'];

    $json = json_encode($local, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return ['ok' => false, 'error' => 'encode_failed'];

    // Tidak ada salinan .backups: payload asli tetap utuh di S3 dan file lokal
    // baru diubah menjadi pointer setelah PUT sukses.
    $debug = null;
    $url = s3_put_binary($json, $key, 'application/json', $debug, false);
    if ($url === null) {
        return ['ok' => false, 'error' => 's3_put_failed', 'debug' => $debug];
    }

    store_cache_write($name, $key, $local);
    if (!store_stub_write($path, $key, strlen($json))) {
        return ['ok' => false, 'error' => 'stub_write_failed'];
    }
    return ['ok' => true, 'bytes' => strlen($json), 'key' => $key];
}

// Muat lapisan pecahan data_json (kompatibel dua arah; file ini saling
// require_once dengan aman).
require_once __DIR__ . '/data_store.php';

require_once __DIR__ . '/assessments_store.php';
