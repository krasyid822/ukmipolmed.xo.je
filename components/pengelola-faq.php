<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && (isset($_POST['save_faqs']) || isset($_POST['faq_question']) || isset($_POST['faq_answer']))) {
		$questions = (array) ($_POST['faq_question'] ?? []);
		$answers = (array) ($_POST['faq_answer'] ?? []);
		$slugs = (array) ($_POST['faq_slug'] ?? []);
		$writtenAtValues = (array) ($_POST['faq_written_at'] ?? []);
		$updatedAtValues = (array) ($_POST['faq_updated_at'] ?? []);
		$newFaqs = [];
		$usedSlugs = [];
		$existingFaqsBySlug = [];
		foreach ((array) ($config['faqs'] ?? []) as $existingFaq) {
			if (!is_array($existingFaq)) continue;
			$existingSlug = trim((string) ($existingFaq['slug'] ?? ''));
			if ($existingSlug !== '') $existingFaqsBySlug[$existingSlug] = $existingFaq;
		}
		$nowStamp = date('c');
		foreach ($questions as $i => $questionVal) {
			$question = trim((string) $questionVal);
			$answer = trim((string) ($answers[$i] ?? ''));
			$slug = trim((string) ($slugs[$i] ?? ''));
			$writtenAt = trim((string) ($writtenAtValues[$i] ?? ''));
			$updatedAt = trim((string) ($updatedAtValues[$i] ?? ''));
			if ($question === '' && $answer === '') continue;
			if ($slug === '') {
				$slug = normalizeAdminFaqSlug($question !== '' ? $question : $answer);
			} else {
				$slug = normalizeAdminFaqSlug($slug);
			}
			$baseSlug = $slug;
			$counter = 2;
			while (isset($usedSlugs[$slug])) {
				$slug = $baseSlug . '-' . $counter;
				$counter++;
			}
			$usedSlugs[$slug] = true;
			$existingFaq = $existingFaqsBySlug[$slug] ?? null;
			if ($existingFaq === null) {
				$writtenTimestamp = $nowStamp;
				$updatedTimestamp = $nowStamp;
			} else {
				$writtenTimestamp = $writtenAt !== '' ? normalizeAdminFaqTimestamp($writtenAt) : $nowStamp;
				$contentChanged = trim((string) ($existingFaq['question'] ?? '')) !== $question
					|| trim((string) ($existingFaq['answer'] ?? '')) !== $answer;
				if ($contentChanged) {
					$updatedTimestamp = $nowStamp;
				} else {
					$updatedTimestamp = $updatedAt !== '' ? normalizeAdminFaqTimestamp($updatedAt) : $writtenTimestamp;
				}
			}
			$newFaqs[] = [
				'question' => $question ?: 'Pertanyaan',
				'answer' => $answer ?: 'Jawaban',
				'slug' => $slug,
				'written_at' => $writtenTimestamp,
				'updated_at' => $updatedTimestamp,
			];
		}
		$config['faqs'] = $newFaqs;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan FAQ.';
		} else {
			$faqs = $newFaqs;
			$message = 'FAQ diperbarui.';
			appendLog($config, $configPath, 'faqs-updated', $maxLogs, $archiveLimitDays);
		}
	}
	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Pengelola FAQ</h2>
	<p class="muted-text" style="margin:0 0 10px;">FAQ yang disimpan di sini akan dipakai halaman FAQ publik. Item bisa ditambah, diedit, atau dihapus langsung dari daftar ini.</p>
	<form method="post" class="stack-sm" id="faq-form" data-autosave-key="faqs"><?php echo ukmi_csrf_field(); ?>
		<div id="faq-list" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:10px;">
			<?php $faqItems = !empty($faqs) ? $faqs : [['question' => '', 'answer' => '', 'slug' => '', 'written_at' => '', 'updated_at' => '']]; ?>
			<?php foreach ($faqItems as $faqIdx => $faqItem): ?>
			<div class="faq-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Pertanyaan</label>
				<input type="text" name="faq_question[]" value="<?php echo htmlspecialchars($faqItem['question'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Tulis pertanyaan FAQ">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Jawaban</label>
				<textarea name="faq_answer[]" style="width:100%; min-height:110px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;" placeholder="Tulis jawaban FAQ"><?php echo htmlspecialchars($faqItem['answer'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
				<input type="hidden" name="faq_slug[]" value="<?php echo htmlspecialchars($faqItem['slug'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
				<input type="hidden" name="faq_updated_at[]" value="<?php echo htmlspecialchars($faqItem['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
				<?php
				$faqWrittenTs = !empty($faqItem['written_at']) ? strtotime((string) $faqItem['written_at']) : false;
				$faqWrittenLabel = $faqWrittenTs ? date('d M Y H:i', $faqWrittenTs) : 'Otomatis saat disimpan';
				$faqUpdatedTs = !empty($faqItem['updated_at']) ? strtotime((string) $faqItem['updated_at']) : false;
				$faqUpdatedLabel = $faqUpdatedTs ? date('d M Y H:i', $faqUpdatedTs) : 'Otomatis saat disimpan';
				?>
				<div class="field-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px; margin-top:8px;">
					<div>
						<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Ditulis</label>
						<p class="muted-text" style="margin:0; padding:10px 0; font-size:13px;"><?php echo htmlspecialchars($faqWrittenLabel, ENT_QUOTES, 'UTF-8'); ?></p>
					</div>
					<div>
						<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Diupdate</label>
						<p class="muted-text" style="margin:0; padding:10px 0; font-size:13px;"><?php echo htmlspecialchars($faqUpdatedLabel, ENT_QUOTES, 'UTF-8'); ?></p>
					</div>
				</div>
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-faq" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="inline-actions" style="margin-top: 6px;">
			<button type="button" id="add-faq" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah FAQ</button>
			<button type="submit" name="save_faqs" value="1">Simpan FAQ</button>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Slug, Ditulis, dan Diupdate terisi otomatis. Slug dan Ditulis ditetapkan saat kartu pertama kali disimpan, Diupdate ikut berubah saat isi FAQ diedit.</p>
	</form>
</div>
<script>
(function () {
	const faqList = document.getElementById('faq-list');
	const addFaq = document.getElementById('add-faq');
	if (!faqList || !addFaq) return;
	const escapeHtml = (value) => String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#039;');
	const faqTemplate = (question = '', answer = '', slug = '', writtenAt = '', updatedAt = '') => `
		<div class="faq-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
			<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Pertanyaan</label>
			<input type="text" name="faq_question[]" value="${escapeHtml(question)}" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Tulis pertanyaan FAQ">
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Jawaban</label>
			<textarea name="faq_answer[]" style="width:100%; min-height:110px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;" placeholder="Tulis jawaban FAQ">${escapeHtml(answer)}</textarea>
			<input type="hidden" name="faq_slug[]" value="${escapeHtml(slug)}">
			<input type="hidden" name="faq_written_at[]" value="${escapeHtml(writtenAt)}">
			<input type="hidden" name="faq_updated_at[]" value="${escapeHtml(updatedAt)}">
			<div class="field-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px; margin-top:8px;">
				<div>
					<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Ditulis</label>
					<p class="muted-text" style="margin:0; padding:10px 0; font-size:13px;">Otomatis saat disimpan</p>
				</div>
				<div>
					<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Diupdate</label>
					<p class="muted-text" style="margin:0; padding:10px 0; font-size:13px;">Otomatis saat disimpan</p>
				</div>
			</div>
			<div style="text-align:right; margin-top:6px;">
				<button type="button" class="remove-faq" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
			</div>
		</div>`;

	function attachFaqRemove() {
		faqList.querySelectorAll('.remove-faq').forEach((btn) => {
			btn.onclick = () => btn.closest('.faq-item')?.remove();
		});
	}
	function updateFaqState() {
		addFaq.disabled = faqList.children.length >= 50;
		addFaq.title = addFaq.disabled ? 'Maksimal 50 FAQ' : '';
	}
	addFaq.addEventListener('click', () => {
		if (faqList.children.length >= 50) return;
		const wrapper = document.createElement('div');
		wrapper.innerHTML = faqTemplate();
		faqList.appendChild(wrapper.firstElementChild);
		attachFaqRemove();
		updateFaqState();
	});
	attachFaqRemove();
	updateFaqState();
})();
</script>
