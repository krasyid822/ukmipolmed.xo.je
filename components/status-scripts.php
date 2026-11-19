		<script>
		const fmt = (n) => typeof n === 'number' ? n.toLocaleString('id-ID') : '-';
		const fmtSize = (n) => {
			if (typeof n !== 'number') return '-';
			if (n >= 1024 * 1024 * 1024) return (n / (1024*1024*1024)).toFixed(2) + ' GB';
			if (n >= 1024 * 1024) return (n / (1024*1024)).toFixed(2) + ' MB';
			if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
			return n + ' B';
		};
		const kvFill = (id, rows) => {
			const el = document.getElementById(id);
			if (!el) return;
			el.innerHTML = rows.map(r => `<div><span>${r[0]}</span><strong>${r[1]}</strong></div>`).join('');
		};
		const fillTable = (id, rows) => {
			const tb = document.querySelector(`#${id} tbody`);
			if (!tb) return;
			tb.innerHTML = rows.map(r => `<tr><td>${r[0]}</td><td>${r[1]}</td></tr>`).join('');
		};
		const fitCanvas = (canvas) => {
			const rect = canvas.getBoundingClientRect();
			const dpr = window.devicePixelRatio || 1;
			const width = Math.max(320, Math.round(rect.width));
			const height = Math.max(220, Math.round(rect.height));
			canvas.width = Math.round(width * dpr);
			canvas.height = Math.round(height * dpr);
			const ctx = canvas.getContext('2d');
			ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
			return { ctx, width, height };
		};
		const drawTrendChart = (canvas, points) => {
			if (!canvas) return;
			const { ctx, width, height } = fitCanvas(canvas);
			ctx.clearRect(0, 0, width, height);
			const pad = { top: 18, right: 18, bottom: 34, left: 44 };
			const chartW = width - pad.left - pad.right;
			const chartH = height - pad.top - pad.bottom;
			const values = points.map((p) => Number(p.total) || 0);
			const max = Math.max(1, ...values);
			ctx.strokeStyle = 'rgba(255,255,255,0.12)';
			ctx.lineWidth = 1;
			ctx.font = '12px Manrope, sans-serif';
			ctx.fillStyle = 'rgba(196,206,222,0.8)';
			ctx.textAlign = 'right';
			ctx.textBaseline = 'middle';
			for (let i = 0; i <= 4; i++) {
				const y = pad.top + (chartH / 4) * i;
				ctx.beginPath();
				ctx.moveTo(pad.left, y);
				ctx.lineTo(width - pad.right, y);
				ctx.stroke();
				ctx.fillText(Math.round(max - (max / 4) * i).toString(), pad.left - 10, y);
			}
			if (!points.length) {
				ctx.fillStyle = 'rgba(196,206,222,0.9)';
				ctx.textAlign = 'center';
				ctx.textBaseline = 'middle';
				ctx.fillText('Belum ada history', width / 2, height / 2);
				return;
			}
			const xStep = points.length > 1 ? chartW / (points.length - 1) : chartW;
			const coords = points.map((point, index) => {
				const x = pad.left + xStep * index;
				const y = pad.top + chartH - ((Number(point.total) || 0) / max) * chartH;
				return { x, y, label: String(point.date || ''), value: Number(point.total) || 0 };
			});
			const path = new Path2D();
			coords.forEach((point, index) => {
				if (index === 0) path.moveTo(point.x, point.y);
				else path.lineTo(point.x, point.y);
			});
			ctx.strokeStyle = 'rgba(108,247,197,0.95)';
			ctx.lineWidth = 3;
			ctx.lineJoin = 'round';
			ctx.lineCap = 'round';
			ctx.stroke(path);
			const fillPath = new Path2D(path);
			fillPath.lineTo(coords[coords.length - 1].x, height - pad.bottom);
			fillPath.lineTo(coords[0].x, height - pad.bottom);
			fillPath.closePath();
			const gradient = ctx.createLinearGradient(0, pad.top, 0, height - pad.bottom);
			gradient.addColorStop(0, 'rgba(108,247,197,0.35)');
			gradient.addColorStop(1, 'rgba(108,247,197,0.02)');
			ctx.fillStyle = gradient;
			ctx.fill(fillPath);
			coords.forEach((point) => {
				ctx.beginPath();
				ctx.arc(point.x, point.y, 4.5, 0, Math.PI * 2);
				ctx.fillStyle = '#050814';
				ctx.fill();
				ctx.lineWidth = 2;
				ctx.strokeStyle = 'rgba(108,247,197,0.95)';
				ctx.stroke();
			});
			ctx.fillStyle = 'rgba(196,206,222,0.9)';
			ctx.font = '11px Manrope, sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'top';
			coords.forEach((point) => {
				ctx.save();
				ctx.translate(point.x, height - pad.bottom + 8);
				ctx.rotate(-0.45);
				ctx.fillText(point.label.slice(5), 0, 0);
				ctx.restore();
			});
		};
		let lastDashboardData = null;
		const renderDashboard = (data) => {
			lastDashboardData = data;

			kvFill('kv-traffic', [
				['Lifetime events', fmt(data.traffic?.lifetime_total)],
				['Hari ini', fmt(data.traffic?.today_total)],
			]);
			kvFill('kv-engagement', [
				['CTA non-daftar', fmt(data.engagement?.cta_clicks)],
				['Dokumentasi dibuka', fmt(data.engagement?.doc_opens)],
				['Agenda views', fmt(data.engagement?.agenda_views)],
			]);
			kvFill('kv-conv', [
				['Total CTA daftar', fmt(data.conversion?.form_clicks)],
				['Nav daftar', fmt(data.conversion?.nav_daftar)],
				['CTA daftar (hero)', fmt(data.conversion?.cta_daftar_hero)],
				['CTA daftar (banner)', fmt(data.conversion?.cta_daftar_banner)],
			]);
			const sm = data.technical?.sitemap || {};
			const pool = data.technical?.storage_pool || null;
			const s3 = data.technical?.s3_storage || null;
			const up = data.technical?.uploads || null;
			const hs = data.technical?.hosting_storage || null;
			kvFill('kv-tech', [
				['insights.json (S3)', fmtSize(data.technical?.insights_file?.size_bytes)],
				['Uploads (S3)', up?.tracked ? ((up.file_count ?? 0) + ' file · ' + fmtSize(up.size_bytes)) : 'Belum terhitung'],
				['Assessments (S3)', data.technical?.assessments_storage ? (fmt(data.technical.assessments_storage.assessments) + ' asesmen · ' + fmt(data.technical.assessments_storage.responses) + ' respons') : '-'],
				[(data.technical?.data_json?.sharded ? 'data_json (S3)' : 'data.json (S3)'), fmtSize(data.technical?.data_json?.size_bytes) + ' · pointer ' + fmtSize(data.technical?.data_json?.pointer_bytes)],
				['Komentar terpakai', fmtSize(data.technical?.comments_storage?.used_bytes)],
				['S3 terpakai', s3 ? fmtSize(s3.bytes_used) + ' / ' + fmtSize(s3.limit_bytes) + ' · ' + fmt(s3.objects) + ' objek' : '-'],
				['S3 sisa', pool ? fmtSize(pool.remaining_bytes) : 'Tidak diketahui'],
				['Hosting terpakai', hs ? fmtSize(hs.bytes_used) + ' / ' + (hs.limit_bytes > 0 ? fmtSize(hs.limit_bytes) : 'n/a') + ' · ' + (hs.percent_used === null ? 'n/a' : fmt(hs.percent_used) + '%') : '-'],
				['Inode hosting', hs ? fmt(hs.inodes_used) + ' / ' + (hs.inode_limit > 0 ? fmt(hs.inode_limit) : 'n/a') + ' · ' + (hs.inode_percent_used === null ? 'n/a' : fmt(hs.inode_percent_used) + '%') : '-'],
				['Cache seo-cache', hs && hs.cache ? fmtSize(hs.cache.bytes_used) + ' / ' + fmtSize(data.technical?.storage_model?.local_cache_max_bytes) + ' · ' + fmt(hs.cache.files) + ' file' : '-'],
				['Cakupan hosting', hs ? (hs.root_mode === 'account' ? 'Seluruh akun' : 'Docroot') : '-'],
				['Scan hosting', hs && hs.updated_at ? hs.updated_at + ' · ' + fmt(hs.age_seconds) + ' dtk lalu' + (hs.stale ? ' (stale)' : '') : '-'],
				['Sitemap', sm.type === 'dynamic' ? 'Dinamis · ' + fmt(sm.url_count) + ' URL · ' + fmt(sm.blog_post_count) + ' artikel · lastmod ' + (sm.lastmod_newest || '-') + ' · robots ' + (sm.declared_in_robots ? '✓' : '✗') : 'Tidak diketahui'],
				['HTTPS', data.technical?.https ? 'Aktif' : 'Belum'],
				['Waktu respon server', (data.technical?.runtime_ms || '-') + ' ms'],
			]);
			kvFill('kv-org', [
				['Pembaruan konten (agenda + blog)', fmt(data.org_specific?.agenda_updates)],
				['Blog dibuka (total)', fmt(data.org_specific?.blog_views_total)],
				['Kunjungan halaman utama', fmt(data.org_specific?.home_views)],
			]);

			const topRows = Object.entries(data.traffic?.top_events || {}).map(([k,v]) => [k, Number(v) || 0]);
			fillTable('tbl-top', topRows.map(([k, v]) => [k, fmt(v)]));
			drawTrendChart(document.getElementById('chart-trend'), data.recent_history || []);
		};
		const loadData = async () => {
			let data = null;
			try {
				const res = await fetch('/status?view=json');
				data = await res.json();
			} catch (e) {
				console.error('Load failed', e);
				return;
			}
			renderDashboard(data);
		};
		loadData();
		window.addEventListener('resize', () => {
			if (lastDashboardData) {
				renderDashboard(lastDashboardData);
			}
		});
		setInterval(loadData, 8000);
		</script>
