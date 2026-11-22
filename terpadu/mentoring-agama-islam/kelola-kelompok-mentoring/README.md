## Struktur S3

```text
ukmipolmed/
└── terpadu/
    └── mai/
        └── mentoring/
            ├── master_json/
            └── profil/
                ├── mentor/
                ├── pendaftar/
                └── team/
```

## PWA

Salin isi cloudflare_worker_redirector.js dan Deploy ke Worker laporan-mai.krasyid822.workers.dev di Cloudflare Dashboard. Setelah itu, di PWABuilder.com Anda bisa memasukkan:            
  
- Laporan MAI: https://laporan-mai.krasyid822.workers.dev/pwa/laporan
- Daftar MAI: https://laporan-mai.krasyid822.workers.dev/pwa/daftar

## PWABuilder

- pkg name: `dev.workers.krasyid822.laporan_mai.twa`
- sign key: `terpadu/mentoring-agama-islam/kelola-kelompok-mentoring/storage_secure/keystore/mai-mentoring-pwabuilder.keystore`
- password: `terpadu/mentoring-agama-islam/kelola-kelompok-mentoring/storage_secure/keystore/keystore-info.txt`