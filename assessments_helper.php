<?php
// assessments_helper.php — store pretest/posttest (runtime, assessments.json).

function assessments_path()
{
    return __DIR__ . '/assessments.json';
}

function assessments_load()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $data = ['assessments' => []];
    $p = assessments_path();
    if (is_readable($p)) {
        $j = json_decode((string) @file_get_contents($p), true);
        if (is_array($j) && isset($j['assessments']) && is_array($j['assessments'])) {
            $data['assessments'] = array_values($j['assessments']);
        }
    }
    $cache = $data;
    return $cache;
}

function assessments_save($data)
{
    $enc = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($enc === false) return false;
    return @file_put_contents(assessments_path(), $enc) !== false;
}

function assessments_for_post($slug)
{
    $out = [];
    foreach (assessments_load()['assessments'] as $a) {
        if ((string) ($a['post_slug'] ?? '') === (string) $slug) $out[] = $a;
    }
    return $out;
}

function assessments_by_author($aid)
{
    $out = [];
    foreach (assessments_load()['assessments'] as $a) {
        if ((string) ($a['author_id'] ?? '') === (string) $aid) $out[] = $a;
    }
    return $out;
}

function assessment_find($id)
{
    foreach (assessments_load()['assessments'] as $a) {
        if ((string) ($a['id'] ?? '') === (string) $id) return $a;
    }
    return null;
}

function assessments_update($id, $mutator)
{
    $data = assessments_load();
    foreach ($data['assessments'] as $i => $a) {
        if ((string) ($a['id'] ?? '') === (string) $id) {
            $data['assessments'][$i] = $mutator($a);
            return assessments_save($data);
        }
    }
    return false;
}
