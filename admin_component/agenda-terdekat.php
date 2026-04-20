<?php
if (($adminComponentMode ?? '') === 'handle') {
	$agendaArchive = is_array($config['agenda_archive']) ? $config['agenda_archive'] : [];

	if ($loggedIn && isset($_POST['archive_idx'])) {
		$idx = (int) $_POST['archive_idx'];
		if (isset($agendas[$idx])) {
			$archived = $agendas[$idx];
			$archived['archived_at'] = date('c');
			unset($agendas[$idx]);
			$agendas = array_values($agendas);
			array_unshift($agendaArchive, $archived);
			$agendaArchive = array_slice($agendaArchive, 0, $maxAgendaArchive);
			$config['agendas'] = $agendas;
			$config['agenda_archive'] = $agendaArchive;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal mengarsipkan agenda.';
			} else {
				$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
				$message = 'Agenda diarsipkan.';
				appendLog($config, $configPath, 'agenda-archived', $maxLogs, $archiveLimitDays);
				bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
			}
		}
	}

	if ($loggedIn && isset($_POST['delete_idx'])) {
		$idx = (int) $_POST['delete_idx'];
		if (isset($agendas[$idx])) {
			unset($agendas[$idx]);
			$agendas = array_values($agendas);
			$config['agendas'] = $agendas;
			$config['agenda'] = $agendas[0] ?? $defaults['agenda'];
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal menghapus agenda.';
			} else {
				$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
				$editingIdx = 0;
				$message = 'Agenda dihapus.';
				appendLog($config, $configPath, 'agenda-deleted', $maxLogs, $archiveLimitDays);
			}
		}
	}

	if ($loggedIn && isset($_POST['move_idx'], $_POST['move_dir'])) {
		$idx = (int) $_POST['move_idx'];
		$dir = $_POST['move_dir'] === 'down' ? 'down' : 'up';
		$maxIdx = count($agendas) - 1;
		if ($idx >= 0 && $idx <= $maxIdx) {
			$target = ($dir === 'up') ? $idx - 1 : $idx + 1;
			if ($target >= 0 && $target <= $maxIdx) {
				$tmp = $agendas[$idx];
				$agendas[$idx] = $agendas[$target];
				$agendas[$target] = $tmp;
				$agendas = array_values($agendas);
				$config['agendas'] = $agendas;
				$config['agenda'] = $agendas[0] ?? $defaults['agenda'];
				$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
				if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
					$error = 'Gagal mengubah urutan agenda.';
				} else {
					$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
					$editingIdx = 0;
					$message = 'Urutan agenda diperbarui.';
					appendLog($config, $configPath, 'agenda-reordered', $maxLogs, $archiveLimitDays);
					bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
				}
			}
		}
	}

	if ($loggedIn && isset($_POST['restore_archive_idx'])) {
		$idx = (int) $_POST['restore_archive_idx'];
		if (isset($agendaArchive[$idx])) {
			$restored = $agendaArchive[$idx];
			unset($restored['archived_at']);
			unset($agendaArchive[$idx]);
			$agendaArchive = array_values($agendaArchive);
			array_unshift($agendas, $restored);
			$config['agendas'] = $agendas;
			$config['agenda_archive'] = $agendaArchive;
			$config['agenda'] = $agendas[0] ?? $defaults['agenda'];
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal memulihkan agenda arsip.';
			} else {
				$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
				$message = 'Agenda dikembalikan dari arsip.';
				appendLog($config, $configPath, 'agenda-restored', $maxLogs, $archiveLimitDays);
				bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
			}
		}
	}

	if ($loggedIn && isset($_POST['edit_idx'])) {
		$idx = (int) $_POST['edit_idx'];
		if (isset($agendas[$idx])) {
			$editingIdx = $idx;
			$primaryAgenda = $agendas[$idx];
		}
	}

	if ($loggedIn && isset($_POST['agenda_tag'], $_POST['agenda_title'], $_POST['agenda_detail'])) {
		$mode = $_POST['agenda_mode'] ?? 'update';
		$editIdx = isset($_POST['agenda_edit_idx']) ? max(0, (int) $_POST['agenda_edit_idx']) : 0;
		$newAgenda = [
			'tag' => trim((string) $_POST['agenda_tag']) ?: $defaults['agenda']['tag'],
			'title' => trim((string) $_POST['agenda_title']) ?: $defaults['agenda']['title'],
			'detail' => trim((string) $_POST['agenda_detail']) ?: $defaults['agenda']['detail'],
		];

		if ($mode === 'new' || empty($agendas)) {
			array_unshift($agendas, $newAgenda);
			$editingIdx = 0;
		} else {
			if (isset($agendas[$editIdx])) {
				$agendas[$editIdx] = $newAgenda;
				$editingIdx = $editIdx;
			} else {
				$agendas[0] = $newAgenda;
				$editingIdx = 0;
			}
		}

		$config['agendas'] = $agendas;
		$config['agenda'] = $agendas[0] ?? $newAgenda;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan data agenda.';
		} else {
			$primaryAgenda = $agendas[$editingIdx] ?? ($agendas[0] ?? $newAgenda);
			$editingIdx = 0;
			$message = ($mode === 'new') ? 'Agenda baru ditambahkan.' : 'Agenda diperbarui.';
			appendLog($config, $configPath, 'agenda-updated', $maxLogs, $archiveLimitDays);
			bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
		}
	}

	return;
}

$agendaArchive = is_array($config['agenda_archive']) ? $config['agenda_archive'] : [];
?>
<div class="section">
	<h2 style="margin:0 0 8px; font-size:16px;">Agenda terdekat</h2>
	<form method="post" class="stack-sm" id="agenda-form" data-autosave-key="agenda">
		<input type="hidden" name="agenda_edit_idx" value="<?php echo (int) $editingIdx; ?>">
		<div class="field-grid">
			<div>
				<label for="agenda_tag">Tag</label>
				<input type="text" id="agenda_tag" name="agenda_tag" style="min-width:260px;" value="<?php echo htmlspecialchars($primaryAgenda['tag'], ENT_QUOTES, 'UTF-8'); ?>" required>
			</div>
			<div>
				<label for="agenda_title">Judul</label>
				<input type="text" id="agenda_title" name="agenda_title" style="min-width:260px;" value="<?php echo htmlspecialchars($primaryAgenda['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
			</div>
			<div class="full">
				<label for="agenda_detail">Detail</label>
				<textarea id="agenda_detail" name="agenda_detail" required><?php echo htmlspecialchars($primaryAgenda['detail'], ENT_QUOTES, 'UTF-8'); ?></textarea>
				<p class="muted-text">Contoh: Minggu, 25 Februari · Namira School. MUBES, Ihsan Taufiq, dipilih sebagai Ketua Umum UKMI Polmed 2026-2027.</p>
			</div>
		</div>
		<div class="inline-actions" style="align-items:center;">
			<label style="margin:0; display:flex; gap:6px; align-items:center; color: var(--muted); font-size:13px;">
				<input type="radio" name="agenda_mode" value="update" <?php echo (!isset($_POST['agenda_mode']) || ($_POST['agenda_mode'] ?? '') !== 'new') ? 'checked' : ''; ?>> Perbarui agenda dipilih
			</label>
			<label style="margin:0; display:flex; gap:6px; align-items:center; color: var(--muted); font-size:13px;">
				<input type="radio" name="agenda_mode" value="new" <?php echo (($_POST['agenda_mode'] ?? '') === 'new') ? 'checked' : ''; ?>> Simpan sebagai agenda baru
			</label>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Saat ini mengedit agenda ke-<?php echo (int) $editingIdx + 1; ?> (klik Edit di daftar untuk memilih).</p>
		<div class="inline-actions" style="margin-top: 4px;">
			<button type="submit">Simpan agenda</button>
		</div>
	</form>
	<div class="admin-box" style="margin-top:10px;">
		<h3 style="margin:0 0 8px; font-size:15px;">Agenda aktif (diputar bergantian)</h3>
		<?php if (empty($agendas)): ?>
			<p class="muted-text" style="margin:0;">Belum ada agenda aktif.</p>
		<?php else: ?>
			<ul class="logs" style="margin-top:6px;">
				<?php foreach ($agendas as $idx => $item): ?>
					<li style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
						<div>
							<strong><?php echo htmlspecialchars($item['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
							<br><span style="color: var(--muted); font-size:12px;"><?php echo htmlspecialchars($item['tag'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
							<br><span style="color: var(--muted); font-size:12px;">Detail: <?php echo htmlspecialchars($item['detail'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
							<?php if ($idx === 0): ?><br><span style="font-size:12px; color: var(--accent);">(utama)</span><?php endif; ?>
						</div>
						<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
							<form method="post" style="margin:0;">
								<input type="hidden" name="edit_idx" value="<?php echo $idx; ?>">
								<button type="submit" style="min-width:80px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Edit</button>
							</form>
							<form method="post" style="margin:0;">
								<input type="hidden" name="archive_idx" value="<?php echo $idx; ?>">
								<button type="submit" style="min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Arsipkan</button>
							</form>
							<form method="post" style="margin:0;" onsubmit="return confirm('Hapus agenda ini?');">
								<input type="hidden" name="delete_idx" value="<?php echo $idx; ?>">
								<button type="submit" style="min-width:90px; background: linear-gradient(135deg, #f87171, #ef4444); color: #0b1727; box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35); border: none;">Hapus</button>
							</form>
							<div style="display:flex; gap:4px;">
								<form method="post" style="margin:0;">
									<input type="hidden" name="move_idx" value="<?php echo $idx; ?>">
									<input type="hidden" name="move_dir" value="up">
									<button type="submit" title="Naikkan urutan" style="min-width:40px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);" <?php echo $idx === 0 ? 'disabled' : ''; ?>>↑</button>
								</form>
								<form method="post" style="margin:0;">
									<input type="hidden" name="move_idx" value="<?php echo $idx; ?>">
									<input type="hidden" name="move_dir" value="down">
									<button type="submit" title="Turunkan urutan" style="min-width:40px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);" <?php echo ($idx === count($agendas)-1) ? 'disabled' : ''; ?>>↓</button>
								</form>
							</div>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<div class="admin-box" style="margin-top:8px;">
		<h3 style="margin:0 0 8px; font-size:15px;">Arsip agenda</h3>
		<?php if (empty($agendaArchive)): ?>
			<p class="muted-text" style="margin:0;">Belum ada arsip agenda.</p>
		<?php else: ?>
			<ul class="logs" style="margin-top:6px; max-height:220px; overflow:auto;">
				<?php foreach ($agendaArchive as $idx => $item): ?>
					<li style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
						<div>
							<strong><?php echo htmlspecialchars($item['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
							<br><span style="color: var(--muted); font-size:12px;"><?php echo htmlspecialchars($item['tag'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
							<br><span style="color: var(--muted); font-size:12px;">Detail: <?php echo htmlspecialchars($item['detail'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
							<?php if (!empty($item['archived_at'])): ?><br><span style="font-size:12px; color: var(--muted);">Diarsipkan: <?php echo htmlspecialchars($item['archived_at'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
						</div>
						<form method="post" style="margin:0;">
							<input type="hidden" name="restore_archive_idx" value="<?php echo $idx; ?>">
							<button type="submit" style="min-width:110px; background: linear-gradient(135deg, #38bdf8, #0ea5e9); color: #0b1727; box-shadow: 0 10px 30px rgba(14, 165, 233, 0.35);">Pulihkan</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="muted-text" style="margin:6px 0 0;">Menampilkan sampai 50 arsip terbaru. Mengarsipkan agenda baru akan memotong yang paling lama.</p>
		<?php endif; ?>
	</div>
</div>
<script>
(function () {
	const form = document.getElementById('agenda-form');
	if (!form) return;
	const key = 'admin_autosave_agenda';
	const save = () => {
		const payload = {};
		form.querySelectorAll('input, textarea, select').forEach((el) => {
			if (!el.name || el.type === 'hidden' || el.type === 'submit') return;
			if (el.type === 'radio') {
				if (el.checked) payload[el.name] = el.value;
				return;
			}
			payload[el.name] = el.value;
		});
		localStorage.setItem(key, JSON.stringify(payload));
	};
	const raw = localStorage.getItem(key);
	if (raw) {
		try {
			const data = JSON.parse(raw);
			Object.keys(data).forEach((name) => {
				form.querySelectorAll(`[name="${name}"]`).forEach((el) => {
					if (el.type === 'radio') {
						el.checked = String(el.value) === String(data[name]);
					} else if (typeof data[name] === 'string') {
						el.value = data[name];
					}
				});
			});
		} catch (_) {}
	}
	form.addEventListener('input', save);
	form.addEventListener('change', save);
	form.addEventListener('submit', () => localStorage.removeItem(key));
})();
</script>
