(() => {
  const regions = new Map();
  function update(region) {
    const hint = regions.get(region);
    const hidden = region.scrollHeight <= region.clientHeight + 2;
    if (hint && hint.hidden !== hidden) hint.hidden = hidden;
  }
  const resize = typeof ResizeObserver === 'function' ? new ResizeObserver(entries => {
    entries.forEach(({target}) => update(target));
  }) : null;
  function install(region, label, table = false) {
    if (regions.has(region)) return;
    region.classList.add('staff-record-scroll', table ? 'staff-record-scroll-table' : 'staff-record-scroll-list');
    if (!region.hasAttribute('tabindex')) region.setAttribute('tabindex', '0');
    if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
    if (!region.hasAttribute('aria-label') && !region.hasAttribute('aria-labelledby')) region.setAttribute('aria-label', label);
    const hint = document.createElement('p');
    hint.className = 'staff-record-scroll-hint';
    hint.textContent = 'Scroll inside this list to view more records.';
    hint.hidden = true;
    region.before(hint);
    regions.set(region, hint);
    resize?.observe(region);
    requestAnimationFrame(() => update(region));
  }
  function initialize() {
    document.querySelectorAll('[data-staff-record-scroll], .resident-record-details, .admin-consultation-detail-wrap').forEach(region => {
      if (region.closest('[data-triage-workbench]')) return;
      install(region, region.dataset.staffRecordScroll || 'Resident records');
    });
    document.querySelectorAll('[data-record-search-row]').forEach(row => {
      const collection = row.parentElement;
      if (!collection || collection.matches('main, body, table, tbody, tr') || collection.closest('.staff-record-scroll, [data-triage-workbench]')) return;
      install(collection, 'Resident record list');
    });
    document.querySelectorAll('main table').forEach(table => {
      // Examination/prescription tables belong to the enclosing record sheet.
      if (table.closest('.history-entry, .service-record-sheet, .dental-prescription-sheet, .dental-exam-sheet, .staff-record-scroll, [data-no-record-scroll], [data-triage-workbench]')) return;
      if (!table.tBodies.length) return;
      let wrapper = table.parentElement;
      if (!/overflow/.test(wrapper.className) || wrapper.querySelectorAll('table').length !== 1) {
        wrapper = document.createElement('div');
        table.before(wrapper);
        wrapper.appendChild(table);
      }
      install(wrapper, 'Resident records table', true);
    });
    regions.forEach((hint, region) => {
      if (!region.isConnected) { resize?.unobserve(region); regions.delete(region); return; }
      update(region);
    });
  }
  let scheduled = false;
  function refresh() {
    if (scheduled) return;
    scheduled = true;
    queueMicrotask(() => { scheduled = false; initialize(); });
  }
  function start() {
    initialize();
    new MutationObserver(refresh).observe(document.body, {childList:true,subtree:true,attributes:true,attributeFilter:['class','hidden','open']});
    window.addEventListener('resize', refresh);
    document.addEventListener('toggle', refresh, true);
    document.addEventListener('input', refresh);
    document.addEventListener('change', refresh);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();
})();
