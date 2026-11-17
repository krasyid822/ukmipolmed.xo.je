<?php
// authors_helper.php — store akun penulis + profil admin (authors.json).
// Dipakai oleh admin (bootstrap), blog publik (byline/profil), dan panel penulis.

function authors_path()
{
    return __DIR__ . '/authors.json';
}

function authors_default()
{
    return [
        'admin_profile' => [
            'pen_name' => 'Admin UKMI Polmed',
            'avatar' => '/logo-ukmi.png',
            'social' => 'https://www.instagram.com/ukmipolmed/',
        ],
        'authors' => [],
    ];
}

function authors_load()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $data = authors_default();
    $p = authors_path();
    if (is_readable($p)) {
        $j = json_decode((string) @file_get_contents($p), true);
        if (is_array($j)) {
            if (isset($j['authors']) && is_array($j['authors'])) {
                $data['authors'] = array_values($j['authors']);
            }
            if (isset($j['admin_profile']) && is_array($j['admin_profile'])) {
                $data['admin_profile'] = array_merge($data['admin_profile'], $j['admin_profile']);
            }
        }
    }
    $cache = $data;
    return $cache;
}

function authors_save($data)
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }
    return @file_put_contents(authors_path(), $encoded) !== false;
}

function authors_find_by_username($username)
{
    $data = authors_load();
    foreach ($data['authors'] as $a) {
        if (!empty($a['username']) && strcasecmp((string) $a['username'], (string) $username) === 0) {
            return $a;
        }
    }
    return null;
}

function authors_find_by_id($id)
{
    if ($id === '' || $id === null) {
        return null;
    }
    $data = authors_load();
    foreach ($data['authors'] as $a) {
        if (isset($a['id']) && (string) $a['id'] === (string) $id) {
            return $a;
        }
    }
    return null;
}

function author_display($authorId, $adminBio = '')
{
    if ($authorId === '' || $authorId === null) {
        $d = authors_load();
        $ap = $d['admin_profile'];
        return [
            'pen_name' => $ap['pen_name'] ?: 'Admin UKMI Polmed',
            'bio' => (string) $adminBio,
            'avatar' => $ap['avatar'] ?: '/logo-ukmi.png',
            'social' => $ap['social'] ?? '',
            'username' => '',
            'url' => '/penulis/admin',
            'is_admin' => true,
        ];
    }
    $a = authors_find_by_id($authorId);
    if (!$a) {
        return author_display('', $adminBio);
    }
    $u = (string) ($a['username'] ?? '');
    return [
        'pen_name' => ($a['pen_name'] ?? '') !== '' ? $a['pen_name'] : ($u !== '' ? $u : 'Penulis'),
        'bio' => (string) ($a['bio'] ?? ''),
        'avatar' => ($a['avatar'] ?? '') !== '' ? $a['avatar'] : '/logo-ukmi.png',
        'social' => (string) ($a['social'] ?? ''),
        'username' => $u,
        'url' => $u !== '' ? '/penulis/' . rawurlencode($u) : '/penulis/admin',
        'is_admin' => false,
    ];
}
