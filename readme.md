index robot htaccess

The BI metric “Agenda views” is computed by summing any insight events whose name starts with agenda_ in the engagement and org sections, e.g. agenda_view, agenda_rotate (status.php:95-135).
An agenda event is only recorded when the frontend calls status.php?event=… via sendInsight() and the name begins with agenda_ (index.php:700-760).
Currently the page only sends cta_* and doc_* events via data-insight attributes; no element or script emits an agenda_* event (index.php:660-725). So “Agenda views” will stay at 0 unless a request like status.php?event=agenda_view is fired.
If you want it to increment automatically, wire the agenda card/rotator to sendInsight('agenda_view') (e.g., on load or on each rotation interval).

lindungi semua file .json, dan khusus untuk default.json aksesnya read-only

Klik dokumentasi, adalah berapakali tombol dibagian Kartu dokumentasi kegiatan diklik pengunjung.
Klik tombol daftar, adalah berapakali tombol dibagian Buka form pendaftaran diklik pengunjung.
Pembaruan agenda, adalah berapakali admin sudah melakukan pembaruan pada web (termasuk menghapus, mengedit, menambah postingan. dan lain lain).
Blog dibuka (total), adalah berapakali postingan di Blog dibuka pengunjung.
Kunjungan halaman utama, adalah berapakali web ukmipolmed.xo.je dibuka pengunjung.

perbarui kebijakan: boleh hapus walau belum 10MB

### Khusus untuk embed ig jika ngawur layoutnya:
```html
<style>
.instagram-embed-wrapper {
  max-width: 100%;           /* batasi lebar maksimal */
  margin: 1em auto;          /* optional: biar rapi di tengah */
  overflow: hidden;          /* sembunyikan jika masih ada yang nyempil */
}

.instagram-embed-wrapper .instagram-media {
  margin: 0 !important;      /* hilangkan margin default Instagram */
  max-width: 100% !important;
  width: 100% !important;
  min-width: 0 !important;   /* penting: lawan min-width:326px */
}

/* Target iframe yang di-inject script */
.instagram-embed-wrapper iframe.instagram-media {
  width: 100% !important;
  min-width: 0 !important;
  max-width: 100% !important;
}
</style>
<div class="instagram-embed-wrapper">
 ..masukkan kode embed disini..
</div>
```