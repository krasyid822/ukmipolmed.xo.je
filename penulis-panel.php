<?php
session_start();
require_once __DIR__ . '/authors_helper.php';
require_once __DIR__ . '/assessments_helper.php';
require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/s3_gc_helper.php';
require_once __DIR__ . '/store.php';

$configPath = __DIR__ . '/data.json';
$config = store_read_json($configPath);
if (!is_array($config)) $config = [];
$sessionVersion = (int) ($config['session_version'] ?? 1);
$isWriter = !empty($_SESSION['admin_logged_in'])
    && (($_SESSION['role'] ?? '') === 'writer')
    && (($_SESSION['session_version'] ?? 0) === $sessionVersion);
if (!$isWriter) {
    header('Location: /admin');
    exit;
}

$aid = (string) ($_SESSION['author_id'] ?? '');
$data = authors_load();
$meIdx = -1;
foreach ($data['authors'] as $i => $a) {
    if ((string) ($a['id'] ?? '') === $aid) {
        $meIdx = $i;
        break;
    }
}
if ($meIdx < 0) {
    session_destroy();
    header('Location: /admin');
    exit;
}
$me = $data['authors'][$meIdx];
$username = (string) ($me['username'] ?? '');
$meFeatures = (isset($me['features']) && is_array($me['features'])) ? $me['features'] : null;
$allowAssessment = $meFeatures === null ? true : in_array('assessment', $meFeatures, true);
$allowEmbed = $meFeatures === null ? true : in_array('embed', $meFeatures, true);

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin');
    exit;
}

function wp_slugify($s)
{
    $s = strtolower(trim((string) $s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? $s : ('post-' . substr(bin2hex(random_bytes(3)), 0, 6));
}

function wp_load_config($path)
{
    $j = store_read_json($path);
    return is_array($j) ? $j : [];
}

function wp_save_config($path, $cfg)
{
    return store_save_json($path, $cfg);
}

function wp_valid_cover_image($image)
{
    $image = trim((string) $image);
    if ($image === '') return false;
    if (preg_match('#^https?://\S+$#i', $image)) return true;
    if (preg_match('#^data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=\s]+$#i', $image)) return true;
    return false;
}

// Sidik jari sampul: hash isi + ukuran byte (dipakai untuk menolak gambar duplikat).
function wp_cover_fingerprint($image)
{
    $image = trim((string) $image);
    if ($image === '') return null;
    if (stripos($image, 'data:') === 0) {
        if (!preg_match('#^data:([a-z0-9.+-]+/[a-z0-9.+-]+);base64,(.*)$#is', $image, $m)) return null;
        $mime = strtolower($m[1]);
        $bin = base64_decode($m[2], true);
        if ($bin === false || $bin === '') return null;
        if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
            list($bin, $mime) = s3_optimize_image($bin, $mime);
        } elseif ($mime === 'image/svg+xml') {
            $bin = s3_sanitize_svg($bin);
        }
        return ['hash' => sha1($bin), 'size' => strlen($bin)];
    }
    if (preg_match('~uploads(?:/|%2F)([a-f0-9]{40})\.([a-z0-9]+)~i', rawurldecode($image), $m)) {
        return ['hash' => strtolower($m[1]), 'size' => null];
    }
    return ['hash' => sha1($image), 'size' => null];
}

function wp_post_cover_fingerprint($post)
{
    if (!is_array($post)) return null;
    $hash = trim((string) ($post['image_hash'] ?? ''));
    if ($hash !== '') {
        $size = isset($post['image_size']) ? (int) $post['image_size'] : null;
        return ['hash' => $hash, 'size' => ($size !== null && $size > 0) ? $size : null];
    }
    return wp_cover_fingerprint((string) ($post['image'] ?? ''));
}

// Cari postingan lain yang memakai gambar sama (hash sama atau ukuran file sama).
function wp_find_duplicate_cover($posts, $fp, $editSlug, $aid)
{
    if (!is_array($fp) || empty($fp['hash'])) return null;
    foreach ((array) $posts as $p) {
        if (!is_array($p)) continue;
        $isSelf = $editSlug !== ''
            && (string) ($p['slug'] ?? '') === $editSlug
            && (string) ($p['author_id'] ?? '') === $aid;
        if ($isSelf) continue;
        if (trim((string) ($p['image'] ?? '')) === '') continue;
        $pfp = wp_post_cover_fingerprint($p);
        if (!is_array($pfp)) continue;
        $hashDup = !empty($pfp['hash']) && hash_equals($fp['hash'], $pfp['hash']);
        $sizeDup = isset($fp['size'], $pfp['size']) && $fp['size'] > 0 && $fp['size'] === $pfp['size'];
        if ($hashDup || $sizeDup) {
            $t = trim((string) ($p['title'] ?? ''));
            return $t !== '' ? $t : 'tanpa judul';
        }
    }
    return null;
}

$msg = '';
$err = '';

// Profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $data['authors'][$meIdx]['pen_name'] = trim((string) ($_POST['pen_name'] ?? ''));
    $data['authors'][$meIdx]['bio'] = trim((string) ($_POST['bio'] ?? ''));
    $oldAvatar = (string) ($data['authors'][$meIdx]['avatar'] ?? '');
    $av = trim((string) ($_POST['avatar'] ?? ''));
    if (stripos($av, 'data:') === 0 && function_exists('s3_put_data_uri')) { $u = s3_put_data_uri($av); if ($u) $av = $u; }
    $data['authors'][$meIdx]['avatar'] = $av;
    $rawSoc = (string) ($_POST['socials'] ?? '');
    $socArr = [];
    foreach (preg_split('/\r?\n/', $rawSoc) as $line) {
        $line = trim($line);
        if ($line !== '') $socArr[] = $line;
    }
    $data['authors'][$meIdx]['socials'] = $socArr;
    $data['authors'][$meIdx]['social'] = $socArr[0] ?? '';
    if (authors_save($data)) {
        $me = $data['authors'][$meIdx];
        $msg = 'Profil disimpan.';
        s3_gc_prune_keys([$oldAvatar]);
    } else {
        $err = 'Gagal menyimpan profil.';
    }
}

// Simpan postingan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post'])) {
    $cfg = wp_load_config($configPath);
    if (!isset($cfg['posts']) || !is_array($cfg['posts'])) $cfg['posts'] = [];
    $editSlug = trim((string) ($_POST['orig_slug'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $slugIn = trim((string) ($_POST['slug'] ?? ''));
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $body = (string) ($_POST['body'] ?? '');
    if ($summary === '' && $body !== '') {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        $summary = substr($plain, 0, 180);
    }
    $image = trim((string) ($_POST['image'] ?? ''));
    $imageFp = wp_cover_fingerprint($image);
    if (stripos($image, 'data:') === 0 && function_exists('s3_put_data_uri')) { $u = s3_put_data_uri($image); if ($u) $image = $u; }
    $focalX = isset($_POST['focal_x']) ? max(0, min(100, (float) $_POST['focal_x'])) : 50;
    $focalY = isset($_POST['focal_y']) ? max(0, min(100, (float) $_POST['focal_y'])) : 50;
    $gdriveIds = $_POST['gdrive_id'] ?? [];
    $gdriveViews = $_POST['gdrive_view'] ?? [];
    $gdriveFolders = [];
    if (is_array($gdriveIds)) {
        foreach ($gdriveIds as $k => $gid) {
            $gid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $gid);
            if ($gid === '') continue;
            $gv = (is_array($gdriveViews) && isset($gdriveViews[$k]) && $gdriveViews[$k] === 'list') ? 'list' : 'grid';
            $gdriveFolders[] = ['id' => $gid, 'view' => $gv];
        }
    }
    $gdriveFolder = $gdriveFolders[0]['id'] ?? '';
    $gdriveView = $gdriveFolders[0]['view'] ?? 'grid';
    $igFix = !empty($_POST['ig_embed_fix']);
    $embedsRaw = $_POST['embeds'] ?? '';
    $embeds = [];
    if (is_array($embedsRaw)) {
        foreach ($embedsRaw as $e) { $e = trim((string) $e); if ($e !== '') $embeds[] = $e; }
    } else {
        foreach (preg_split('/\r?\n/', (string) $embedsRaw) as $ln) { $ln = trim($ln); if ($ln !== '') $embeds[] = $ln; }
    }
    $embedEnabled = !empty($embeds);
    if ($title === '') {
        $err = 'Judul wajib diisi.';
    } elseif (!wp_valid_cover_image($image)) {
        $err = 'Gambar sampul wajib diunggah. Postingan tanpa gambar sampul tidak diizinkan.';
    } elseif (($dupTitle = wp_find_duplicate_cover($cfg['posts'], $imageFp, $editSlug, $aid)) !== null) {
        $err = 'Gambar sampul sudah dipakai pada postingan lain ("' . $dupTitle . '"). Gunakan gambar yang berbeda.';
    } else {
        $slug = $slugIn !== '' ? wp_slugify($slugIn) : ($editSlug !== '' ? $editSlug : wp_slugify($title));
        // keunikan slug
        $taken = [];
        foreach ($cfg['posts'] as $p) {
            $ps = (string) ($p['slug'] ?? '');
            if ($ps !== '' && !($editSlug !== '' && $ps === $editSlug)) $taken[$ps] = true;
        }
        if (isset($taken[$slug])) {
            $base = $slug; $n = 2;
            while (isset($taken[$slug])) { $slug = $base . '-' . $n; $n++; }
        }
        $now = date('c');
        $found = false;
        $oldRefs = [];
        foreach ($cfg['posts'] as $i => $p) {
            if ($editSlug !== '' && (string) ($p['slug'] ?? '') === $editSlug && (string) ($p['author_id'] ?? '') === $aid) {
                s3_gc_collect_refs($p, $oldRefs);
                $cfg['posts'][$i]['title'] = $title;
                $cfg['posts'][$i]['slug'] = $slug;
                $cfg['posts'][$i]['summary'] = $summary;
                $cfg['posts'][$i]['body'] = $body;
                $cfg['posts'][$i]['image'] = $image;
                $cfg['posts'][$i]['image_hash'] = $imageFp ? $imageFp['hash'] : '';
                $cfg['posts'][$i]['image_size'] = $imageFp ? $imageFp['size'] : null;
                $cfg['posts'][$i]['focal_x'] = $focalX;
                $cfg['posts'][$i]['focal_y'] = $focalY;
                if ($allowEmbed) {
                    $cfg['posts'][$i]['gdrive_folder'] = $gdriveFolder;
                    $cfg['posts'][$i]['gdrive_view'] = $gdriveView;
                    $cfg['posts'][$i]['gdrive_folders'] = $gdriveFolders;
                    $cfg['posts'][$i]['ig_embed_fix'] = $igFix;
                    $cfg['posts'][$i]['embeds'] = $embeds;
                    $cfg['posts'][$i]['embed_enabled'] = $embedEnabled;
                }
                $cfg['posts'][$i]['author_id'] = $aid;
                $cfg['posts'][$i]['updated_at'] = $now;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $cfg['posts'][] = [
                'title' => $title,
                'slug' => $slug,
                'summary' => $summary,
                'body' => $body,
                'image' => $image,
                'image_hash' => $imageFp ? $imageFp['hash'] : '',
                'image_size' => $imageFp ? $imageFp['size'] : null,
                'focal_x' => $focalX,
                'focal_y' => $focalY,
                'author_id' => $aid,
                'gdrive_folder' => $allowEmbed ? $gdriveFolder : '',
                'gdrive_view' => $allowEmbed ? $gdriveView : 'grid',
                'gdrive_folders' => $allowEmbed ? $gdriveFolders : [],
                'ig_embed_fix' => $allowEmbed ? $igFix : false,
                'embeds' => $allowEmbed ? $embeds : [],
                'embed_enabled' => $allowEmbed ? $embedEnabled : false,
                'embed_tokens_v2' => true,
                'created_at' => $now,
                'updated_at' => $now,
                'comments' => [],
            ];
        }
        if ($allowAssessment && isset($_POST['pa_add'])) {
            $paTitle = $title;
            if (true) {
                $paType = (($_POST['pa_type'] ?? 'pretest') === 'posttest') ? 'posttest' : 'pretest';
                $paGrading = (($_POST['pa_grading'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
                $paQs = [];
                foreach (preg_split('/\r?\n/', (string) ($_POST['pa_questions'] ?? '')) as $ln) {
                    $ln = trim($ln);
                    if ($ln === '') continue;
                    $parts = array_map('trim', explode('|', $ln));
                    if (count($parts) < 3) continue;
                    $c = (int) array_pop($parts);
                    $q = array_shift($parts);
                    $opts = array_values(array_filter($parts, fn($x) => $x !== ''));
                    $ci = $c - 1;
                    if ($q === '' || count($opts) < 2 || $ci < 0 || $ci >= count($opts)) continue;
                    $paQs[] = ['q' => $q, 'options' => $opts, 'correct' => $ci];
                }
                $paJson = (string) ($_POST['pa_questions_json'] ?? '');
                if ($paJson !== '') {
                    $pjq = json_decode($paJson, true);
                    if (is_array($pjq)) {
                        $pp2 = [];
                        foreach ($pjq as $row) {
                            if (!is_array($row)) continue;
                            $qq = trim((string) ($row['q'] ?? ''));
                            $opts = $row['options'] ?? null;
                            $ci = isset($row['correct']) ? (int) $row['correct'] : -1;
                            if ($qq === '' || !is_array($opts)) continue;
                            $opts = array_values(array_filter(array_map('trim', $opts), fn($x) => $x !== ''));
                            if (count($opts) < 2 || $ci < 0 || $ci >= count($opts)) continue;
                            $pp2[] = ['q' => $qq, 'options' => $opts, 'correct' => $ci];
                        }
                        if (!empty($pp2)) $paQs = $pp2;
                    }
                }
                $paCellsRaw = (string) ($_POST['pa_answer_cells'] ?? '');
                $paCells = [];
                if ($paCellsRaw !== '') {
                    $pcj = json_decode($paCellsRaw, true);
                    if (is_array($pcj)) {
                        foreach ($pcj as $cc) {
                            if (!is_array($cc)) continue;
                            $paCells[] = ['s' => (string) ($cc['s'] ?? ''), 'r' => (int) ($cc['r'] ?? 0), 'c' => (int) ($cc['c'] ?? 0), 'key' => trim((string) ($cc['key'] ?? ''))];
                        }
                    }
                }
                $paKeyRaw = (string) ($_POST['pa_key_cells'] ?? '');
                $paKeyCells = [];
                if ($paKeyRaw !== '') {
                    $pkj = json_decode($paKeyRaw, true);
                    if (is_array($pkj)) {
                        foreach ($pkj as $kc) {
                            if (!is_array($kc)) continue;
                            $addr = trim((string) ($kc['addr'] ?? ''));
                            if ($addr === '') continue;
                            $paKeyCells[] = ['s' => (string) ($kc['s'] ?? ''), 'addr' => $addr, 'key' => trim((string) ($kc['key'] ?? ''))];
                        }
                    }
                }
                $paFileKey = '';
                $paCleanB64 = (string) ($_POST['pa_clean_xlsx'] ?? '');
                if ($paCleanB64 !== '' && function_exists('s3_put_binary')) {
                    $pbin = base64_decode($paCleanB64, true);
                    if ($pbin !== false && $pbin !== '') {
                        $pobj = s3_prefix() . 'assessments/' . bin2hex(random_bytes(6)) . '.xlsx';
                        if (s3_put_binary($pbin, $pobj, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) $paFileKey = $pobj;
                    }
                }
                if (!empty($paQs) || !empty($paCells) || !empty($paKeyCells)) {
                    $paTask = (string) ($_POST['pa_task_image'] ?? '');
                    if (stripos($paTask, 'data:') === 0 && function_exists('s3_put_data_uri')) { $tu = s3_put_data_uri($paTask); if ($tu) $paTask = $tu; }
                    $ad = assessments_load();
                    $ad['assessments'][] = [
                        'id' => bin2hex(random_bytes(6)),
                        'post_slug' => $slug,
                        'author_id' => $aid,
                        'title' => $paTitle,
                        'type' => $paType,
                        'grading' => $paGrading,
                        'questions' => $paQs,
                        'answer_cells' => $paCells,
                        'key_cells' => $paKeyCells,
                        'file_key' => $paFileKey,
                        'task_image' => $paTask,
                        'responses' => [],
                        'created_at' => date('c'),
                    ];
                    assessments_save($ad);
                }
            }
        }
        if (wp_save_config($configPath, $cfg)) {
            if (!empty($oldRefs)) s3_gc_prune_keys(array_keys($oldRefs));
            header('Location: /penulis-panel?saved=1');
            exit;
        }
        $err = 'Gagal menyimpan postingan.';
    }
}

// Hapus postingan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post'])) {
    $delSlug = trim((string) $_POST['delete_post']);
    $cfg = wp_load_config($configPath);
    $before = count($cfg['posts'] ?? []);
    $oldRefs = [];
    $cfg['posts'] = array_values(array_filter($cfg['posts'] ?? [], function ($p) use ($delSlug, $aid, &$oldRefs) {
        $match = (string) ($p['slug'] ?? '') === $delSlug && (string) ($p['author_id'] ?? '') === $aid;
        if ($match) s3_gc_collect_refs($p, $oldRefs);
        return !$match;
    }));
    if (count($cfg['posts']) < $before && wp_save_config($configPath, $cfg)) {
        if (!empty($oldRefs)) s3_gc_prune_keys(array_keys($oldRefs));
        header('Location: /penulis-panel?deleted=1');
        exit;
    }
    $err = 'Gagal menghapus postingan.';
}

// Buat asesmen (pretest/posttest)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assessment']) && $allowAssessment) {
    $aTitle = trim((string) ($_POST['a_title'] ?? ''));
    $aType = (($_POST['a_type'] ?? 'pretest') === 'posttest') ? 'posttest' : 'pretest';
    $aGrading = (($_POST['a_grading'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
    $aPost = trim((string) ($_POST['a_post'] ?? ''));
    $rawQ = (string) ($_POST['a_questions'] ?? '');
    $questions = [];
    foreach (preg_split('/\r?\n/', $rawQ) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) continue;
        $correct = (int) array_pop($parts);
        $q = array_shift($parts);
        $options = array_values(array_filter($parts, fn($x) => $x !== ''));
        $ci = $correct - 1;
        if ($q === '' || count($options) < 2 || $ci < 0 || $ci >= count($options)) continue;
        $questions[] = ['q' => $q, 'options' => $options, 'correct' => $ci];
    }
    $qJson = (string) ($_POST['a_questions_json'] ?? '');
    if ($qJson !== '') {
        $jq = json_decode($qJson, true);
        if (is_array($jq)) {
            $parsed = [];
            foreach ($jq as $row) {
                if (!is_array($row)) continue;
                $qq = trim((string) ($row['q'] ?? ''));
                $opts = $row['options'] ?? null;
                $ci = isset($row['correct']) ? (int) $row['correct'] : -1;
                if ($qq === '' || !is_array($opts)) continue;
                $opts = array_values(array_filter(array_map('trim', $opts), fn($x) => $x !== ''));
                if (count($opts) < 2 || $ci < 0 || $ci >= count($opts)) continue;
                $parsed[] = ['q' => $qq, 'options' => $opts, 'correct' => $ci];
            }
            if (!empty($parsed)) $questions = $parsed;
        }
    }
    $cellsRaw = (string) ($_POST['a_answer_cells'] ?? '');
    $answerCells = [];
    if ($cellsRaw !== '') {
        $cj = json_decode($cellsRaw, true);
        if (is_array($cj)) {
            foreach ($cj as $cc) {
                if (!is_array($cc)) continue;
                $answerCells[] = ['s' => (string) ($cc['s'] ?? ''), 'r' => (int) ($cc['r'] ?? 0), 'c' => (int) ($cc['c'] ?? 0), 'key' => trim((string) ($cc['key'] ?? ''))];
            }
        }
    }
    $keyCellsRaw = (string) ($_POST['a_key_cells'] ?? '');
    $keyCells = [];
    if ($keyCellsRaw !== '') {
        $kj = json_decode($keyCellsRaw, true);
        if (is_array($kj)) {
            foreach ($kj as $kc) {
                if (!is_array($kc)) continue;
                $addr = trim((string) ($kc['addr'] ?? ''));
                if ($addr === '') continue;
                $keyCells[] = ['s' => (string) ($kc['s'] ?? ''), 'addr' => $addr, 'key' => trim((string) ($kc['key'] ?? ''))];
            }
        }
    }
    $fileKey = '';
    $cleanB64 = (string) ($_POST['a_clean_xlsx'] ?? '');
    if ($cleanB64 !== '' && function_exists('s3_put_binary')) {
        $bin = base64_decode($cleanB64, true);
        if ($bin !== false && $bin !== '') {
            $objKey = s3_prefix() . 'assessments/' . bin2hex(random_bytes(6)) . '.xlsx';
            if (s3_put_binary($bin, $objKey, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) $fileKey = $objKey;
        }
    }
    $taskImg = (string) ($_POST['a_task_image'] ?? '');
    if (stripos($taskImg, 'data:') === 0 && function_exists('s3_put_data_uri')) {
        $tu = s3_put_data_uri($taskImg);
        if ($tu) $taskImg = $tu;
    }
    $ownsPost = false;
    $cfgNow = wp_load_config($configPath);
    if (!empty($cfgNow['posts'])) {
        foreach ($cfgNow['posts'] as $pp) {
            if ((string) ($pp['slug'] ?? '') === $aPost && (string) ($pp['author_id'] ?? '') === $aid) { $ownsPost = true; break; }
        }
    }
    if ($aTitle === '' || (empty($questions) && empty($answerCells) && empty($keyCells))) {
        $err = 'Judul & minimal 1 soal/isi/Excel-kunci wajib.';
    } elseif ($aPost !== '' && !$ownsPost) {
        $err = 'Postingan tidak valid / bukan milik Anda.';
    } else {
        $d = assessments_load();
        $d['assessments'][] = [
            'id' => bin2hex(random_bytes(6)),
            'post_slug' => $aPost,
            'author_id' => $aid,
            'title' => $aTitle,
            'type' => $aType,
            'grading' => $aGrading,
            'questions' => $questions,
            'answer_cells' => $answerCells,
            'key_cells' => $keyCells,
            'file_key' => $fileKey,
            'task_image' => $taskImg,
            'responses' => [],
            'created_at' => date('c'),
        ];
        if (assessments_save($d)) $msg = 'Asesmen dibuat.'; else $err = 'Gagal menyimpan asesmen.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assessment'])) {
    $delAid = trim((string) $_POST['delete_assessment']);
    $as = assessment_find($delAid);
    if ($as && (string) ($as['author_id'] ?? '') === $aid) {
        $oldRefs = [];
        s3_gc_collect_refs($as, $oldRefs);
        $d = assessments_load();
        $d['assessments'] = array_values(array_filter($d['assessments'], fn($x) => (string) ($x['id'] ?? '') !== $delAid));
        if (assessments_save($d)) {
            if (!empty($oldRefs)) s3_gc_prune_keys(array_keys($oldRefs));
            $msg = 'Asesmen dihapus.';
        } else {
            $err = 'Gagal menghapus asesmen.';
        }
    } else {
        $err = 'Asesmen tidak ditemukan / bukan milik Anda.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['audit_aid'], $_POST['audit_rid'])) {
    $auAid = trim((string) $_POST['audit_aid']);
    $auRid = trim((string) $_POST['audit_rid']);
    $as = assessment_find($auAid);
    if ($as && (string) ($as['author_id'] ?? '') === $aid) {
        $total = count($as['questions'] ?? []);
        $auScore = max(0, min($total, (int) ($_POST['audit_score'] ?? 0)));
        assessments_update($auAid, function ($x) use ($auRid, $auScore) {
            $rs = $x['responses'] ?? [];
            foreach ($rs as $k => $r) {
                if ((string) ($r['id'] ?? '') === $auRid) { $rs[$k]['audited'] = true; $rs[$k]['audit_score'] = $auScore; }
            }
            $x['responses'] = $rs;
            return $x;
        });
        $msg = 'Skor audit disimpan.';
    } else {
        $err = 'Tidak berhak mengaudit asesmen ini.';
    }
}

if (isset($_GET['saved'])) $msg = 'Postingan disimpan.';
if (isset($_GET['deleted'])) $msg = 'Postingan dihapus.';

$config = wp_load_config($configPath);
$all = (!empty($config['posts']) && is_array($config['posts'])) ? $config['posts'] : [];
$myPosts = [];
foreach ($all as $p) {
    if (trim((string) ($p['author_id'] ?? '')) === $aid) $myPosts[] = $p;
}
usort($myPosts, fn($x, $y) => strcmp((string) ($y['created_at'] ?? ''), (string) ($x['created_at'] ?? '')));

$editing = null;
$editSlug = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($editSlug !== '') {
    foreach ($myPosts as $p) {
        if ((string) ($p['slug'] ?? '') === $editSlug) { $editing = $p; break; }
    }
}
$showForm = ($editing !== null) || isset($_GET['new']);
$myAssessments = assessments_by_author($aid);
$respAid = isset($_GET['responses']) ? trim((string) $_GET['responses']) : '';
$respAs = $respAid !== '' ? assessment_find($respAid) : null;
if ($respAs && (string) ($respAs['author_id'] ?? '') !== $aid) $respAs = null;
$profileView = isset($_GET['profile']);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel Penulis – <?php echo htmlspecialchars((string) ($me['pen_name'] ?: $username), ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="/logo-ukmi.png">
<script src="/components/ui-modal.js"></script>
<?php require __DIR__ . '/components/penulis-panel-styles.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/turndown@7.2.0/dist/turndown.js"></script>
<script src="https://cdn.jsdelivr.net/npm/turndown-plugin-gfm@1.0.2/dist/turndown-plugin-gfm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
</head>
<body>
<?php require __DIR__ . '/components/loading-overlay.php'; ?>
<div class="wrap">
  <div class="row" style="justify-content:space-between;margin-top:0;">
    <div><h1>Panel Penulis</h1><div class="muted">@<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="row" style="margin:0;">
      <a class="btn" href="?profile=1">Pengaturan Profil</a>
      <a class="btn" href="/penulis/<?php echo rawurlencode($username); ?>" target="_blank" rel="noopener">Lihat profil</a>
      <a class="btn" href="?logout=1">Keluar</a>
    </div>
  </div>

  <?php if ($msg !== ''): ?><p class="ok"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="err"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

  <?php if ($profileView): ?>
    <div class="card">
      <div class="row" style="justify-content:space-between;margin-top:0;">
        <h2 style="font-size:15px;margin:0;">Pengaturan Profil</h2>
        <a class="btn" href="/penulis-panel">Kembali</a>
      </div>
      <form method="post">
        <input type="hidden" name="save_profile" value="1">
        <div class="grid2">
          <div><label for="pen_name">Nama pena</label><input type="text" id="pen_name" name="pen_name" value="<?php echo htmlspecialchars((string) ($me['pen_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
          <div><label for="avatar">Avatar URL</label><input type="text" id="avatar" name="avatar" value="<?php echo htmlspecialchars((string) ($me['avatar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...jpg"></div>
        </div>
        <label for="bio">Bio</label>
        <textarea id="bio" name="bio" rows="2"><?php echo htmlspecialchars((string) ($me['bio'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        <?php $meSocials = (!empty($me['socials']) && is_array($me['socials'])) ? $me['socials'] : (!empty($me['social']) ? [$me['social']] : []); ?>
        <label for="socials" style="display:block;font-size:14px;color:var(--text);font-weight:600;margin:12px 0 2px;">Link Sosial Media</label>
        <p class="muted" style="margin:0 0 6px;">Pisahkan dengan baris, satu akun satu baris.</p>
        <textarea id="socials" name="socials" rows="3" placeholder="https://instagram.com/akun&#10;https://youtube.com/@akun"><?php echo htmlspecialchars(implode("\n", $meSocials), ENT_QUOTES, 'UTF-8'); ?></textarea>
        <div class="row"><button type="submit">Simpan profil</button></div>
      </form>
    </div>
  <?php elseif ($showForm): ?>
    <div class="card">
      <h2 style="font-size:15px;margin:0 0 6px;"><?php echo $editing ? 'Edit postingan' : 'Postingan baru'; ?></h2>
      <form method="post" id="post_form">
        <input type="hidden" name="save_post" value="1">
        <input type="hidden" name="orig_slug" value="<?php echo htmlspecialchars((string) ($editing['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <label for="title">Judul</label>
        <input type="text" id="title" name="title" required value="<?php echo htmlspecialchars((string) ($editing['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <label for="body">Konten (Markdown)</label>
        <textarea id="body" name="body" rows="14"><?php echo htmlspecialchars((string) ($editing['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        <div class="row" style="margin-top:4px;">
          <label class="btn" style="margin:0;cursor:pointer;">Upload file .md / .docx<input type="file" id="body_md_file" accept=".md,.markdown,.txt,.docx" style="display:none;"></label>
          <button type="button" id="md_preview_btn" class="btn" style="margin:0;">Preview</button>
          <span id="body_info" class="muted" style="font-size:12px;"></span>
        </div>
        <div id="md_preview" style="display:none;margin-top:8px;padding:12px;border:1px solid var(--stroke);border-radius:10px;background:rgba(255,255,255,0.02);"></div>

        <label>Gambar sampul (wajib, tidak boleh sama dengan postingan lain)</label>
        <input type="hidden" name="image" id="image" value="<?php echo htmlspecialchars((string) ($editing['image'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="row" style="margin-top:4px;">
          <label class="btn" style="margin:0;cursor:pointer;">Upload gambar<input type="file" id="cover_file" accept="image/*" style="display:none;"></label>
          <input type="text" id="cover_paste" placeholder="atau paste URL https://... / data:image/...;base64,..." style="flex:1;min-width:200px;">
        </div>
        <div id="cover_info" class="muted" style="font-size:12px;margin-top:4px;"></div>

        <label>Titik fokus sampul (klik gambar untuk menetapkan)</label>
        <input type="hidden" name="focal_x" id="focal_x" value="<?php echo (int) ($editing['focal_x'] ?? 50); ?>">
        <input type="hidden" name="focal_y" id="focal_y" value="<?php echo (int) ($editing['focal_y'] ?? 50); ?>">
        <div id="cover_pick" style="position:relative;width:100%;max-width:380px;margin-top:6px;display:none;cursor:crosshair;border:1px solid var(--stroke);border-radius:10px;overflow:hidden;">
          <img id="cover_pick_img" alt="Pratinjau titik fokus sampul" style="display:block;width:100%;height:auto;">
          <span id="cover_pick_marker" style="position:absolute;width:18px;height:18px;border-radius:50%;border:2px solid #fff;background:rgba(56,189,248,.75);transform:translate(-50%,-50%);pointer-events:none;box-shadow:0 0 0 2px rgba(0,0,0,.45);"></span>
        </div>

        <?php $__postAssess = $editing ? assessments_for_post((string) ($editing['slug'] ?? '')) : []; ?>
        <?php if (!empty($__postAssess)): ?>
          <div style="margin-top:12px;border-top:1px dashed var(--stroke);padding-top:10px;">
            <div class="muted" style="font-size:12px;margin-bottom:6px;">Pretest/Posttest yang sudah ada:</div>
            <?php foreach ($__postAssess as $as): ?>
              <div class="row" style="margin:4px 0;">
                <span style="font-size:13px;"><?php echo htmlspecialchars((string) $as['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                <a class="btn" href="?responses=<?php echo rawurlencode((string) $as['id']); ?>">Responden</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($allowAssessment): ?>
        <div style="margin-top:12px;border-top:1px dashed var(--stroke);padding-top:10px;">
          <label for="pa_xlsx">File Excel tugas &amp; kunci (beri Fill HITAM pada sel kunci)</label>
          <input type="file" id="pa_xlsx" class="pa_xlsx" accept=".xlsx,.xls,.csv" style="margin-top:6px;">
          <input type="hidden" name="pa_add" value="1">
          <input type="hidden" name="pa_key_cells" class="pa_key_cells" value="">
          <input type="hidden" name="pa_clean_xlsx" class="pa_clean_xlsx" value="">
          <div class="pa_task_preview" style="margin-top:8px;"></div>
        </div>
        <?php endif; ?>
        <?php if ($allowEmbed): ?>
        <div style="margin-top:12px;border-top:1px dashed var(--stroke);padding-top:10px;">
          <div class="muted" style="font-size:12px;margin-bottom:6px;">Embed Master</div>
          <label>Google Drive Folder (bisa lebih dari satu) — token [[GDRIVE0]], [[GDRIVE1]], ...</label>
          <div id="gdrive_list">
            <?php $__gd0 = (array) ($editing['gdrive_folders'] ?? []); if (empty($__gd0) && !empty($editing['gdrive_folder'])) { $__gd0 = [['id' => $editing['gdrive_folder'], 'view' => $editing['gdrive_view'] ?? 'grid']]; } if (empty($__gd0)) { $__gd0 = [['id' => '', 'view' => 'grid']]; } foreach ($__gd0 as $__gd): ?>
            <div class="gdrive-item" style="display:flex; gap:8px; align-items:center; margin-top:6px;">
              <input type="text" name="gdrive_id[]" value="<?php echo htmlspecialchars((string) ($__gd['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Folder ID" style="flex:1;">
              <select name="gdrive_view[]" style="width:110px;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text);">
                <option value="grid" <?php echo (($__gd['view'] ?? 'grid') === 'grid') ? 'selected' : ''; ?>>Grid</option>
                <option value="list" <?php echo (($__gd['view'] ?? 'grid') === 'list') ? 'selected' : ''; ?>>List</option>
              </select>
              <button type="button" class="btn danger remove-gdrive" style="margin:0;">Hapus</button>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" id="add-gdrive" class="btn" style="margin-top:6px;">+ Tambah GDrive</button>

          <label style="margin-top:10px;">Embed HTML (bisa lebih dari satu) — token [[EMBED0]], [[EMBED1]], ...</label>
          <div id="embed_list">
            <?php $__emb0 = (array) ($editing['embeds'] ?? []); if (empty($__emb0)) $__emb0 = ['']; foreach ($__emb0 as $__emb): ?>
            <div class="embed-item" style="display:flex; gap:8px; align-items:flex-start; margin-top:6px;">
              <textarea name="embeds[]" rows="3" style="flex:1;" placeholder="<iframe ...></iframe>"><?php echo htmlspecialchars((string) $__emb, ENT_QUOTES, 'UTF-8'); ?></textarea>
              <button type="button" class="btn danger remove-embed" style="margin:0;">Hapus</button>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" id="add-embed" class="btn" style="margin-top:6px;">+ Tambah embed</button>
          <p class="muted" style="font-size:12px;margin:6px 0 0;">Tempatkan [[EMBED0]]/[[GDRIVE0]] di konten sesuai posisi. Tanpa token → muncul di akhir.</p>
          <label style="display:flex;align-items:center;gap:8px;margin-top:8px;"><input type="checkbox" name="ig_embed_fix" value="1" <?php echo !empty($editing['ig_embed_fix']) ? 'checked' : ''; ?>> Perbaiki layout embed Instagram</label>
        </div>
        <?php endif; ?>
        <div class="row">
          <button type="submit">Simpan</button>
          <a class="btn danger" href="/penulis-panel">Batal</a>
        </div>
      </form>
    </div>
  <?php else: ?>
    <?php if ($respAs): ?>
      <div class="card">
        <div class="row" style="justify-content:space-between;margin-top:0;">
          <h2 style="font-size:15px;margin:0;">Responden – <?php echo htmlspecialchars((string) $respAs['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
          <a class="btn" href="/penulis-panel">Kembali</a>
        </div>
        <?php
        $rs = $respAs['responses'] ?? [];
        $qTotal = !empty($respAs['key_cells']) ? count($respAs['key_cells']) : (!empty($respAs['answer_cells']) ? count($respAs['answer_cells']) : count($respAs['questions'] ?? []));
        ?>
        <input type="text" id="resp_search" placeholder="Cari nama / identitas..." style="width:100%;margin:10px 0;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text);" oninput="(function(v){v=v.toLowerCase();document.querySelectorAll('.resp-item').forEach(function(el){var t=(el.getAttribute('data-s')||'');el.style.display=t.indexOf(v)>=0?'':'none';});})(this.value);">
        <?php if (empty($rs)): ?>
          <p class="muted">Belum ada responden.</p>
        <?php else: foreach ($rs as $r): ?>
          <div class="pcard resp-item" data-s="<?php echo htmlspecialchars(strtolower((string) ($r['name'] ?? '') . ' ' . (string) ($r['ident'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
            <h3><?php echo htmlspecialchars((string) (($r['name'] ?? '') !== '' ? $r['name'] : '(tanpa nama)'), ENT_QUOTES, 'UTF-8'); ?> <span class="muted"><?php echo htmlspecialchars((string) ($r['ident'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></h3>
            <?php $sc = !empty($r['audited']) ? $r['audit_score'] : ($r['score'] ?? null); ?>
            <div style="font-size:16px;margin-top:2px;"><strong>Skor: <?php echo $sc === null ? 'menunggu audit' : ((int) $sc . '/' . $qTotal); ?></strong> <span class="muted" style="font-size:13px;">· <?php echo htmlspecialchars((string) ($r['mode'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($r['submitted_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php if (!empty($r['manual_count'])): ?>
              <div style="margin-top:4px;font-size:13px;color:#fca5a5;">⚠ Pelanggaran: <?php echo (int) $r['manual_count']; ?> jawaban angka diketik manual (tanpa rumus)<?php if (!empty($r['manual_answers']) && is_array($r['manual_answers'])): ?> — <?php echo htmlspecialchars(implode(', ', array_map('strval', $r['manual_answers'])), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
            <?php endif; ?>
            <?php if (($respAs['grading'] ?? 'auto') === 'manual'): ?>
              <form method="post" class="row" style="margin-top:8px;">
                <input type="hidden" name="audit_aid" value="<?php echo htmlspecialchars((string) $respAs['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="audit_rid" value="<?php echo htmlspecialchars((string) ($r['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="audit_score" value="<?php echo $sc === null ? '' : (int) $sc; ?>" placeholder="skor 0-<?php echo (int) $qTotal; ?>" style="max-width:140px;">
                <button type="submit" class="danger">Simpan skor</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php endif; ?>
    <?php if (!$respAs): ?>
    <div class="card">
      <div class="row" style="justify-content:space-between;margin-top:0;">
        <h2 style="font-size:15px;margin:0;">Tulisan saya (<?php echo count($myPosts); ?>)</h2>
        <a class="btn" href="?new=1">+ Tulis baru</a>
      </div>
      <?php if (empty($myPosts)): ?>
        <p class="muted">Belum ada tulisan. Klik "Tulis baru".</p>
      <?php else: ?>
        <input type="search" id="my_posts_search" placeholder="Cari tulisan saya..." aria-label="Cari tulisan saya" autocomplete="off" style="width:100%;margin:8px 0;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text);">
        <p class="muted" id="my_posts_search_info" style="margin:0 0 6px;font-size:13px;"></p>
        <?php foreach ($myPosts as $p): ?>
        <?php $__blob = strtolower(trim((string) ($p['title'] ?? '') . ' ' . (string) ($p['slug'] ?? '') . ' ' . (string) ($p['summary'] ?? ''))); ?>
        <div class="pcard my-post-item" data-search="<?php echo htmlspecialchars($__blob, ENT_QUOTES, 'UTF-8'); ?>">
          <h3><?php echo htmlspecialchars((string) ($p['title'] ?? 'Tanpa judul'), ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="muted"><?php echo htmlspecialchars((string) (($p['created_at'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($p['archived']) ? ' · (arsip)' : ''; ?></div>
          <div class="row" style="margin-top:8px;">
            <a class="btn" href="/blog/<?php echo rawurlencode((string) ($p['slug'] ?? '')); ?>" target="_blank" rel="noopener">Lihat</a>
            <a class="btn" href="?edit=<?php echo rawurlencode((string) ($p['slug'] ?? '')); ?>">Edit</a>
            <form method="post" style="margin:0;" onsubmit="return uiConfirm(event, 'Hapus postingan ini?');">
              <input type="hidden" name="delete_post" value="<?php echo htmlspecialchars((string) ($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="danger">Hapus</button>
            </form>
          </div>
          <?php $__pa = assessments_for_post((string) ($p['slug'] ?? '')); ?>
          <?php if (!empty($__pa)): ?>
          <div style="margin-top:10px;border-top:1px dashed var(--stroke);padding-top:10px;">
            <div class="muted" style="font-size:12px;margin-bottom:6px;">Pretest/Posttest:</div>
            <?php foreach ($__pa as $as): ?>
              <div class="row" style="margin:4px 0;">
                <span style="font-size:13px;"><?php echo htmlspecialchars((string) $as['title'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo count($as['key_cells'] ?? []); ?> sel kunci · <?php echo (int) ($as['response_count'] ?? count($as['responses'] ?? [])); ?> responden</span>
                <a class="btn" href="?responses=<?php echo rawurlencode((string) $as['id']); ?>">Responden</a>
                <form method="post" style="margin:0;" onsubmit="return uiConfirm(event, 'Hapus asesmen ini?');">
                  <input type="hidden" name="delete_assessment" value="<?php echo htmlspecialchars((string) $as['id'], ENT_QUOTES, 'UTF-8'); ?>">
                  <button type="submit" class="danger">Hapus</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</div>
<?php require __DIR__ . '/components/penulis-panel-scripts.php'; ?>
<?php require __DIR__ . '/components/soft-save.php'; ?>
</body>
</html>
