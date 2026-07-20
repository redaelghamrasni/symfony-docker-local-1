const MEILISEARCH_URL = 'http://localhost:7700';
const MEILISEARCH_KEY = 'changeme_master_key_dev';
const SEARCH_LIMIT = 50;
const DEBOUNCE_MS = 220;

/**
 * Generic admin list search: types into an input, queries the matching
 * Meilisearch index client-side, then asks the admin controller to render
 * the matched rows server-side (so category/date/CSRF-protected actions stay
 * correct) via `?ids=1,2,3`. Clearing the input restores the original view.
 *
 * Wire a new admin list into this by wrapping its markup in a container with
 * `data-admin-search` + `data-meili-index` + `data-index-url`, and tagging the
 * pieces with `data-admin-search-*` (see templates/admin/articles/index.html.twig).
 */
function initAdminSearch(container) {
    if (container.dataset.searchInit) return;
    container.dataset.searchInit = '1';

    const input = container.querySelector('[data-admin-search-input]');
    if (!input) return;

    const meiliIndex   = container.dataset.meiliIndex;
    const baseIndexUrl = container.dataset.indexUrl;
    const countTemplate     = container.dataset.countTemplate || '';
    const noResultsTemplate = container.dataset.searchEmptyTemplate || '';

    const listEl         = container.querySelector('[data-admin-search-list]');
    const tbody          = container.querySelector('[data-admin-search-tbody]');
    const emptyEl        = container.querySelector('[data-admin-search-empty]');
    const noResultsEl    = container.querySelector('[data-admin-search-noresults]');
    const noResultsTitle = container.querySelector('[data-admin-search-noresults-title]');
    const countEl        = container.querySelector('[data-admin-search-count]');
    const loadMoreWrap   = container.querySelector('[data-admin-search-loadmore-wrap]');
    const loadMoreBtn    = container.querySelector('[data-admin-search-loadmore-btn]');

    let debounceTimer = null;
    let lastQuery = '';

    function show(el) { if (el) el.style.display = ''; }
    function hide(el) { if (el) el.style.display = 'none'; }

    function showTableState() {
        show(listEl); hide(emptyEl); hide(noResultsEl);
    }

    function showEmptyState() {
        hide(listEl); show(emptyEl); hide(noResultsEl); hide(loadMoreWrap);
    }

    function showNoResultsState(query) {
        hide(listEl); hide(emptyEl); hide(loadMoreWrap);
        if (noResultsTitle) {
            noResultsTitle.textContent = noResultsTemplate.replace('__QUERY__', query);
        }
        show(noResultsEl);
    }

    function updateCount(count) {
        if (countEl && countTemplate) {
            countEl.textContent = countTemplate.replace('__COUNT__', count);
        }
    }

    function withIds(ids) {
        const sep = baseIndexUrl.includes('?') ? '&' : '?';
        return `${baseIndexUrl}${sep}ids=${encodeURIComponent(ids)}`;
    }

    async function fetchRows(url) {
        const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!resp.ok) throw new Error('request failed');
        return resp.json();
    }

    async function runSearch(query) {
        lastQuery = query;
        try {
            const res = await fetch(`${MEILISEARCH_URL}/indexes/${meiliIndex}/search`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${MEILISEARCH_KEY}`,
                },
                // Strip a leading '#' so typing an order number like "#123" still matches its id.
                body: JSON.stringify({ q: query.replace(/^#/, ''), limit: SEARCH_LIMIT }),
            });
            if (!res.ok || query !== lastQuery) return;
            const { hits = [] } = await res.json();

            if (hits.length === 0) {
                updateCount(0);
                showNoResultsState(query);
                return;
            }

            const ids = hits.map(hit => hit.id).join(',');
            const data = await fetchRows(withIds(ids));
            if (query !== lastQuery) return;

            tbody.innerHTML = data.html;
            updateCount(hits.length);
            hide(loadMoreWrap);
            showTableState();
        } catch (e) {
            // Meilisearch unreachable — leave the current view untouched
        }
    }

    async function resetToDefault() {
        try {
            const data = await fetchRows(baseIndexUrl);
            tbody.innerHTML = data.html;
            updateCount(data.total);

            if (data.html.trim() === '') {
                showEmptyState();
                return;
            }

            showTableState();
            if (loadMoreBtn) {
                loadMoreBtn.dataset.offset = data.nextOffset ?? 0;
                loadMoreBtn.disabled = false;
            }
            if (loadMoreWrap) {
                loadMoreWrap.style.display = data.hasMore ? '' : 'none';
            }
        } catch (e) {
            // ignore, keep current view
        }
    }

    input.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(debounceTimer);
        if (!query) {
            lastQuery = '';
            resetToDefault();
            return;
        }
        debounceTimer = setTimeout(() => runSearch(query), DEBOUNCE_MS);
    });

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', async () => {
            const offset = loadMoreBtn.dataset.offset;
            const sep = baseIndexUrl.includes('?') ? '&' : '?';
            const url = `${baseIndexUrl}${sep}offset=${offset}`;

            const originalLabel = loadMoreBtn.textContent;
            loadMoreBtn.disabled = true;
            loadMoreBtn.textContent = loadMoreBtn.dataset.loadingLabel || originalLabel;

            try {
                const data = await fetchRows(url);
                tbody.insertAdjacentHTML('beforeend', data.html);

                if (data.hasMore) {
                    loadMoreBtn.dataset.offset = data.nextOffset;
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.textContent = originalLabel;
                } else if (loadMoreWrap) {
                    loadMoreWrap.style.display = 'none';
                }
            } catch (e) {
                loadMoreBtn.disabled = false;
                loadMoreBtn.textContent = originalLabel;
            }
        });
    }
}

function initAll() {
    document.querySelectorAll('[data-admin-search]').forEach(initAdminSearch);
}

document.addEventListener('DOMContentLoaded', initAll);
document.addEventListener('turbo:load', initAll);
