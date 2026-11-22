<?php
// assessments_store.php — pecahan assessments.json.
//
// Layout S3 (prefix ukmipolmed/assessments_json/):
//   manifest.json
//   index.json                  # definisi asesmen tanpa responses + hitungan
//   responses/<sha1(id)>.json   # daftar respons satu asesmen
//
// Tujuan: satu asesmen dengan banyak responden tidak memaksa seluruh
// assessments.json (dan asesmen lain) ikut diunduh/ditulis.

require_once __DIR__ . '/store.php';

const ASSESSMENTS_STORE_VERSION = 1;

function assessments_store_prefix()
{
    return s3_prefix() . 'assessments_json/';
}

function assessments_store_key($rel)
{
    return assessments_store_prefix() . ltrim((string) $rel, '/');
}

function assessments_store_id_hash($id)
{
    return sha1((string) $id);
}

function assessments_store_cache_name($rel)
{
    return 'assessments_json_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $rel);
}

function assessments_store_cache_clear($rel)
{
    $name = assessments_store_cache_name($rel);
    @unlink(store_cache_file($name));
    @unlink(store_cache_file_gz($name));
    if (function_exists('store_memo_forget_key')) {
        store_memo_forget_key(assessments_store_key($rel));
    }
}

function assessments_store_shard_read($rel, $default = null, $ttl = STORE_TTL)
{
    return store_read_key(assessments_store_cache_name($rel), assessments_store_key($rel), $default, $ttl);
}

function assessments_store_shard_write($rel, $data, $lock = true)
{
    return store_save_key(assessments_store_cache_name($rel), assessments_store_key($rel), $data, $lock);
}

function assessments_store_shard_delete($rel)
{
    if (!s3_available()) return false;
    $ok = s3_delete_object(assessments_store_key($rel));
    assessments_store_cache_clear($rel);
    return $ok;
}

function assessments_store_manifest_read(bool $reset = false)
{
    static $m = false;
    if ($reset) {
        $m = false;
        return null;
    }
    if ($m !== false) return $m;
    $data = assessments_store_shard_read('manifest.json', null);
    return $m = (is_array($data) && (int) ($data['version'] ?? 0) >= 1) ? $data : null;
}

function assessments_store_is_sharded()
{
    static $sharded = null;
    if ($sharded !== null) return $sharded;
    $local = store_read_local(__DIR__ . '/assessments.json');
    if (empty($local['_sharded'])) return $sharded = false;
    return $sharded = assessments_store_manifest_read() !== null;
}

function assessments_store_pointer_write()
{
    $stub = [
        '_store' => 's3',
        '_sharded' => true,
        '_key' => assessments_store_key('manifest.json'),
        'version' => ASSESSMENTS_STORE_VERSION,
        'updated_at' => date('c'),
    ];
    $json = json_encode($stub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $path = __DIR__ . '/assessments.json';
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function assessments_store_index_read()
{
    $d = assessments_store_shard_read('index.json', []);
    return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
}

function assessments_store_index_entry(array $assessment): array
{
    $responses = isset($assessment['responses']) && is_array($assessment['responses'])
        ? array_values($assessment['responses'])
        : [];
    unset($assessment['responses']);
    $json = json_encode($responses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assessment['response_count'] = count($responses);
    $assessment['responses_bytes'] = $json === false ? 0 : strlen($json);
    return $assessment;
}

function assessments_store_shard_read_ex($rel, $default = null)
{
    return store_read_key_ex(assessments_store_cache_name($rel), assessments_store_key($rel), $default);
}

function assessments_store_responses_read_ex($id)
{
    $rel = 'responses/' . assessments_store_id_hash($id) . '.json';
    $res = assessments_store_shard_read_ex($rel, []);
    if (($res['status'] ?? '') !== 'ok') {
        return ['status' => ($res['status'] ?? 'error'), 'responses' => []];
    }
    $d = $res['data'];
    if (is_array($d) && isset($d['responses']) && is_array($d['responses'])) {
        return ['status' => 'ok', 'responses' => array_values($d['responses'])];
    }
    return ['status' => 'ok', 'responses' => is_array($d) ? array_values(array_filter($d, 'is_array')) : []];
}


function assessments_store_responses_write($id, array $responses)
{
    $rel = 'responses/' . assessments_store_id_hash($id) . '.json';
    return assessments_store_shard_write($rel, [
        'id' => (string) $id,
        'updated_at' => date('c'),
        'responses' => array_values($responses),
    ]);
}

function assessments_store_read_one($id)
{
    $id = (string) $id;
    if ($id === '') return null;
    $index = assessments_store_index_read();
    $definition = null;
    foreach ($index as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            $definition = $row;
            break;
        }
    }
    if ($definition === null) return null;
    $responses = assessments_store_responses_read_ex($id);
    if ($responses['status'] !== 'ok') return null;
    $definition['responses'] = $responses['responses'];
    return $definition;
}

function assessments_store_read_all(&$complete = null)
{
    $complete = true;
    $out = [];
    foreach (assessments_store_index_read() as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') continue;
        $responses = assessments_store_responses_read_ex($id);
        if ($responses['status'] !== 'ok') $complete = false;
        $row['responses'] = $responses['responses'];
        $out[] = $row;
    }
    return ['assessments' => $out];
}

function assessments_store_write_full(array $data)
{
    if (!s3_available()) return false;
    $assessments = isset($data['assessments']) && is_array($data['assessments'])
        ? array_values(array_filter($data['assessments'], 'is_array'))
        : [];

    $oldIndex = assessments_store_index_read();
    $oldIds = [];
    foreach ($oldIndex as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id !== '') $oldIds[$id] = true;
    }

    $index = [];
    $responsesById = [];
    $seen = [];
    foreach ($assessments as $a) {
        $id = trim((string) ($a['id'] ?? ''));
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        $responsesById[$id] = isset($a['responses']) && is_array($a['responses'])
            ? array_values($a['responses'])
            : [];
        $index[] = assessments_store_index_entry($a);
    }

    $ok = true;
    foreach ($responsesById as $id => $responses) {
        $rel = 'responses/' . assessments_store_id_hash($id) . '.json';
        $oldRes = assessments_store_shard_read_ex($rel, null);
        if (($oldRes['status'] ?? '') === 'error') {
            // Data di memori sudah lengkap (dijaga store_save_raw); kegagalan
            // baca lama tidak boleh diam-diam menghapus respons.
            $ok = false;
            continue;
        }
        $oldRaw = $oldRes['data'] ?? null;
        $oldResponses = is_array($oldRaw['responses'] ?? null) ? $oldRaw['responses'] : [];
        if (($oldRes['status'] ?? '') === 'ok' && $oldResponses === $responses) continue;
        if (!assessments_store_responses_write($id, $responses)) $ok = false;
    }
    if ($oldIndex !== $index && !assessments_store_shard_write('index.json', $index)) {
        $ok = false;
    }

    $oldManifest = assessments_store_manifest_read();
    $manifest = [
        'version' => ASSESSMENTS_STORE_VERSION,
        'generated_at' => date('c'),
        'assessments' => count($index),
        'responses' => array_sum(array_map('count', $responsesById)),
    ];
    if ($oldManifest !== $manifest && !assessments_store_shard_write('manifest.json', $manifest)) {
        $ok = false;
    }
    if (!$ok) return false;

    // Hapus respons yatim hanya setelah index + manifest sukses.
    foreach (array_keys($oldIds) as $id) {
        if (isset($responsesById[$id])) continue;
        assessments_store_shard_delete('responses/' . assessments_store_id_hash($id) . '.json');
    }

    assessments_store_pointer_write();
    $memo = &store_memo();
    $memo[__DIR__ . '/assessments.json'] = ['assessments' => $assessments];
    return true;
}

// Tulis satu asesmen (dipakai assessments_update) tanpa menyentuh asesmen lain.
function assessments_store_write_one(array $assessment)
{
    $id = trim((string) ($assessment['id'] ?? ''));
    if ($id === '') return false;

    $index = assessments_store_index_read();
    $newEntry = assessments_store_index_entry($assessment);
    $found = false;
    foreach ($index as $i => $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            $index[$i] = $newEntry;
            $found = true;
            break;
        }
    }
    if (!$found) return false;

    $responses = isset($assessment['responses']) && is_array($assessment['responses'])
        ? array_values($assessment['responses'])
        : [];
    $rel = 'responses/' . assessments_store_id_hash($id) . '.json';
    $oldRes = assessments_store_shard_read_ex($rel, null);
    if (($oldRes['status'] ?? '') === 'error') return false;
    $oldRaw = $oldRes['data'] ?? null;
    $oldResponses = is_array($oldRaw['responses'] ?? null) ? $oldRaw['responses'] : [];
    $ok = true;
    if (($oldRes['status'] ?? '') !== 'ok' || $oldResponses !== $responses) {
        if (!assessments_store_responses_write($id, $responses)) $ok = false;
    }
    $oldIndex = assessments_store_index_read();
    if ($oldIndex !== $index && !assessments_store_shard_write('index.json', $index)) {
        $ok = false;
    }
    $manifest = [
        'version' => ASSESSMENTS_STORE_VERSION,
        'generated_at' => date('c'),
        'assessments' => count($index),
        'responses' => array_sum(array_map(function ($row) {
            return max(0, (int) ($row['response_count'] ?? 0));
        }, $index)),
    ];
    if (!assessments_store_shard_write('manifest.json', $manifest)) $ok = false;

    if ($ok) {
        $memo = &store_memo();
        unset($memo[__DIR__ . '/assessments.json']);
    }
    return $ok;
}

function assessments_store_migrate()
{
    if (assessments_store_is_sharded()) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'already_sharded'];
    }
    $data = store_read_json(__DIR__ . '/assessments.json');
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'assessments_unreadable'];
    }
    if (!assessments_store_write_full($data)) {
        return ['ok' => false, 'error' => 'write_failed'];
    }
    @unlink(store_cache_file('assessments'));
    @unlink(store_cache_file_gz('assessments'));
    return [
        'ok' => true,
        'assessments' => count(assessments_store_index_read()),
        'manifest' => assessments_store_key('manifest.json'),
    ];
}

function assessments_store_rollback($deleteManifest = false)
{
    $complete = true;
    $data = assessments_store_read_all($complete);
    if (!is_array($data) || !$complete) return false;
    $fullJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($fullJson === false) return false;
    $debug = null;
    $legacyKey = store_key('assessments');
    if (s3_put_binary($fullJson, $legacyKey, 'application/json', $debug, false) === null) {
        return false;
    }
    store_cache_write('assessments', $legacyKey, $data);

    $path = __DIR__ . '/assessments.json';
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
    if ($deleteManifest) assessments_store_shard_delete('manifest.json');
    return true;
}

function assessments_store_cache_size()
{
    $total = 0;
    foreach ((array) glob(seo_cache_dir() . '/assessments_json_*.cache.json*') as $f) {
        if (is_file($f)) $total += (int) filesize($f);
    }
    return $total;
}
