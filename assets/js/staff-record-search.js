/* Local filtering of records already authorized and rendered by the server. */
(function () {
    'use strict';
    const normalize = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    const matches = (text, query) => normalize(query).split(' ').filter(Boolean).every(word => normalize(text).includes(word));
    if (typeof module !== 'undefined') module.exports = { normalize, matches };
    if (typeof document === 'undefined') return;
    const groups = new WeakMap();
    let serial = 0;
    const searchableInput = root => Array.from(root.querySelectorAll('input')).some(input =>
        input.type === 'search' || /search/i.test(input.placeholder + ' ' + input.getAttribute('aria-label')));
    function install(collection, getRows, anchor, label) {
        if (groups.has(collection)) return;
        groups.set(collection, true);
        const bar = document.createElement('div');
        bar.className = 'staff-record-search';
        const id = 'staff-record-search-' + (++serial);
        const caption = document.createElement('label');
        caption.htmlFor = id;
        caption.textContent = 'Search ' + label;
        const controls = document.createElement('div');
        controls.className = 'staff-record-search-controls';
        const input = document.createElement('input');
        input.id = id; input.type = 'search';
        input.placeholder = 'Search name, date, service, or status...';
        input.autocomplete = 'off';
        const clear = document.createElement('button');
        clear.type = 'button'; clear.textContent = 'Clear';
        const status = document.createElement('span');
        status.className = 'staff-record-search-status';
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        const hint = document.createElement('small');
        hint.textContent = 'Search records loaded in this list.';
        controls.append(input, clear, status); bar.append(caption, controls, hint);
        anchor.before(bar);
        const empty = document.createElement('p');
        empty.className = 'staff-record-search-empty'; empty.hidden = true;
        empty.textContent = 'No records match your search. Clear the search to see all records.';
        anchor.after(empty);
        const render = () => {
            const rows = getRows();
            let found = 0;
            rows.forEach(row => {
                const match = matches(row.dataset.searchText || row.textContent, input.value);
                row.classList.toggle('staff-record-search-excluded', !match);
                if (match) found++;
            });
            const message = found + ' of ' + rows.length + ' records match';
            if (status.textContent !== message) status.textContent = message;
            clear.disabled = !input.value;
            empty.hidden = !input.value.trim() || found > 0;
        };
        input.addEventListener('input', render);
        clear.addEventListener('click', () => { input.value = ''; render(); input.focus(); });
        collection.addEventListener('staff-record-search-refresh', render);
        render();
    }
    function heading(element) {
        const section = element.closest('section');
        return (section?.querySelector('h2, h3, h4')?.textContent || 'records').trim().replace(/\s+/g, ' ').slice(0, 90);
    }
    function initialize() {
        document.querySelectorAll('main table').forEach(table => {
            if (table.closest('.resident-record-group')) return;
            if (table.closest('dialog, [role="dialog"], [data-no-record-search]')) return;
            // Existing search/pagination owns its table; avoid competing visibility rules.
            if (table.closest('[data-certificate-cleanup-panel]') || table.dataset.consultationPaginationBound === '1') return;
            let scope = table.parentElement;
            for (let depth = 0; scope && depth < 3 && scope.tagName !== 'MAIN'; depth++, scope = scope.parentElement) {
                if (scope.querySelectorAll('table').length !== 1) break;
                if (searchableInput(scope) && !scope.querySelector('.staff-record-search')) return;
            }
            const body = table.tBodies[0];
            if (!body) return;
            const rows = () => Array.from(table.tBodies).flatMap(body => Array.from(body.rows)).filter(row =>
                row.hasAttribute('data-record-search-row') || !row.querySelector('td[colspan]') || row.cells.length > 1);
            const wrapper = /overflow/.test(table.parentElement.className) ? table.parentElement : table;
            install(table, rows, wrapper, heading(table));
        });
        document.querySelectorAll('[data-record-search-row]').forEach(row => {
            if (row.tagName === 'TR') return;
            const collection = row.parentElement;
            install(collection, () => Array.from(collection.children).filter(child => child.hasAttribute('data-record-search-row')), collection, heading(collection));
        });
    }
    function start() {
        initialize();
        new MutationObserver(changes => {
            if (changes.some(change => Array.from(change.addedNodes).some(node => node.nodeType === 1 &&
                (node.matches('table, tr, [data-record-search-row]') || node.querySelector('table, [data-record-search-row]'))))) {
                initialize();
                document.querySelectorAll('main table, [data-record-search-row]').forEach(node =>
                    (node.matches('table') ? node : node.parentElement).dispatchEvent(new Event('staff-record-search-refresh')));
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
