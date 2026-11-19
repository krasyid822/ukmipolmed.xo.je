    <script>
    (function () {
        const links = Array.from(document.querySelectorAll('.faq-link'));
        const items = Array.from(document.querySelectorAll('.faq-item'));
        const searchInput = document.getElementById('faq-search');
        const emptyState = document.getElementById('empty-state');

        const getItem = (slug) => document.getElementById('faq-item-' + slug);
        const nav = document.getElementById('faq-nav');

        const visibleLinks = () => links.filter((link) => !link.classList.contains('hidden'));
        const visibleItems = () => items.filter((item) => !item.classList.contains('hidden'));

        const activate = (slug, updateHash = true) => {
            if (!slug) return;
            const target = getItem(slug);
            if (!target || target.classList.contains('hidden')) return;

            items.forEach((item) => item.classList.toggle('active', item.dataset.slug === slug));
            links.forEach((link) => link.classList.toggle('active', link.dataset.slug === slug));

            const activeLink = links.find((link) => link.dataset.slug === slug);
            if (activeLink) {
                activeLink.scrollIntoView({ block: 'nearest' });
            }
            target.scrollIntoView({ block: 'start', behavior: 'smooth' });

            if (updateHash) {
                history.replaceState(null, '', '#' + encodeURIComponent(slug));
            }
        };

        const filterFaq = (query) => {
            const q = (query || '').toLowerCase().trim();
            links.forEach((link) => {
                const searchable = (link.dataset.search || '').toLowerCase();
                const match = q === '' || searchable.indexOf(q) !== -1;
                link.classList.toggle('hidden', !match);
            });

            items.forEach((item) => {
                const searchable = (item.dataset.search || '').toLowerCase();
                const match = q === '' || searchable.indexOf(q) !== -1;
                item.classList.toggle('hidden', !match);
            });

            const hasVisible = visibleItems().length > 0;
            emptyState.style.display = hasVisible ? 'none' : 'block';

            const currentActive = document.querySelector('.faq-item.active:not(.hidden)');
            if (!currentActive && hasVisible) {
                const first = visibleLinks()[0];
                if (first) activate(first.dataset.slug, false);
            }
        };

        if (nav) {
            nav.addEventListener('click', (event) => {
                const link = event.target.closest('.faq-link');
                if (!link || !nav.contains(link) || link.classList.contains('hidden')) return;
                event.preventDefault();
                activate(link.dataset.slug, true);
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                filterFaq(searchInput.value);
            });
        }

        const hash = decodeURIComponent((location.hash || '').replace(/^#/, ''));
        filterFaq('');
        if (hash && getItem(hash)) {
            activate(hash, false);
        } else if (links.length > 0) {
            activate(links[0].dataset.slug, false);
        }
    })();
    </script>
