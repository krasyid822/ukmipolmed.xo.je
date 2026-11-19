<?php
require_once __DIR__ . '/store.php';
// assessments_helper.php — store pretest/posttest (runtime, assessments.json).

function assessments_path()
{
    return __DIR__ . '/assessments.json';
}

function assessments_load()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    if (function_exists('assessments_store_is_sharded') && assessments_store_is_sharded()) {
        return assessments_store_read_all();
    }
    $data = ['assessments' => []];
    $j = store_read_json(assessments_path());
    if (is_array($j) && isset($j['assessments']) && is_array($j['assessments'])) {
        $data['assessments'] = array_values($j['assessments']);
    }
    $cache = $data;
    return $cache;
}

function assessments_save($data)
{
    if (function_exists('assessments_store_is_sharded') && assessments_store_is_sharded()) {
        return assessments_store_write_full($data);
    }
    return store_save_json(assessments_path(), $data);
}

function assessments_for_post($slug)
{
    $out = [];
    $list = (function_exists('assessments_store_is_sharded') && assessments_store_is_sharded())
        ? assessments_store_index_read()
        : assessments_load()['assessments'];
    foreach ($list as $a) {
        if ((string) ($a['post_slug'] ?? '') === (string) $slug) $out[] = $a;
    }
    return $out;
}

function assessments_by_author($aid)
{
    $out = [];
    $list = (function_exists('assessments_store_is_sharded') && assessments_store_is_sharded())
        ? assessments_store_index_read()
        : assessments_load()['assessments'];
    foreach ($list as $a) {
        if ((string) ($a['author_id'] ?? '') === (string) $aid) $out[] = $a;
    }
    return $out;
}

function assessment_find($id)
{
    if (function_exists('assessments_store_is_sharded') && assessments_store_is_sharded()) {
        return assessments_store_read_one($id);
    }
    foreach (assessments_load()['assessments'] as $a) {
        if ((string) ($a['id'] ?? '') === (string) $id) return $a;
    }
    return null;
}

function assessments_update($id, $mutator)
{
    if (function_exists('assessments_store_is_sharded') && assessments_store_is_sharded()) {
        $a = assessments_store_read_one($id);
        if (!is_array($a)) return false;
        $updated = $mutator($a);
        if (!is_array($updated)) return false;
        return assessments_store_write_one($updated);
    }
    $data = assessments_load();
    foreach ($data['assessments'] as $i => $a) {
        if ((string) ($a['id'] ?? '') === (string) $id) {
            $data['assessments'][$i] = $mutator($a);
            return assessments_save($data);
        }
    }
    return false;
}
