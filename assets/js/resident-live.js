(() => {
  'use strict';

  const panels = [...document.querySelectorAll('[data-tab-panel]')];
  if (!panels.length) return;
  const snapshots = new Map();
  let refreshing = false;
  const owned = 'form, input, textarea, select, script, [data-resident-qr], [data-live-profile], [data-resident-message-list], #records-list-container, #staff-notices-list, #staff-notices-count, #hero-event-bg, #hero-event-image-reset, #records-total-visits, #records-visits-this-year, #records-total-prescriptions, #records-total-count';

  // Keep existing nodes and their listeners while updating server-owned content.
  function reconcile(current, incoming) {
    // Lucide replaces server-rendered <i> tags with SVGs; keep the live icon.
    if (current.nodeType === Node.ELEMENT_NODE && current.hasAttribute('data-lucide')
      && incoming.nodeType === Node.ELEMENT_NODE
      && current.getAttribute('data-lucide') === incoming.getAttribute('data-lucide')) return;
    if (current.nodeType !== incoming.nodeType || current.nodeName !== incoming.nodeName) {
      current.replaceWith(incoming.cloneNode(true));
      return;
    }
    if (current.nodeType === Node.TEXT_NODE) {
      if (current.nodeValue !== incoming.nodeValue) current.nodeValue = incoming.nodeValue;
      return;
    }
    if (current.nodeType !== Node.ELEMENT_NODE || current.matches(owned) || current.matches('[role="dialog"], [id$="-modal"]')) return;
    if (current.hasAttribute('data-lucide')) return;

    const keepClass = current.hasAttribute('data-tab-panel') || current.hasAttribute('data-hero-slide') || current.classList.contains('hero-dot');
    const revealClasses = ['reveal-on-scroll', 'is-visible', 'dashboard-surface'].filter(name => current.classList.contains(name));
    for (const attribute of [...current.attributes]) {
      if (attribute.name === 'class' && keepClass) continue;
      if (!incoming.hasAttribute(attribute.name)) current.removeAttribute(attribute.name);
    }
    for (const attribute of [...incoming.attributes]) {
      if (attribute.name === 'class' && keepClass) continue;
      if (current.getAttribute(attribute.name) !== attribute.value) current.setAttribute(attribute.name, attribute.value);
    }
    current.classList.add(...revealClasses);
    const oldChildren = [...current.childNodes];
    const newChildren = [...incoming.childNodes];
    const retained = new Set();
    newChildren.forEach((child, index) => {
      const key = child.nodeType === Node.ELEMENT_NODE ? child.id : '';
      const match = key
        ? oldChildren.find(node => node.nodeType === Node.ELEMENT_NODE && node.id === key)
        : oldChildren[index]?.nodeType === child.nodeType
          && (oldChildren[index]?.nodeName === child.nodeName
            || (child.nodeType === Node.ELEMENT_NODE && child.hasAttribute('data-lucide')
              && oldChildren[index]?.getAttribute('data-lucide') === child.getAttribute('data-lucide')))
          && !oldChildren[index]?.id ? oldChildren[index] : undefined;
      if (match && !retained.has(match) && (!match.id || match.id === key)) {
        retained.add(match);
        if (current.childNodes[index] !== match) current.insertBefore(match, current.childNodes[index] || null);
        reconcile(match, child);
      } else {
        current.insertBefore(child.cloneNode(true), current.childNodes[index] || null);
      }
    });
    oldChildren.filter(child => !retained.has(child)).forEach(child => child.remove());
  }

  function restorePagination(panel) {
    const groups = new Set([...panel.querySelectorAll('[data-pagination-item]')].map(node => node.dataset.paginationItem));
    groups.forEach(group => {
      const items = [...panel.querySelectorAll(`[data-pagination-item="${group}"]`)];
      const total = Math.max(1, ...items.map(item => Number(item.dataset.page) || 1));
      const page = Math.min(window.dashboardCurrentPages?.[group] || 1, total);
      window.dashboardCurrentPages = window.dashboardCurrentPages || {};
      window.dashboardCurrentPages[group] = page;
      items.forEach(item => item.classList.toggle('hidden', Number(item.dataset.page) !== page));
      const label = document.getElementById(`${group}-current-page`);
      if (label) label.textContent = page;
      const previous = document.getElementById(`${group}-prev-btn`);
      const next = document.getElementById(`${group}-next-btn`);
      if (previous) previous.disabled = page <= 1;
      if (next) next.disabled = page >= total;
    });
  }

  async function refreshResidentSections() {
    if (refreshing || document.hidden) return;
    refreshing = true;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      const url = new URL('ResidentDashboard.php', window.location.href);
      url.searchParams.set('live_sections', '1');
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (!response.ok || response.redirected) return;
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (!page.querySelector('[data-tab-panel="home"]')) return;
      let changed = false;
      panels.forEach(panel => {
        if (panel.dataset.tabPanel === 'map') return;
        const incoming = page.querySelector(`[data-tab-panel="${panel.dataset.tabPanel}"]`);
        if (!incoming) return;
        const signature = incoming.innerHTML;
        if (snapshots.get(panel.dataset.tabPanel) === signature) return;
        // Defer only the section being edited; other sections can still update.
        if (panel.contains(document.activeElement) && document.activeElement.matches('input, textarea, select, [contenteditable]')) return;
        reconcile(panel, incoming);
        restorePagination(panel);
        snapshots.set(panel.dataset.tabPanel, signature);
        changed = true;
      });
      const staff = page.getElementById('staff_selection_container');
      const currentStaff = document.getElementById('staff_selection_container');
      if (staff && currentStaff && !document.querySelector('#appointment-modal input[name="health_worker_id"]:checked')) {
        reconcile(currentStaff, staff);
        if (typeof window.filterAvailableStaff === 'function') window.filterAvailableStaff();
      }
      if (changed) {
        // New cards were not registered with the initial reveal observer.
        document.querySelectorAll('[data-tab-panel] > div, [data-tab-panel] > article')
          .forEach(card => card.classList.add('is-visible'));
        window.dispatchEvent(new Event('resident:sections-updated'));
        if (window.lucide) window.lucide.createIcons();
      }
    } catch (error) {
      console.warn('Resident section update failed', error);
    } finally {
      window.clearTimeout(timeout);
      refreshing = false;
    }
  }

  refreshResidentSections();
  window.setInterval(refreshResidentSections, 5000);
  document.addEventListener('visibilitychange', refreshResidentSections);
  window.addEventListener('focus', refreshResidentSections);
})();
