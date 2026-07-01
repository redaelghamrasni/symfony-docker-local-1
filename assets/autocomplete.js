const MEILISEARCH_URL = 'http://localhost:7700';
const MEILISEARCH_KEY = 'changeme_master_key_dev';
const RESULTS_LIMIT = 6;
const DEBOUNCE_MS = 220;

function initAutocomplete() {
    const input = document.getElementById('nav-search-input');
    const dropdown = document.getElementById('nav-search-dropdown');
    if (!input || !dropdown || input.dataset.acInit) return;
    input.dataset.acInit = '1';

    const locale = input.dataset.locale || 'fr';
    const searchPageUrl = input.dataset.searchUrl || `/${locale}/search`;
    const labelViewAll = input.dataset.labelViewAll || 'See all results';
    const labelNoResults = input.dataset.labelNoResults || 'No results.';

    let debounceTimer = null;
    let lastQuery = '';

    input.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(debounceTimer);
        if (!query) { hide(); return; }
        debounceTimer = setTimeout(() => fetchResults(query), DEBOUNCE_MS);
    });

    // Re-show dropdown on focus if there is already content
    input.addEventListener('focus', () => {
        if (input.value.trim() && dropdown.children.length) show();
    });

    // Delay hiding so clicks on dropdown items register first
    input.addEventListener('blur', () => {
        setTimeout(hide, 180);
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            const q = input.value.trim();
            if (q) window.location.href = `${searchPageUrl}?q=${encodeURIComponent(q)}`;
        }
        if (e.key === 'Escape') {
            hide();
            input.blur();
        }
    });

    async function fetchResults(query) {
        lastQuery = query;
        try {
            const res = await fetch(`${MEILISEARCH_URL}/indexes/articles/search`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${MEILISEARCH_KEY}`,
                },
                body: JSON.stringify({ q: query, limit: RESULTS_LIMIT }),
            });
            if (!res.ok || query !== lastQuery) return;
            const { hits = [] } = await res.json();
            render(hits, query);
        } catch {
            // MeiliSearch not reachable
        }
    }

    function render(hits, query) {
        dropdown.innerHTML = '';

        if (!hits.length) {
            const empty = document.createElement('div');
            empty.className = 'nav-search-empty';
            empty.textContent = labelNoResults;
            dropdown.appendChild(empty);
            show();
            return;
        }

        hits.forEach(hit => {
            const a = document.createElement('a');
            a.href = `/${locale}/article/${hit.id}`;
            a.className = 'nav-search-item';

            const title = document.createElement('span');
            title.className = 'nav-search-item-title';
            title.textContent = hit.title;

            const price = document.createElement('span');
            price.className = 'nav-search-item-price';
            price.textContent = `${hit.price} $`;

            a.appendChild(title);
            a.appendChild(price);
            dropdown.appendChild(a);
        });

        const footer = document.createElement('a');
        footer.href = `${searchPageUrl}?q=${encodeURIComponent(query)}`;
        footer.className = 'nav-search-footer';
        footer.innerHTML = `<i class="bi bi-search"></i> ${labelViewAll}`;
        dropdown.appendChild(footer);

        show();
    }

    function show() { dropdown.style.display = 'block'; }
    function hide() { dropdown.style.display = 'none'; }
}

document.addEventListener('DOMContentLoaded', initAutocomplete);
document.addEventListener('turbo:load', initAutocomplete);
