<?php
// Varian nama resmi UKMI, diurutkan dari paling ringkas ke paling lengkap.
if (!function_exists('ukmi_brand_variants')) {
    function ukmi_brand_variants(): array
    {
        return [
            'UKMI POLMED',
            'UKM Islam POLMED',
            'UKMI Politeknik Negeri Medan',
            'Unit Kegiatan Mahasiswa Islam POLMED',
            'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
        ];
    }
}

if (!function_exists('ukmi_brand_full_name')) {
    function ukmi_brand_full_name(): string
    {
        $variants = ukmi_brand_variants();
        return (string) end($variants);
    }
}

if (!function_exists('ukmi_brand_name')) {
    function ukmi_brand_name(int $level = 0): string
    {
        $variants = ukmi_brand_variants();
        $level = max(0, min($level, count($variants) - 1));
        return $variants[$level];
    }
}

// Markup brand yang otomatis memilih varian terpanjang yang muat (JS).
if (!function_exists('ukmi_brand_auto')) {
    function ukmi_brand_auto(string $tag = 'span', string $class = 'brand-title', array $attrs = []): string
    {
        $tag = preg_replace('/[^a-z0-9-]/i', '', $tag) ?: 'span';
        $variants = ukmi_brand_variants();
        $full = ukmi_brand_full_name();
        $esc = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $attr = '';
        foreach ($attrs as $name => $value) {
            $name = preg_replace('/[^a-z0-9:_-]/i', '', (string) $name);
            if ($name === '') {
                continue;
            }
            $attr .= ' ' . $name . '="' . $esc($value) . '"';
        }

        return '<' . $tag
            . ' class="' . $esc($class) . '"'
            . ' data-brand-auto'
            . ' data-brand-variants="' . $esc(json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
            . ' title="' . $esc($full) . '"'
            . ' aria-label="' . $esc($full) . '"'
            . $attr
            . '>' . $esc($variants[0]) . '</' . $tag . '>';
    }
}

// Aset (CSS + JS) hanya dicetak sekali per request.
if (!function_exists('ukmi_brand_assets')) {
    function ukmi_brand_assets(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        return <<<'HTML'
<style>
[data-brand-auto] {
	display: inline-block;
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	vertical-align: middle;
}
</style>
<script>
(() => {
	if (window.__ukmiBrandInit) {
		if (typeof window.__ukmiBrandFitAll === 'function') window.__ukmiBrandFitAll();
		return;
	}
	window.__ukmiBrandInit = true;
	let frame = 0;

	const fit = (el) => {
		let variants = [];
		try {
			variants = JSON.parse(el.getAttribute('data-brand-variants') || '[]');
		} catch (_) {
			variants = [];
		}
		if (!Array.isArray(variants) || variants.length === 0) return;

		const full = variants[variants.length - 1];
		const available = el.clientWidth;
		let best = variants[0];

		if (available > 1) {
			for (let i = variants.length - 1; i >= 0; i--) {
				el.textContent = variants[i];
				if (el.scrollWidth <= available + 1) {
					best = variants[i];
					break;
				}
			}
		}

		el.textContent = best;
		el.setAttribute('title', full);
		el.setAttribute('aria-label', full);
	};

	const fitAll = () => {
		document.querySelectorAll('[data-brand-auto]').forEach(fit);
	};

	window.__ukmiBrandFitAll = () => {
		if (frame) return;
		frame = requestAnimationFrame(() => {
			frame = 0;
			fitAll();
		});
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', window.__ukmiBrandFitAll);
	} else {
		window.__ukmiBrandFitAll();
	}

	window.addEventListener('resize', window.__ukmiBrandFitAll);
	if (document.fonts && document.fonts.ready) {
		document.fonts.ready.then(window.__ukmiBrandFitAll).catch(() => {});
	}
	if ('ResizeObserver' in window) {
		const observer = new ResizeObserver(window.__ukmiBrandFitAll);
		document.querySelectorAll('[data-brand-auto]').forEach((el) => {
			if (el.parentElement) observer.observe(el.parentElement);
		});
	}
})();
</script>
HTML;
    }
}
