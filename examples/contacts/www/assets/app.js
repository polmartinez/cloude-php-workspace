// Live search for the home page.
//
// Talks to GET /api/search?q=… (JSON) with a 200 ms debounce so we don't
// fire a request on every keystroke. The endpoint is the same one a CLI
// or another service would hit — the page just renders its results.

(function () {
    const input = document.querySelector('[data-search]');
    if (!input) return; // not on the home page

    const list  = document.querySelector('[data-results]');
    const empty = document.querySelector('[data-empty]');
    const count = document.querySelector('[data-count]');

    let timer = null;
    let lastQuery = null;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(runSearch, 200);
    });

    async function runSearch() {
        const q = input.value.trim();
        if (q === lastQuery) return;
        lastQuery = q;

        const url = '/api/search?q=' + encodeURIComponent(q);
        let data;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            data = await res.json();
        } catch (err) {
            console.warn('search failed', err);
            return;
        }

        list.innerHTML = data.results.map(renderItem).join('');
        if (empty) empty.hidden = data.count > 0;
        if (count) count.textContent =
            data.count + ' contact' + (data.count === 1 ? '' : 's') +
            (q ? ' matching "' + q + '"' : '');
    }

    function renderItem(c) {
        const meta = [];
        if (c.email) meta.push(`<span class="contact-meta">${esc(c.email)}</span>`);
        if (c.phone) meta.push(`<span class="contact-meta">${esc(c.phone)}</span>`);
        return `
            <li class="contact">
                <a href="${esc(c.url)}" class="contact-name">${esc(c.name)}</a>
                ${meta.join('')}
            </li>
        `;
    }

    // Tiny HTML escaper — never inject user-supplied strings into innerHTML
    // without going through this.
    function esc(s) {
        return String(s).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[ch]));
    }
})();
