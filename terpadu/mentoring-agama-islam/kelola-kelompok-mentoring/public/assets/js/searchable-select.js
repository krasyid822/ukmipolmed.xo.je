/**
 * searchable-select.js — Custom Searchable Select Dropdown
 * Ringan, tanpa jQuery, mendukung pencarian responsif untuk elemen <select>
 */

function initSearchableSelect(selectElement) {
    if (!selectElement || selectElement.dataset.searchableInit === 'true') {
        return;
    }
    selectElement.dataset.searchableInit = 'true';
    selectElement.style.display = 'none';

    // Wrapper container
    const wrapper = document.createElement('div');
    wrapper.className = 'searchable-select-wrapper';

    // Trigger button
    const trigger = document.createElement('div');
    trigger.className = 'searchable-select-trigger';
    trigger.tabIndex = 0;
    
    const triggerText = document.createElement('span');
    triggerText.className = 'trigger-text';
    triggerText.textContent = selectElement.options[selectElement.selectedIndex]?.text || '-- Pilih --';
    
    const triggerArrow = document.createElement('span');
    triggerArrow.className = 'trigger-arrow';
    triggerArrow.innerHTML = '&#9662;';

    trigger.appendChild(triggerText);
    trigger.appendChild(triggerArrow);
    wrapper.appendChild(trigger);

    // Dropdown panel
    const panel = document.createElement('div');
    panel.className = 'searchable-select-panel';

    // Search input
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'searchable-select-search';
    searchInput.placeholder = 'Cari pilihan...';
    panel.appendChild(searchInput);

    // Options list container
    const list = document.createElement('div');
    list.className = 'searchable-select-list';
    panel.appendChild(list);

    function renderOptions(filterText = '') {
        list.innerHTML = '';
        const options = Array.from(selectElement.options);
        const lowerFilter = filterText.toLowerCase().trim();

        let visibleCount = 0;
        options.forEach(opt => {
            if (lowerFilter && !opt.text.toLowerCase().includes(lowerFilter)) {
                return;
            }
            visibleCount++;
            const item = document.createElement('div');
            item.className = 'searchable-select-item' + (opt.value === selectElement.value ? ' selected' : '');
            item.textContent = opt.text;
            item.dataset.value = opt.value;

            item.addEventListener('click', (e) => {
                e.stopPropagation();
                selectElement.value = opt.value;
                triggerText.textContent = opt.text;
                closeDropdown();
                selectElement.dispatchEvent(new Event('change', { bubbles: true }));
            });

            list.appendChild(item);
        });

        if (visibleCount === 0) {
            const empty = document.createElement('div');
            empty.className = 'searchable-select-empty';
            empty.textContent = 'Tidak ada hasil yang cocok';
            list.appendChild(empty);
        }
    }

    function openDropdown() {
        // Tutup dropdown lain
        document.querySelectorAll('.searchable-select-panel.active').forEach(p => p.classList.remove('active'));
        panel.classList.add('active');
        searchInput.value = '';
        renderOptions();
        setTimeout(() => searchInput.focus(), 50);
    }

    function closeDropdown() {
        panel.classList.remove('active');
    }

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        if (panel.classList.contains('active')) {
            closeDropdown();
        } else {
            openDropdown();
        }
    });

    searchInput.addEventListener('input', (e) => {
        renderOptions(e.target.value);
    });

    searchInput.addEventListener('click', (e) => {
        e.stopPropagation();
    });

    document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) {
            closeDropdown();
        }
    });

    wrapper.appendChild(panel);
    selectElement.parentNode.insertBefore(wrapper, selectElement.nextSibling);

    // Observer bila isi option diubah secara dinamis via JS
    const observer = new MutationObserver(() => {
        triggerText.textContent = selectElement.options[selectElement.selectedIndex]?.text || '-- Pilih --';
        renderOptions();
    });
    observer.observe(selectElement, { childList: true, subtree: true });

    return {
        update: () => {
            triggerText.textContent = selectElement.options[selectElement.selectedIndex]?.text || '-- Pilih --';
            renderOptions();
        }
    };
}
