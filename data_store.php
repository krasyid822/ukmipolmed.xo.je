<?php
// data_store.php — penyimpanan data situs sebagai pecahan kecil di S3.
//
// Layout (prefix S3 ukmipolmed/data_json/):
//   manifest.json
//   meta.json          user, key, session_version, fb_app_id, yel_yel
//   site.json          agenda, agendas, registration, docs, divisions, dll.
//   faqs.json
//   logs.json
//   extra.json         key top-level baru yang belum dipetakan
//   posts/index.json   metadata ringan untuk listing
//   posts/<sha1>.json  isi lengkap satu artikel (tanpa komentar)
//   comments/<sha1>.json
//
// Tujuan: halaman publik hanya membaca pecahan yang dibutuhkan, sehingga satu
// artikel/komentar yang membesar tidak memaksa seluruh data.json diunduh.
// Kompatibilitas: selama manifest belum aktif, store.php tetap memakai
// data/data.json lama. Setelah migrasi, data.json lokal menjadi pointer kecil
// dengan penanda "_sharded": true.

require_once __DIR__ . '/store.php';

const DATA_STORE_VERSION = 1;
const DATA_STORE_INDEX_REL = 'posts/index.json';

function data_store_prefix()
{
    return s3_prefix() . 'data_json/';
}

function data_store_key($rel)
{
    return data_store_prefix() . ltrim((string) $rel, '/');
}

function data_store_slug_hash($slug)
{
    return sha1((string) $slug);
}

function data_store_cache_name($rel)
{
    return 'data_json_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $rel);
}

function data_store_cache_clear($rel)
{
    $name = data_store_cache_name($rel);
    @unlink(store_cache_file($name));
    @unlink(store_cache_file_gz($name));
    if (function_exists('store_memo_forget_key')) {
        store_memo_forget_key(data_store_key($rel));
    }
}

function data_store_shard_read($rel, $default = null, $ttl = STORE_TTL)
{
    return store_read_key(data_store_cache_name($rel), data_store_key($rel), $default, $ttl);
}

function data_store_shard_write($rel, $data, $lock = true)
{
    return store_save_key(data_store_cache_name($rel), data_store_key($rel), $data, $lock);
}

function data_store_shard_delete($rel)
{
    if (!s3_available()) return false;
    $ok = s3_delete_object(data_store_key($rel));
    data_store_cache_clear($rel);
    return $ok;
}

function data_store_manifest_read(bool $reset = false)
{
    static $m = false;
    if ($reset) {
        $m = false;
        return null;
    }
    if ($m !== false) return $m;
    $data = data_store_shard_read('manifest.json', null);
    return $m = (is_array($data) && (int) ($data['version'] ?? 0) >= 1) ? $data : null;
}

function data_store_is_sharded()
{
    static $sharded = null;
    if ($sharded !== null) return $sharded;

    $local = store_read_local(__DIR__ . '/data.json');
    if (empty($local['_sharded'])) return $sharded = false;
    return $sharded = data_store_manifest_read() !== null;
}

function data_store_pointer_write()
{
    $stub = [
        '_store' => 's3',
        '_sharded' => true,
        '_key' => data_store_key('manifest.json'),
        'version' => DATA_STORE_VERSION,
        'updated_at' => date('c'),
    ];
    $json = json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $path = __DIR__ . '/data.json';
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function data_store_meta_read()
{
    $d = data_store_shard_read('meta.json', []);
    return is_array($d) ? $d : [];
}

function data_store_site_read()
{
    $d = data_store_shard_read('site.json', []);
    return is_array($d) ? $d : [];
}

function data_store_faqs_read()
{
    $d = data_store_shard_read('faqs.json', []);
    return is_array($d) ? $d : [];
}

function data_store_logs_read()
{
    $d = data_store_shard_read('logs.json', []);
    return is_array($d) ? $d : [];
}

function data_store_extra_read()
{
    $d = data_store_shard_read('extra.json', []);
    return is_array($d) ? $d : [];
}

function data_store_posts_index_read()
{
    $d = data_store_shard_read(DATA_STORE_INDEX_REL, []);
    return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
}

// Versi dengan status eksplisit: 'error' tidak boleh dianggap komentar kosong.
function data_store_comments_read_ex($slug)
{
    $rel = 'comments/' . data_store_slug_hash($slug) . '.json';
    $res = data_store_shard_read_ex($rel, []);
    $status = (string) ($res['status'] ?? 'error');
    $raw = $res['data'] ?? [];
    if (is_array($raw) && isset($raw['comments']) && is_array($raw['comments'])) {
        $comments = array_values($raw['comments']);
    } else {
        $comments = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }
    return ['status' => $status, 'comments' => $comments];
}

// Perbarui metadata komentar di posts/index.json tanpa menulis ulang semua
// pecahan. Gagal bila index tidak terbaca dengan status pasti.
function data_store_index_update_comments($slug, array $comments, bool $lock = true)
{
    $res = data_store_shard_read_ex(DATA_STORE_INDEX_REL, []);
    $status = (string) ($res['status'] ?? 'error');
    if ($status !== 'ok' && $status !== 'missing') return false;
    $index = is_array($res['data'] ?? null) ? array_values(array_filter($res['data'], 'is_array')) : [];
    $json = json_encode(array_values($comments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $bytes = $json === false ? 0 : strlen($json);
    $found = false;
    foreach ($index as &$row) {
        if ((string) ($row['slug'] ?? '') !== $slug) continue;
        $row['comments_count'] = count($comments);
        $row['comments_bytes'] = $bytes;
        $found = true;
        break;
    }
    unset($row);
    if (!$found) return false;
    $ok = data_store_shard_write(DATA_STORE_INDEX_REL, $index, $lock);
    if ($ok) data_store_index_by_slug(true);
    return $ok;
}

function data_store_comments_write($slug, array $comments, bool $lock = true)
{
    $rel = 'comments/' . data_store_slug_hash($slug) . '.json';
    return data_store_shard_write($rel, [
        'slug' => (string) $slug,
        'updated_at' => date('c'),
        'comments' => array_values($comments),
    ], $lock);
}

function data_store_index_by_slug(bool $reset = false)
{
    static $map = null;
    if ($reset) {
        $map = null;
        return [];
    }
    if ($map !== null) return $map;
    $map = [];
    foreach (data_store_posts_index_read() as $row) {
        $s = trim((string) ($row['slug'] ?? ''));
        if ($s !== '') $map[$s] = $row;
    }
    return $map;
}

function data_store_shard_read_ex($rel, $default = null)
{
    return store_read_key_ex(data_store_cache_name($rel), data_store_key($rel), $default);
}

function data_store_post_read_ex($slug, $withComments = true)
{
    $rel = 'posts/' . data_store_slug_hash($slug) . '.json';
    $res = data_store_shard_read_ex($rel, null);
    if (($res['status'] ?? '') !== 'ok' || !is_array($res['data'] ?? null)) {
        return ['status' => ($res['status'] ?? 'error'), 'post' => null];
    }
    $post = $res['data'];
    if ($withComments) {
        $map = data_store_index_by_slug();
        $count = isset($map[$slug]) ? (int) ($map[$slug]['comments_count'] ?? 0) : -1;
        if ($count === 0) {
            $post['comments'] = [];
        } else {
            $cres = data_store_shard_read_ex('comments/' . data_store_slug_hash($slug) . '.json', []);
            if (($cres['status'] ?? '') !== 'ok') return ['status' => ($cres['status'] ?? 'error'), 'post' => null];
            $raw = $cres['data'];
            $post['comments'] = is_array($raw['comments'] ?? null) ? array_values($raw['comments']) : (is_array($raw) ? array_values($raw) : []);
        }
    }
    return ['status' => 'ok', 'post' => $post];
}

function data_store_post_read($slug, $withComments = true)
{
    $res = data_store_post_read_ex($slug, $withComments);
    return $res['status'] === 'ok' ? $res['post'] : null;
}


// Ringkasan listing. Memakai field summary bila ada; selain itu teks polos
// dari body (Parsedown bila tersedia) agar index tidak perlu menyimpan body.
function data_store_summary(array $post, int $limit = 180): string
{
    $summary = trim((string) ($post['summary'] ?? ''));
    if ($summary !== '') return $summary;

    $body = (string) ($post['body'] ?? '');
    if ($body === '') return '';

    $plain = '';
    if (is_readable(__DIR__ . '/lib/Parsedown.php')) {
        require_once __DIR__ . '/lib/Parsedown.php';
        if (class_exists('Parsedown')) {
            $pd = new Parsedown();
            $pd->setSafeMode(true);
            $html = $pd->text($body);
            $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
            $plain = strip_tags($html);
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    if ($plain === '') {
        $plain = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $body);
        $plain = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $plain);
        $plain = preg_replace('/[`*_>#~\-]+/', ' ', (string) $plain);
        $plain = strip_tags((string) $plain);
    }
    $plain = trim(preg_replace('/\s+/u', ' ', (string) $plain));
    if (function_exists('mb_substr')) return mb_substr($plain, 0, $limit, 'UTF-8');
    return substr($plain, 0, $limit);
}

function data_store_index_entry(array $post, bool $archived): array
{
    $slug = trim((string) ($post['slug'] ?? ''));
    $image = trim((string) ($post['image'] ?? ''));
    if (stripos($image, 'data:') === 0) {
        // Jangan masukkan base64 ke index; detail artikel tetap memuat penuh.
        $image = '';
    }
    $comments = isset($post['comments']) && is_array($post['comments']) ? $post['comments'] : [];
    $commentsJson = json_encode($comments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [
        'title' => (string) ($post['title'] ?? ''),
        'slug' => $slug,
        'summary' => data_store_summary($post),
        'image' => $image,
        'focal_x' => isset($post['focal_x']) ? max(0, min(100, (float) $post['focal_x'])) : 50,
        'focal_y' => isset($post['focal_y']) ? max(0, min(100, (float) $post['focal_y'])) : 50,
        'author_id' => (string) ($post['author_id'] ?? ''),
        'created_at' => (string) ($post['created_at'] ?? ''),
        'updated_at' => (string) ($post['updated_at'] ?? ''),
        'archived' => $archived,
        'comments_count' => count($comments),
        'comments_bytes' => $commentsJson === false ? 0 : strlen($commentsJson),
    ];
}

function data_store_split(array $data)
{
    $metaKeys = ['user', 'key', 'session_version', 'fb_app_id', 'yel_yel'];
    $siteKeys = ['agenda', 'agendas', 'agenda_archive', 'registration', 'docs', 'docs_settings', 'shortcut_cards', 'divisions', 'hero_cards'];
    $logKeys = ['logs', 'logs_archive'];
    $known = array_merge($metaKeys, $siteKeys, $logKeys, ['posts', 'posts_archived', 'faqs']);

    $meta = [];
    foreach ($metaKeys as $k) if (array_key_exists($k, $data)) $meta[$k] = $data[$k];
    $site = [];
    foreach ($siteKeys as $k) if (array_key_exists($k, $data)) $site[$k] = $data[$k];
    $logs = [];
    foreach ($logKeys as $k) if (array_key_exists($k, $data)) $logs[$k] = $data[$k];
    $extra = [];
    foreach ($data as $k => $v) {
        if (!in_array($k, $known, true)) $extra[$k] = $v;
    }

    $posts = isset($data['posts']) && is_array($data['posts']) ? array_values(array_filter($data['posts'], 'is_array')) : [];
    $archived = isset($data['posts_archived']) && is_array($data['posts_archived']) ? array_values(array_filter($data['posts_archived'], 'is_array')) : [];

    $index = [];
    $postFiles = [];
    $commentFiles = [];
    $seen = [];
    foreach ([[$posts, false], [$archived, true]] as $pair) {
        foreach ($pair[0] as $post) {
            $slug = trim((string) ($post['slug'] ?? ''));
            if ($slug === '') continue;
            if (isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $index[] = data_store_index_entry($post, $pair[1] || !empty($post['archived']));
            $comments = isset($post['comments']) && is_array($post['comments']) ? $post['comments'] : [];
            unset($post['comments']);
            $postFiles[$slug] = $post;
            $commentFiles[$slug] = array_values($comments);
        }
    }

    return [
        'meta' => $meta,
        'site' => $site,
        'logs' => $logs,
        'faqs' => is_array($data['faqs'] ?? null) ? $data['faqs'] : [],
        'extra' => $extra,
        'index' => $index,
        'posts' => $postFiles,
        'comments' => $commentFiles,
    ];
}

function data_store_write_full(array $data)
{
    if (!s3_available()) return false;
    // Cegah penulisan snapshot yang berasal dari baca tidak lengkap.
    if (!store_read_status_complete(__DIR__ . '/data.json')) return false;

    $parts = data_store_split($data);
    $ok = true;
    $oldIndex = data_store_posts_index_read();
    $oldSlugs = [];
    foreach ($oldIndex as $row) {
        $s = trim((string) ($row['slug'] ?? ''));
        if ($s !== '') $oldSlugs[$s] = true;
    }

    // Metadata kecil: tulis hanya bila berubah.
    foreach ([
        'meta.json' => $parts['meta'],
        'site.json' => $parts['site'],
        'logs.json' => $parts['logs'],
        'faqs.json' => $parts['faqs'],
        'extra.json' => $parts['extra'],
    ] as $rel => $payload) {
        $old = data_store_shard_read($rel, null);
        if ($old === $payload) continue;
        if (!data_store_shard_write($rel, $payload)) $ok = false;
    }

    foreach ($parts['posts'] as $slug => $post) {
        $rel = 'posts/' . data_store_slug_hash($slug) . '.json';
        $old = data_store_shard_read($rel, null);
        if ($old !== $post && !data_store_shard_write($rel, $post)) $ok = false;
    }
    foreach ($parts['comments'] as $slug => $comments) {
        $rel = 'comments/' . data_store_slug_hash($slug) . '.json';
        $oldRaw = data_store_shard_read($rel, null);
        $oldComments = is_array($oldRaw['comments'] ?? null) ? $oldRaw['comments'] : [];
        if (is_array($oldRaw) && $oldComments === $comments) continue;
        if (!data_store_comments_write($slug, $comments)) $ok = false;
    }
    if ($oldIndex !== $parts['index'] && !data_store_shard_write(DATA_STORE_INDEX_REL, $parts['index'])) {
        $ok = false;
    }

    $manifest = [
        'version' => DATA_STORE_VERSION,
        'generated_at' => date('c'),
        'parts' => ['meta.json', 'site.json', 'logs.json', 'faqs.json', 'extra.json', DATA_STORE_INDEX_REL],
        'posts' => count($parts['posts']),
        'archived' => count(array_filter($parts['index'], fn($p) => !empty($p['archived']))),
        'comments' => array_sum(array_map('count', $parts['comments'])),
    ];
    $oldManifest = data_store_manifest_read();
    if ($oldManifest !== $manifest) {
        if (!data_store_shard_write('manifest.json', $manifest)) $ok = false;
    }
    if (!$ok) return false;

    // Baru setelah index + manifest sukses, shard lama boleh dihapus.
    foreach (array_keys($oldSlugs) as $slug) {
        if (isset($parts['posts'][$slug])) continue;
        data_store_shard_delete('posts/' . data_store_slug_hash($slug) . '.json');
        data_store_shard_delete('comments/' . data_store_slug_hash($slug) . '.json');
    }

    data_store_pointer_write();
    $memo = &store_memo();
    $memo[__DIR__ . '/data.json'] = $data;
    return true;
}

function data_store_read_all(&$complete = null)
{
    $complete = true;
    // 'missing' adalah 404 definitif (boleh dianggap kosong); 'error' berarti
    // S3 gagal/timeout dan tidak boleh dianggap data kosong.
    $read = function ($rel, $default) use (&$complete) {
        $res = data_store_shard_read_ex($rel, $default);
        $status = (string) ($res['status'] ?? 'error');
        if ($status !== 'ok' && $status !== 'missing') $complete = false;
        return is_array($res['data'] ?? null) ? $res['data'] : $default;
    };
    $meta = $read('meta.json', []);
    $site = $read('site.json', []);
    $logs = $read('logs.json', []);
    $extra = $read('extra.json', []);
    $faqs = $read('faqs.json', []);
    $index = $read(DATA_STORE_INDEX_REL, []);
    $index = array_values(array_filter($index, 'is_array'));

    $posts = [];
    $archived = [];
    foreach ($index as $row) {
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($slug === '') continue;
        $res = data_store_post_read_ex($slug, true);
        if ($res['status'] !== 'ok' || !is_array($res['post'])) {
            // Jangan substitusi metadata tipis: tandai baca tidak lengkap agar
            // pemanggil tidak menimpanya kembali ke S3.
            $complete = false;
            $post = $row;
        } else {
            $post = $res['post'];
        }
        if (!empty($row['archived'])) $archived[] = $post;
        else $posts[] = $post;
    }

    return array_merge(
        $meta,
        $site,
        $logs,
        $extra,
        [
            'faqs' => $faqs,
            'posts' => $posts,
            'posts_archived' => $archived,
        ]
    );
}

// Migrasi data.json tunggal -> data_json/. Idempoten; payload lama di
// data/data.json tidak dihapus agar rollback tetap mungkin.
function data_store_migrate()
{
    if (data_store_is_sharded()) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'already_sharded'];
    }
    $data = store_read_json(__DIR__ . '/data.json');
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'data_unreadable'];
    }
    if (!data_store_write_full($data)) {
        return ['ok' => false, 'error' => 'write_failed'];
    }
    // Cache monolitik lama tidak dipakai lagi setelah mode pecahan aktif.
    @unlink(store_cache_file('data'));
    @unlink(store_cache_file_gz('data'));
    return [
        'ok' => true,
        'parts' => count(data_store_posts_index_read()),
        'manifest' => data_store_key('manifest.json'),
    ];
}

// Kembali ke data/data.json lama tanpa menghapus pecahan (bisa diaktifkan lagi).
function data_store_rollback($deleteManifest = false)
{
    // Simpan dulu kondisi shard saat ini ke data/data.json agar rollback tidak
    // kembali ke snapshot lama dan kehilangan perubahan setelah migrasi.
    $data = data_store_read_all($complete);
    if (!is_array($data) || empty($complete)) return false;
    $fullJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($fullJson === false) return false;
    $debug = null;
    $legacyKey = store_key('data');
    if (s3_put_binary($fullJson, $legacyKey, 'application/json', $debug, false) === null) {
        return false;
    }
    store_cache_write('data', $legacyKey, $data);

    $path = __DIR__ . '/data.json';
    $stub = [
        '_store' => 's3',
        '_key' => $legacyKey,
        'size' => strlen($fullJson),
        'updated_at' => date('c'),
    ];
    $json = json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    if ($deleteManifest) data_store_shard_delete('manifest.json');
    return true;
}

// Total ukuran cache pecahan di hosting (untuk dashboard /status).
function data_store_cache_size()
{
    $total = 0;
    foreach ((array) glob(seo_cache_dir() . '/data_json_*.cache.json*') as $f) {
        if (is_file($f)) $total += (int) filesize($f);
    }
    return $total;
}
