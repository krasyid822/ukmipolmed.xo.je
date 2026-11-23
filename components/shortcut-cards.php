<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['save_shortcut_cards'])) {
		$rawCards = (array) ($_POST['shortcut_cards'] ?? []);
		$newCards = [];
		$cardLimit = 8;
		$slideLimit = 8;
		foreach ($rawCards as $card) {
			if (!is_array($card)) continue;
			$slides = [];
			foreach ((array) ($card['slides'] ?? []) as $slide) {
				if (!is_array($slide)) continue;
				$tag = trim((string) ($slide['tag'] ?? ''));
				$title = trim((string) ($slide['title'] ?? ''));
				$detail = trim((string) ($slide['detail'] ?? ''));
				if ($tag === '' && $title === '' && $detail === '') continue;
				$slides[] = [
					'tag' => $tag,
					'title' => $title,
					'detail' => $detail,
				];
				if (count($slides) >= $slideLimit) break;
			}
			if (empty($slides)) continue;
			$newCards[] = ['slides' => $slides];
			if (count($newCards) >= $cardLimit) break;
		}

		$config['shortcut_cards'] = $newCards;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan kartu Baca ini.';
		} else {
			$shortcutCards = $newCards;
			$message = 'Kartu Baca ini diperbarui.';
			appendLog($config, $configPath, 'shortcut-cards-updated', $maxLogs, $archiveLimitDays);
		}
	}
	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Kartu "Baca ini.."</h2>
	<p class="muted-text" style="margin:0 0 10px;">Semua kartu di section "Baca ini.." pada beranda dikelola di sini. Kartu pertama adalah agenda terdekat, lalu kartu slide tambahan yang berganti otomatis dengan animasi membalik.</p>
	<div class="admin-box" style="margin-top:10px;">
		<?php require __DIR__ . '/agenda-terdekat.php'; ?>
	</div>
	<div class="admin-box" style="margin-top:10px;">
		<h3 style="margin:0 0 8px; font-size:15px;">Kartu slide tambahan</h3>
		<p class="muted-text" style="margin:0 0 10px;">Setiap kartu bisa berisi beberapa slide yang berganti otomatis dengan animasi membalik. Tambahkan kartu sebanyak yang dibutuhkan (maksimal 8 kartu, 8 slide per kartu).</p>
	<form method="post" id="shortcut-cards-form"><?php echo ukmi_csrf_field(); ?>
		<div id="shortcut-card-list" class="stack-sm">
			<?php foreach ($shortcutCards as $cardIdx => $shortcutCard): ?>
			<div class="shortcut-card-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px;">
					<strong class="shortcut-card-index" style="font-size:13px; color: var(--muted);">Kartu <?php echo $cardIdx + 1; ?></strong>
					<button type="button" class="remove-shortcut-card" style="width:auto; min-width:70px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus kartu</button>
				</div>
				<div class="shortcut-slide-list field-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px;">
					<?php foreach (($shortcutCard['slides'] ?? []) as $slideIdx => $slide): ?>
					<div class="shortcut-slide-item" style="border:1px dashed rgba(148,163,184,0.25); padding:10px; border-radius:10px;">
						<strong class="shortcut-slide-index" style="display:block; font-size:12px; color: var(--muted); margin-bottom:6px;">Slide <?php echo $slideIdx + 1; ?></strong>
						<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Tag</label>
						<input type="text" data-field="tag" name="shortcut_cards[<?php echo (int) $cardIdx; ?>][slides][<?php echo (int) $slideIdx; ?>][tag]" value="<?php echo htmlspecialchars($slide['tag'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Skill Class">
						<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
						<input type="text" data-field="title" name="shortcut_cards[<?php echo (int) $cardIdx; ?>][slides][<?php echo (int) $slideIdx; ?>][title]" value="<?php echo htmlspecialchars($slide['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Judul slide">
						<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Detail</label>
						<textarea data-field="detail" name="shortcut_cards[<?php echo (int) $cardIdx; ?>][slides][<?php echo (int) $slideIdx; ?>][detail]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;" placeholder="Deskripsi singkat"><?php echo htmlspecialchars($slide['detail'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
						<div style="text-align:right; margin-top:6px;">
							<button type="button" class="remove-shortcut-slide" style="width:auto; min-width:70px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus slide</button>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<div style="margin-top:8px;">
					<button type="button" class="add-shortcut-slide" style="width:auto; min-width:120px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah slide</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="inline-actions" style="margin-top: 6px;">
			<button type="button" id="add-shortcut-card" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah kartu</button>
			<button type="submit" name="save_shortcut_cards" value="1">Simpan kartu Baca ini</button>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Kartu akan tampil di section "Baca ini.." pada beranda. Hanya kartu yang memiliki minimal satu slide terisi yang disimpan.</p>
	</form>
	</div>
</div>
<script>
(function () {
	const cardList = document.getElementById('shortcut-card-list');
	const addCard = document.getElementById('add-shortcut-card');
	if (!cardList || !addCard) return;
	const CARD_LIMIT = 8;
	const SLIDE_LIMIT = 8;

	const slideTemplate = () => `
		<div class="shortcut-slide-item" style="border:1px dashed rgba(148,163,184,0.25); padding:10px; border-radius:10px;">
			<strong class="shortcut-slide-index" style="display:block; font-size:12px; color: var(--muted); margin-bottom:6px;">Slide</strong>
			<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Tag</label>
			<input type="text" data-field="tag" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Skill Class">
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
			<input type="text" data-field="title" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Judul slide">
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Detail</label>
			<textarea data-field="detail" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;" placeholder="Deskripsi singkat"></textarea>
			<div style="text-align:right; margin-top:6px;">
				<button type="button" class="remove-shortcut-slide" style="width:auto; min-width:70px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus slide</button>
			</div>
		</div>`;

	const cardTemplate = () => `
		<div class="shortcut-card-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
			<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px;">
				<strong class="shortcut-card-index" style="font-size:13px; color: var(--muted);">Kartu</strong>
				<button type="button" class="remove-shortcut-card" style="width:auto; min-width:70px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus kartu</button>
			</div>
			<div class="shortcut-slide-list" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px;"></div>
			<div style="margin-top:8px;">
				<button type="button" class="add-shortcut-slide" style="width:auto; min-width:120px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah slide</button>
			</div>
		</div>`;

	function renumber() {
		Array.from(cardList.querySelectorAll('.shortcut-card-item')).forEach((card, cardIdx) => {
			const cardLabel = card.querySelector('.shortcut-card-index');
			if (cardLabel) cardLabel.textContent = 'Kartu ' + (cardIdx + 1);
			Array.from(card.querySelectorAll('.shortcut-slide-item')).forEach((slide, slideIdx) => {
				const slideLabel = slide.querySelector('.shortcut-slide-index');
				if (slideLabel) slideLabel.textContent = 'Slide ' + (slideIdx + 1);
				const prefix = `shortcut_cards[${cardIdx}][slides][${slideIdx}]`;
				slide.querySelectorAll('[data-field]').forEach((field) => {
					field.name = prefix + '[' + field.dataset.field + ']';
				});
			});
		});
		updateAddCardState();
	}

	function updateAddCardState() {
		addCard.disabled = cardList.querySelectorAll('.shortcut-card-item').length >= CARD_LIMIT;
		addCard.title = addCard.disabled ? 'Maksimal ' + CARD_LIMIT + ' kartu' : '';
	}

	function attachCardHandlers(card) {
		const slideList = card.querySelector('.shortcut-slide-list');
		const addSlide = card.querySelector('.add-shortcut-slide');
		const removeCard = card.querySelector('.remove-shortcut-card');
		if (addSlide && slideList) {
			addSlide.onclick = () => {
				if (slideList.children.length >= SLIDE_LIMIT) return;
				const wrapper = document.createElement('div');
				wrapper.innerHTML = slideTemplate();
				slideList.appendChild(wrapper.firstElementChild);
				attachAll(card);
				renumber();
			};
		}
		if (removeCard) {
			removeCard.onclick = () => {
				card.remove();
				renumber();
			};
		}
		card.querySelectorAll('.remove-shortcut-slide').forEach((btn) => {
			btn.onclick = () => {
				const slide = btn.closest('.shortcut-slide-item');
				if (!slide || !slideList || slideList.children.length <= 1) return;
				slide.remove();
				renumber();
			};
		});
	}

	function attachAll(card) {
		attachCardHandlers(card);
	}

	addCard.addEventListener('click', () => {
		if (cardList.querySelectorAll('.shortcut-card-item').length >= CARD_LIMIT) return;
		const wrapper = document.createElement('div');
		wrapper.innerHTML = cardTemplate();
		const card = wrapper.firstElementChild;
		cardList.appendChild(card);
		const slideList = card.querySelector('.shortcut-slide-list');
		const slideWrapper = document.createElement('div');
		slideWrapper.innerHTML = slideTemplate();
		slideList.appendChild(slideWrapper.firstElementChild);
		attachAll(card);
		renumber();
	});

	cardList.querySelectorAll('.shortcut-card-item').forEach(attachAll);
	renumber();
})();
</script>
