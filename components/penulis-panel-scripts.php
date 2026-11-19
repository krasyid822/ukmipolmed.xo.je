<script>
(function () {
  function cellVal(cell) {
    var v = cell.value;
    if (v != null && typeof v === 'object') {
      if (v.result != null) return String(v.result);
      if (typeof v.formula === 'string') return '=' + v.formula;
      if (v.richText) return v.richText.map(function (t) { return t.text; }).join('');
      if (v instanceof Date) return v.toISOString().slice(0, 10);
      if (v.text != null) return String(v.text);
    }
    return v == null ? '' : String(v);
  }
  function ab2b64(buf) {
    var bytes = new Uint8Array(buf), bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
  }
  function setupForm(form) {
    if (form.__excelBound) return;
    form.__excelBound = true;
    var inp = form.querySelector('.a_xlsx') || form.querySelector('.pa_xlsx');
    var keyHid = form.querySelector('.a_key_cells') || form.querySelector('.pa_key_cells');
    var cleanHid = form.querySelector('.a_clean_xlsx') || form.querySelector('.pa_clean_xlsx');
    var prev = form.querySelector('.a_task_preview') || form.querySelector('.pa_task_preview');
    if (!inp) return;
    inp.addEventListener('change', async function (e) {
      var f = e.target.files && e.target.files[0];
      if (!f) return;
      if (typeof ExcelJS === 'undefined') { uiAlert('Library ExcelJS belum termuat. Muat ulang halaman.'); return; }
      try {
        var wb = new ExcelJS.Workbook();
        await wb.xlsx.load(await f.arrayBuffer());
        var keys = [];
        wb.eachSheet(function (ws) {
          ws.eachRow({ includeEmpty: false }, function (row) {
            row.eachCell({ includeEmpty: false }, function (cell) {
              var isBlack = false;
              var fl = cell.fill;
              if (fl && fl.type === 'pattern' && fl.fgColor) {
                var argb = (fl.fgColor.argb || '').toUpperCase();
                if (argb === 'FF000000') isBlack = true;
                else if (fl.fgColor.theme != null && Number(fl.fgColor.theme) === 1 && !fl.fgColor.rgb) isBlack = true;
              }
              if (isBlack) keys.push({ s: ws.name, addr: cell.address, key: cellVal(cell) });
            });
          });
        });
        keys.forEach(function (k) {
          var ws = wb.getWorksheet(k.s);
          if (ws) { var c = ws.getCell(k.addr); c.value = null; c.fill = { type: 'pattern', pattern: 'none' }; }
        });
        if (keyHid) keyHid.value = JSON.stringify(keys);
        var buf = await wb.xlsx.writeBuffer();
        if (cleanHid) cleanHid.value = ab2b64(buf);
        if (prev) {
          prev.innerHTML = keys.length
            ? '<div class="muted" style="font-size:12px;">Terdeteksi ' + keys.length + ' sel kunci (hitam): ' + keys.map(function (k) { return k.addr; }).join(', ') + '</div>'
            : '<div class="muted" style="font-size:12px;">Tidak ada sel berwarna HITAM. Beri fill hitam pada sel kunci, lalu pilih file lagi.</div>';
        }
        if (!keys.length) uiAlert('Tidak ada sel berwarna HITAM (kunci). Beri fill hitam pada sel jawaban.');
      } catch (err) { uiAlert('Gagal membaca Excel: ' + err); }
    });
  }
  document.querySelectorAll('.assess-form').forEach(setupForm);
  var paInput = document.querySelector('.pa_xlsx');
  if (paInput) { var pf = paInput.closest('form'); if (pf) setupForm(pf); }
})();
</script>
<script>
(function () {
  var bodyHid = document.getElementById('body');
  var bodyInfo = document.getElementById('body_info');
  function setBody(v) { if (bodyHid) bodyHid.value = v; if (bodyInfo) bodyInfo.textContent = v ? ('Konten: ' + v.length + ' karakter (Markdown)') : ''; }
  function validMd(t) { if (!t || !t.trim()) return false; if (/^data:/i.test(t.trim())) return false; if (/<script/i.test(t)) return false; return true; }
  var bf = document.getElementById('body_md_file');
  if (bf) bf.addEventListener('change', function (e) {
    var f = e.target.files && e.target.files[0]; if (!f) return;
    var name = (f.name || '').toLowerCase();
    if (/\.docx$/.test(name)) {
      if (typeof mammoth === 'undefined' || typeof TurndownService === 'undefined') { uiAlert('Library konversi docx belum termuat.'); e.target.value = ''; return; }
      var fr = new FileReader();
      fr.onload = function () {
        mammoth.convertToHtml({ arrayBuffer: fr.result }).then(function (res) {
          var html = res.value || '';
          try {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            doc.querySelectorAll('table').forEach(function (tbl) {
              tbl.querySelectorAll('td,th').forEach(function (cell) {
                var h = cell.innerHTML;
                h = h.replace(/<br\s*\/?>/gi, ' ');
                h = h.replace(/<\/(p|div|li|h[1-6])>/gi, ' ');
                h = h.replace(/<(p|div|li|h[1-6])[^>]*>/gi, ' ');
                h = h.replace(/[ \t\f\v]+/g, ' ').replace(/\s*\n\s*/g, ' ').trim();
                cell.innerHTML = h;
              });
              var allRows = Array.prototype.slice.call(tbl.querySelectorAll('tr')).filter(function (r) {
                return r.querySelectorAll('td,th').length > 0;
              });
              if (!allRows.length) return;
              var thead = doc.createElement('thead');
              var htr = doc.createElement('tr');
              allRows[0].querySelectorAll('td,th').forEach(function (c) {
                var th = doc.createElement('th');
                th.innerHTML = c.innerHTML;
                htr.appendChild(th);
              });
              thead.appendChild(htr);
              var tbody = doc.createElement('tbody');
              allRows.slice(1).forEach(function (r) { tbody.appendChild(r); });
              while (tbl.firstChild) tbl.removeChild(tbl.firstChild);
              tbl.appendChild(thead);
              tbl.appendChild(tbody);
            });
            html = doc.body.innerHTML;
          } catch (e) {}
          var td = new TurndownService({ headingStyle: 'atx', codeBlockStyle: 'fenced', bulletListMarker: '-' });
          if (typeof turndownPluginGfm !== 'undefined' && turndownPluginGfm && turndownPluginGfm.gfm) { td.use(turndownPluginGfm.gfm); }
          var md = td.turndown(html);
          md = md.replace(/<\/?(table|thead|tbody|tr|td|th)\b[^>]*>/gi, '');
          md = md.replace(/\n{3,}/g, '\n\n').trim();
          if (validMd(md)) setBody(md); else uiAlert('Hasil konversi .docx tidak valid.');
        }).catch(function (err) { uiAlert('Gagal mengonversi .docx: ' + err); });
      };
      fr.readAsArrayBuffer(f);
    } else {
      var rd = new FileReader();
      rd.onload = function () { var t = String(rd.result); if (validMd(t)) setBody(t); else uiAlert('Berkas bukan Markdown yang valid.'); };
      rd.readAsText(f);
    }
    e.target.value = '';
  });
  var bp = document.getElementById('body_paste');
  if (bp) bp.addEventListener('paste', function (e) {
    var cd = e.clipboardData || window.clipboardData; var t = cd ? cd.getData('text') : '';
    if (!t) return; e.preventDefault();
    if (validMd(t)) { bp.value = ''; setBody(t); } else uiAlert('Clipboard tidak sesuai format Markdown.');
  });

  var pvBtn = document.getElementById('md_preview_btn');
  var pv = document.getElementById('md_preview');
  var bodyTA = document.getElementById('body');
  function renderPreview() {
    if (!pv) return;
    var txt = bodyTA ? bodyTA.value : '';
    if (typeof marked !== 'undefined') { pv.innerHTML = marked.parse(txt); }
    else { pv.textContent = txt; }
  }
  if (pvBtn && pv && bodyTA) {
    pvBtn.addEventListener('click', function () {
      if (pv.style.display === 'block') { pv.style.display = 'none'; pvBtn.textContent = 'Preview'; }
      else { renderPreview(); pv.style.display = 'block'; pvBtn.textContent = 'Sembunyikan'; }
    });
    bodyTA.addEventListener('input', function () { if (pv.style.display === 'block') renderPreview(); });
  }

  var imgHid = document.getElementById('image');
  var coverInfo = document.getElementById('cover_info');
  function setImg(v) { if (imgHid) imgHid.value = v; if (coverInfo) coverInfo.textContent = v ? 'Sampul diset.' : ''; renderPick(); }
  function validImg(t) { t = (t || '').trim(); if (/^https?:\/\/\S+$/i.test(t)) return true; if (/^data:image\/[a-z0-9.+-]+;base64,[a-z0-9+/=]+$/i.test(t)) return true; return false; }
  var cf = document.getElementById('cover_file');
  if (cf) cf.addEventListener('change', function (e) {
    var f = e.target.files && e.target.files[0]; if (!f) return;
    if (!/^image\//.test(f.type)) { uiAlert('Berkas harus gambar.'); e.target.value = ''; return; }
    var rd = new FileReader();
    rd.onload = function () { setImg(String(rd.result)); };
    rd.readAsDataURL(f); e.target.value = '';
  });
  var cp = document.getElementById('cover_paste');
  if (cp) cp.addEventListener('paste', function (e) {
    var cd = e.clipboardData || window.clipboardData; var t = cd ? cd.getData('text') : '';
    if (!t) return; e.preventDefault(); t = t.trim();
    if (validImg(t)) { cp.value = ''; setImg(t); } else uiAlert('Clipboard harus URL https://... atau data:image/...;base64,...');
  });

  var postForm = document.getElementById('post_form');
  if (postForm) postForm.addEventListener('submit', function (e) {
    var v = ((imgHid && imgHid.value) || '').trim();
    if (!validImg(v)) {
      e.preventDefault();
      if (coverInfo) coverInfo.textContent = 'Gambar sampul wajib diunggah.';
      uiAlert('Gambar sampul wajib diunggah. Postingan tanpa gambar sampul tidak diizinkan.');
    }
  });

  var fxp = document.getElementById('focal_x');
  var fyp = document.getElementById('focal_y');
  var pickEl = document.getElementById('cover_pick');
  var pickImg = document.getElementById('cover_pick_img');
  var pickMarker = document.getElementById('cover_pick_marker');
  function posMarker() {
    if (!pickMarker) return;
    var x = parseFloat((fxp && fxp.value) || '50');
    var y = parseFloat((fyp && fyp.value) || '50');
    pickMarker.style.left = (isNaN(x) ? 50 : x) + '%';
    pickMarker.style.top = (isNaN(y) ? 50 : y) + '%';
  }
  function renderPick() {
    if (!pickEl || !pickImg) return;
    var v = ((imgHid && imgHid.value) || '').trim();
    if (v.indexOf('data:') === 0 || /^https?:\/\//i.test(v)) {
      if (pickImg.getAttribute('src') !== v) pickImg.src = v;
      pickEl.style.display = 'block';
    } else {
      pickEl.style.display = 'none';
    }
    posMarker();
  }
  if (pickEl) pickEl.addEventListener('click', function (ev) {
    var r = pickEl.getBoundingClientRect();
    if (!r.width || !r.height) return;
    var x = Math.max(0, Math.min(100, ((ev.clientX - r.left) / r.width) * 100));
    var y = Math.max(0, Math.min(100, ((ev.clientY - r.top) / r.height) * 100));
    if (fxp) fxp.value = Math.round(x);
    if (fyp) fyp.value = Math.round(y);
    posMarker();
  });
  if (pickImg) pickImg.addEventListener('load', posMarker);
  renderPick();
})();
</script>
<script>
(function () {
  function bindList(listId, addId, rowClass, removeClass, makeRow) {
    var list = document.getElementById(listId);
    var add = document.getElementById(addId);
    if (!list || !add) return;
    add.addEventListener('click', function () { list.appendChild(makeRow()); });
    list.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.' + removeClass);
      if (!btn) return;
      var item = btn.closest('.' + rowClass);
      if (item) item.remove();
    });
  }
  bindList('embed_list', 'add-embed', 'embed-item', 'remove-embed', function () {
    var d = document.createElement('div');
    d.className = 'embed-item';
    d.style.cssText = 'display:flex;gap:8px;align-items:flex-start;margin-top:6px;';
    var ta = document.createElement('textarea');
    ta.name = 'embeds[]'; ta.rows = 3; ta.style.flex = '1'; ta.placeholder = '<iframe ...></iframe>';
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn danger remove-embed'; b.style.margin = '0'; b.textContent = 'Hapus';
    d.appendChild(ta); d.appendChild(b);
    return d;
  });
  bindList('gdrive_list', 'add-gdrive', 'gdrive-item', 'remove-gdrive', function () {
    var d = document.createElement('div');
    d.className = 'gdrive-item';
    d.style.cssText = 'display:flex;gap:8px;align-items:center;margin-top:6px;';
    var inp = document.createElement('input');
    inp.type = 'text'; inp.name = 'gdrive_id[]'; inp.placeholder = 'Folder ID'; inp.style.flex = '1';
    var sel = document.createElement('select');
    sel.name = 'gdrive_view[]';
    sel.style.cssText = 'width:110px;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text);';
    var o1 = document.createElement('option'); o1.value = 'grid'; o1.textContent = 'Grid';
    var o2 = document.createElement('option'); o2.value = 'list'; o2.textContent = 'List';
    sel.appendChild(o1); sel.appendChild(o2);
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn danger remove-gdrive'; b.style.margin = '0'; b.textContent = 'Hapus';
    d.appendChild(inp); d.appendChild(sel); d.appendChild(b);
    return d;
  });
})();
</script>
<script>
(function () {
  var search = document.getElementById('my_posts_search');
  var info = document.getElementById('my_posts_search_info');
  var items = Array.prototype.slice.call(document.querySelectorAll('.my-post-item'));
  if (!search || !items.length) return;
  function apply() {
    var q = (search.value || '').toLowerCase().trim();
    var shown = 0;
    items.forEach(function (el) {
      var hay = el.getAttribute('data-search') || '';
      var ok = !q || hay.indexOf(q) >= 0;
      el.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    if (info) info.textContent = q ? ('Menampilkan ' + shown + ' dari ' + items.length + ' tulisan.') : '';
  }
  search.addEventListener('input', apply);
  apply();
})();
</script>
