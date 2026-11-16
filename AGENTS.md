# AGENTS.md — Panduan Permanen Proyek ukmipolmed.xo.je

## PRINSIP UTAMA — JANGAN PERNAH DILUPAKAN

**HOLISTIC SEO (SEO Holistik / Optimasi Pencarian Menyeluruh)**

Setiap keputusan teknis, konten, dan desain WAJIB berpegang pada prinsip
Holistic SEO — bukan hanya SEO teknis, tetapi menyeluruh atas 4 pilar:

1. **Technical SEO** — schema JSON-LD, canonical, hreflang, URL bersih,
   sitemap/robots, Core Web Vitals, keamanan (HTTPS, headers, blokir file sensitif).
2. **Content SEO** — long tail keyword, content silo, TOFU/MOFU/BOFU, AEO, GEO.
3. **Authority SEO** — E-E-A-T (Experience, Expertise, Authoritativeness,
   Trustworthiness), sameAs ke sosial media, konsistensi brand.
4. **UX / SXO** — Core Web Vitals, mobile-first, alt text, keterbacaan,
   pengalaman pengguna yang baik.

### Aturan yang tidak boleh dilanggar
- Jangan pernah menambah **thin content** atau konten yang membunuh SEO
  (duplikat, keyword stuffing, konten tanpa nilai) demi mengisi halaman.
- Setiap perubahan harus menjaga keseimbangan keempat pilar di atas.
- AEO & GEO adalah bagian dari Holistic SEO (optimasi era AI), bukan terpisah.

Prinsip ini bersifat **PERMANEN** dan tidak boleh dihapus/direset.

## PRINSIP PERMANEN — DIREKTORI ROOT FTP (JANGAN SALAH)

**ROOT FTP PRODUKSI = `ftp://ftpupload.net/ukmipolmed.xo.je/htdocs/`**

- Path dokumen web (docroot) di server adalah `/ukmipolmed.xo.je/htdocs/`.
- **BUKAN** `/htdocs` (itu hanya relatif setelah login).
- Semua operasi tarik/upload/hapus file di FTP (lftp mirror, put, rm) WAJIB
  memakai path ini.
- File aplikasi (index.php, blog.php, data.json, dst.) tinggal langsung di
  bawah `/ukmipolmed.xo.je/htdocs/`.
- Prinsip ini bersifat **PERMANEN** dan tidak boleh diubah/direset.

## Rujukan
- Detail audit & temuan lengkap: `TEMUAN.TXT`
- Panduan konsep SEO (AEO, GEO, longtail, schema, E-E-A-T, content silo,
  TOFU/MOFU/BOFU, alt/anchor text) tersedia di `TEMUAN.TXT` Bagian 3.