(() => {
  const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  function addSearch(target, index) {
    const wrapper = document.createElement('div');
    wrapper.className = 'mb-3 space-y-1.5';
    const label = document.createElement('label');
    label.className = 'block text-xs font-bold text-slate-700';
    const search = document.createElement('input');
    search.id = `resident-picker-search-${index}`;
    search.type = 'search';
    search.autocomplete = 'off';
    search.placeholder = 'Search by name, patient number, or barangay';
    search.className = 'w-full rounded-xl border border-teal-200 bg-white px-3 py-3 text-sm font-medium text-slate-800 outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-200';
    label.htmlFor = search.id;
    label.textContent = 'Search residents';
    const status = document.createElement('p');
    status.id = `${search.id}-status`;
    status.className = 'text-[11px] text-slate-500';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    search.setAttribute('aria-describedby', status.id);
    wrapper.append(label, search, status);
    target.before(wrapper);
    return {search, status};
  }
  function initializeResidentSearch() {
  // Shared patient selectors across every staff department and record form.
  document.querySelectorAll('select[name="resident_id"], select[name="mother_id"], select[name="patient_id"], select[name="child_id"], select[name="primary_resident_id"], select[name="duplicate_resident_id"], select[data-resident-search]').forEach((select, index) => {
    if (select.dataset.residentSearchReady) return;
    if (!select.id) select.id = `resident-picker-${index}`;
    if (Array.from(document.querySelectorAll('[data-resident-select-search]')).some(search => search.dataset.residentSelectSearch === select.id)) return;
    const {search} = addSearch(select, `select-${index}`);
    search.dataset.residentSelectSearch = select.id;
    search.setAttribute('aria-controls', select.id);
  });
  document.querySelectorAll('[data-resident-select-search]').forEach(search => {
    const select = document.getElementById(search.dataset.residentSelectSearch);
    const status = document.getElementById(search.getAttribute('aria-describedby'));
    if (!select || !status || select.dataset.residentSearchReady) return;
    select.dataset.residentSearchReady = 'true';
    const options = Array.from(select.options, (option, index) => {
      option.dataset.residentSearchKey = String(index);
      return option.cloneNode(true);
    });
    // Legacy lab appointments can have ID 0 but still identify a real patient.
    const placeholder = option => option.value === '' || (option.value === '0' && !option.dataset.residentId);
    const results = document.createElement('div');
    results.id = `${search.id}-results`;
    results.setAttribute('role', 'group');
    results.setAttribute('aria-label', 'Matching residents');
    results.style.cssText = 'display:none;max-height:240px;overflow-y:auto;overscroll-behavior:contain;margin-top:8px;border:1px solid #cce3da;border-radius:12px;background:#fff;box-shadow:0 6px 18px #0d443510';
    search.insertAdjacentElement('afterend', results);
    search.setAttribute('aria-controls', results.id);
    function closeResults() {
      results.style.display = 'none';
      search.setAttribute('aria-expanded', 'false');
    }
    function filter(showResults = false) {
      const selected = select.value;
      const selectedKey = select.selectedOptions[0]?.dataset.residentSearchKey;
      const terms = normalize(search.value.trim()).split(/\s+/).filter(Boolean);
      const matches = options.filter(option => !placeholder(option) && !option.disabled && terms.every(term => normalize(`${option.textContent} ${option.dataset.barangay || ''} ${option.title || ''}`).includes(term)));
      const visible = options.filter(option => placeholder(option) || option.dataset.residentSearchKey === selectedKey || matches.includes(option));
      select.replaceChildren(...visible.map(option => option.cloneNode(true)));
      select.selectedIndex = Array.from(select.options).findIndex(option => option.dataset.residentSearchKey === selectedKey);
      const selectedOutsideResults = selected !== '0' && selected !== '' && !matches.some(option => option.dataset.residentSearchKey === selectedKey);
      status.textContent = `${matches.length} matching resident${matches.length === 1 ? '' : 's'}.${selectedOutsideResults ? ' Your current selection is kept.' : matches.length === 0 ? ' Try another name, patient number, or barangay.' : ' Select a resident below.'}`;
      results.replaceChildren();
      matches.forEach(option => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'block w-full border-b border-slate-100 px-4 py-3 text-left text-sm font-semibold text-slate-800 hover:bg-teal-50 focus:bg-teal-50 focus:outline-none';
        button.textContent = option.textContent;
        button.title = option.title || option.textContent;
        button.disabled = select.disabled;
        button.addEventListener('click', () => {
          if (select.disabled) return;
          select.selectedIndex = Array.from(select.options).findIndex(item => item.dataset.residentSearchKey === option.dataset.residentSearchKey);
          select.dispatchEvent(new Event('change', {bubbles:true}));
          closeResults();
          status.textContent = `Selected: ${option.textContent.trim()}`;
        });
        results.appendChild(button);
      });
      if (!matches.length) {
        const empty = document.createElement('p');
        empty.className = 'px-4 py-4 text-sm text-slate-500';
        empty.textContent = 'No matching residents. Try another name, patient number, or barangay.';
        results.appendChild(empty);
      }
      if (showResults && terms.length) {
        results.style.display = 'block';
        search.setAttribute('aria-expanded', 'true');
      } else closeResults();
    }
    search.addEventListener('input', () => filter(true));
    search.addEventListener('search', () => filter(true));
    search.addEventListener('focus', () => filter(true));
    search.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown') { event.preventDefault(); results.querySelector('button')?.focus(); }
      if (event.key === 'Escape') closeResults();
    });
    results.addEventListener('keydown', event => {
      const buttons = Array.from(results.querySelectorAll('button'));
      const index = buttons.indexOf(document.activeElement);
      if (event.key === 'ArrowDown') { event.preventDefault(); buttons[Math.min(index + 1, buttons.length - 1)]?.focus(); }
      if (event.key === 'ArrowUp') { event.preventDefault(); if (index <= 0) search.focus(); else buttons[index - 1]?.focus(); }
      if (event.key === 'Escape') { search.focus(); closeResults(); }
    });
    document.addEventListener('click', event => {
      if (event.target !== search && !results.contains(event.target)) closeResults();
    });
    select.addEventListener('change', () => filter(false));
    filter();
  });
  // OPD uses a custom resident list rather than a native select.
  document.querySelectorAll('[data-resident-combobox]').forEach((combo, index) => {
    if (combo.dataset.residentSearchReady) return;
    combo.dataset.residentSearchReady = 'true';
    const {search, status} = addSearch(combo, `combo-${index}`);
    // Keep the search inside the picker so its outside-click handler stays open.
    combo.prepend(search.parentElement);
    const options = Array.from(combo.querySelectorAll('[data-resident-combobox-option]'));
    function filter() {
      const terms = normalize(search.value.trim()).split(/\s+/).filter(Boolean);
      let count = 0;
      options.forEach(option => {
        const text = normalize(`${option.textContent} ${option.title || ''}`);
        const matches = terms.every(term => text.includes(term));
        option.hidden = !matches;
        option.style.display = matches ? '' : 'none';
        if (matches) count++;
      });
      if (document.activeElement === search) {
        combo.classList.add('is-open');
        combo.querySelector('[data-resident-combobox-button]')?.setAttribute('aria-expanded', 'true');
      }
      status.textContent = `${count} matching resident${count === 1 ? '' : 's'}.${count ? ' Select a resident below.' : ' Try another name, patient number, or barangay.'}`;
    }
    search.addEventListener('input', filter);
    search.addEventListener('search', filter);
    search.addEventListener('focus', filter);
    filter();
  });
  }
  initializeResidentSearch();
  // Some staff modals create patient selectors after the initial page load.
  const observer = new MutationObserver(changes => {
    const hasNewPicker = changes.some(change => Array.from(change.addedNodes).some(node => node.nodeType === 1 && (node.matches('select, [data-resident-combobox], [data-resident-select-search]') || node.querySelector('select, [data-resident-combobox], [data-resident-select-search]'))));
    if (hasNewPicker) initializeResidentSearch();
  });
  observer.observe(document.body, {childList:true, subtree:true});
})();
