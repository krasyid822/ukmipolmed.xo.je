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
        $adminSocials = [];
        if (!empty($ap['social'])) $adminSocials[] = trim((string) $ap['social']);
        return [
            'pen_name' => $ap['pen_name'] ?: 'Admin UKMI Polmed',
            'bio' => (string) $adminBio,
            'avatar' => $ap['avatar'] ?: '/logo-ukmi.png',
            'social' => $ap['social'] ?? '',
            'socials' => $adminSocials,
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
    $socials = [];
    if (!empty($a['socials']) && is_array($a['socials'])) {
        foreach ($a['socials'] as $s) {
            $s = trim((string) $s);
            if ($s !== '') $socials[] = $s;
        }
    } elseif (!empty($a['social'])) {
        $s = trim((string) $a['social']);
        if ($s !== '') $socials[] = $s;
    }
    return [
        'pen_name' => ($a['pen_name'] ?? '') !== '' ? $a['pen_name'] : ($u !== '' ? $u : 'Penulis'),
        'bio' => (string) ($a['bio'] ?? ''),
        'avatar' => ($a['avatar'] ?? '') !== '' ? $a['avatar'] : '/logo-ukmi.png',
        'social' => (string) ($a['social'] ?? ''),
        'socials' => $socials,
        'username' => $u,
        'url' => $u !== '' ? '/penulis/' . rawurlencode($u) : '/penulis/admin',
        'is_admin' => false,
    ];
}

function social_platform($url)
{
    $u = strtolower(trim((string) $url));
    if ($u === '') return 'website';
    if (strpos($u, 'instagram.com') !== false) return 'instagram';
    if (strpos($u, 'youtube.com') !== false || strpos($u, 'youtu.be') !== false) return 'youtube';
    if (strpos($u, 'facebook.com') !== false || strpos($u, 'fb.com') !== false) return 'facebook';
    if (strpos($u, 'x.com') !== false || strpos($u, 'twitter.com') !== false) return 'x';
    if (strpos($u, 'tiktok.com') !== false) return 'tiktok';
    if (strpos($u, 'linkedin.com') !== false) return 'linkedin';
    if (strpos($u, 'wa.me') !== false || strpos($u, 'whatsapp.com') !== false) return 'whatsapp';
    if (strpos($u, 't.me') !== false || strpos($u, 'telegram') !== false) return 'telegram';
    if (strpos($u, 'github.com') !== false) return 'github';
    if (strpos($u, 'mailto:') === 0) return 'email';
    return 'website';
}

function social_icon_svg($platform)
{
    $p = 'fill="currentColor"';
    switch ($platform) {
        case 'instagram':
            return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>';
        case 'youtube':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M23 12s0-3.5-.45-5.2a2.8 2.8 0 0 0-2-2C18.9 4.3 12 4.3 12 4.3s-6.9 0-8.55.5a2.8 2.8 0 0 0-2 2C1 8.5 1 12 1 12s0 3.5.45 5.2a2.8 2.8 0 0 0 2 2c1.65.5 8.55.5 8.55.5s6.9 0 8.55-.5a2.8 2.8 0 0 0 2-2C23 15.5 23 12 23 12ZM9.75 15.5v-7l6 3.5-6 3.5Z"/></svg>';
        case 'facebook':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M13.5 22v-8h2.7l.4-3.1h-3.1V8.9c0-.9.25-1.5 1.55-1.5H16.7V4.6a21 21 0 0 0-2.4-.12c-2.4 0-4 1.46-4 4.14v2.28H7.6V14h2.7v8h3.2Z"/></svg>';
        case 'x':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M17.5 3h3l-6.6 7.5L21.8 21h-6l-4.7-6.1L5.6 21H2.5l7-8L2 3h6.1l4.2 5.6L17.5 3Zm-2.1 16h1.7L7.7 4.9H5.9L15.4 19Z"/></svg>';
        case 'tiktok':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M16.5 3c.3 2.1 1.6 3.6 3.5 3.9v2.5c-1.3.1-2.5-.3-3.6-1v6.1a5.6 5.6 0 1 1-5.6-5.6c.3 0 .6 0 .9.06v2.6a3 3 0 1 0 2.2 2.9V3h2.6Z"/></svg>';
        case 'linkedin':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M6.94 5a1.94 1.94 0 1 1-3.88 0 1.94 1.94 0 0 1 3.88 0ZM3.3 8.5h3.3V21H3.3V8.5Zm5.4 0h3.16v1.7h.05c.44-.83 1.5-1.7 3.1-1.7 3.3 0 3.9 2.17 3.9 5V21h-3.3v-5.9c0-1.4-.02-3.2-1.95-3.2-1.96 0-2.26 1.53-2.26 3.1V21H8.7V8.5Z"/></svg>';
        case 'whatsapp':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-2.8.7.8-2.7-.2-.3A8 8 0 1 1 12 20Zm4.5-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.2-.4 0-.5.2-.7l.4-.5c.1-.2.1-.3 0-.5l-.7-1.7c-.2-.5-.4-.4-.5-.4h-.5c-.2 0-.5.1-.7.3-.7.7-.9 1.6-.6 2.6.4 1.3 1.4 2.6 2.5 3.4 1.2.9 2.4 1.4 3.4 1.4.6 0 1.2-.2 1.6-.6.2-.2.3-.5.3-.8 0-.1 0-.2-.1-.3Z"/></svg>';
        case 'telegram':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M21.9 4.3 2.9 11.6c-1 .4-1 1.4 0 1.7l4.6 1.5 1.8 5.5c.2.6.4.7 1 .3l2.5-1.8 4.6 3.4c.8.5 1.4.2 1.6-.8l3-13.8c.2-1-.4-1.4-1.1-1.1ZM8.9 14.6l9.4-5.9c.4-.3.8-.1.4.2l-7.7 7-.3 3.2-1.8-4.5Z"/></svg>';
        case 'github':
            return '<svg viewBox="0 0 24 24" width="18" height="18" ' . $p . ' aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.8.6-3.4-1.3-3.4-1.3-.5-1.1-1.1-1.4-1.1-1.4-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.3-2.2-.3-4.6-1.1-4.6-5a3.9 3.9 0 0 1 1-2.7 3.6 3.6 0 0 1 .1-2.7s.8-.3 2.7 1a9.3 9.3 0 0 1 4.9 0c1.9-1.3 2.7-1 2.7-1 .5 1.4.2 2.4.1 2.7.6.7 1 1.6 1 2.7 0 3.9-2.4 4.7-4.6 5 .3.3.6.9.6 1.8v2.7c0 .3.2.6.7.5A10 10 0 0 0 12 2Z"/></svg>';
        case 'email':
            return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
        default:
            return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18Z"/></svg>';
    }
}
