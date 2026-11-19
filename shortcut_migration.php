<?php
// Migrasi satu kali: isi shortcut_cards dari tiga kartu "Baca ini.." lama
// bila key tersebut belum ada di data.json. Setelah key ada (termasuk array
// kosong), migrasi tidak dijalankan lagi sehingga admin tetap memegang kendali.

function shortcut_legacy_cards()
{
    return [
        [
            'slides' => [[
                'tag' => 'Skill Class',
                'title' => 'Desain & konten',
                'detail' => 'Belajar desain, editing, dan social media playbook UKMI. Cocok buat kamu yang kreatif.',
            ]],
        ],
        [
            'slides' => [[
                'tag' => 'Mentoring',
                'title' => 'Circle kecil',
                'detail' => 'Ruang ngobrol hangat bareng kakak tingkat tentang kampus, iman, dan karier.',
            ]],
        ],
        [
            'slides' => [[
                'tag' => 'Social impact',
                'title' => 'Aksi nyata',
                'detail' => 'Program bakti sosial, peduli lingkungan, dan kolaborasi lintas UKM.',
            ]],
        ],
    ];
}

// Return daftar kartu yang baru di-seed, atau null bila tidak ada migrasi.
function shortcut_ensure_seeded($configPath)
{
    if (!is_string($configPath)) return null;
    if (!function_exists('store_read_json')) {
        require_once __DIR__ . '/store.php';
    }
    if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
        // Jangan pernah menulis dari read path bila salah satu pecahan gagal
        // dibaca: default [] tidak bisa dibedakan dari data kosong.
        $siteRes = data_store_shard_read_ex('site.json', null);
        if (($siteRes['status'] ?? 'error') !== 'ok' || !is_array($siteRes['data'] ?? null)) return null;
        $metaRes = data_store_shard_read_ex('meta.json', null);
        if (($metaRes['status'] ?? 'error') !== 'ok' || !is_array($metaRes['data'] ?? null)) return null;
        $site = $siteRes['data'];
        $meta = $metaRes['data'];
        if (array_key_exists('shortcut_cards', $site) || array_key_exists('shortcut_cards', $meta)) return null;
        $cards = shortcut_legacy_cards();
        $site['shortcut_cards'] = $cards;
        if (!data_store_shard_write('site.json', $site)) return null;
        return $cards;
    }
    // Mode legacy tidak diubah dari read path. Migrasi penuh harus lewat
    // migrate_store/migrate_data_shards agar deploy parsial tetap aman.
    return null;
}

