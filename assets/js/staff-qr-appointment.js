(() => {
  function openAppointment() {
    const id = new URL(location.href).searchParams.get('qr_appointment');
    if (!/^[1-9]\d*$/.test(id || '')) return;
    const fp = document.querySelector('form[data-qr-fp-appointment]');
    if (fp) { fp.querySelector('select[name="contraceptive_method"]')?.focus(); return; }
    const vaccine = document.getElementById('nurse-vaccine-form');
    if (vaccine && vaccine.elements.namedItem('consultation_id')?.value === id) {
      vaccine.querySelector('select[name="vaccine_id"]')?.focus();
      return;
    }
    const lab = document.querySelector('select[name="consultation_id"]');
    if (lab && Array.from(lab.options).some(option => option.value === id)) {
      lab.value = id;
      lab.dispatchEvent(new Event('change', {bubbles:true}));
      lab.focus();
      return;
    }
    const input = Array.from(document.querySelectorAll('input[name="consultation_id"]')).find(input => input.value === id);
    const record = document.querySelector(`[data-qr-consultation-id="${id}"]`) || input?.closest('form');
    if (!record) {
      const notice = document.createElement('p');
      notice.setAttribute('role', 'status');
      notice.textContent = 'This appointment is not available in your current queue. It may have been updated or assigned to another provider.';
      notice.style.cssText = 'margin:16px;padding:16px;border:1px solid #f2d292;border-radius:12px;background:#fffbeb;color:#765218;font:14px system-ui';
      (document.querySelector('main') || document.body).prepend(notice);
      return;
    }
    // A visit card can contain its response form in a collapsed details panel.
    const response = input?.closest('details');
    if (response && record.contains(response)) response.open = true;
    const panel = record.closest('.triage-detail-panel');
    if (panel) {
      const tab = Array.from(document.querySelectorAll('[data-triage-tab]')).find(tab => tab.dataset.target === panel.id);
      tab?.click();
      const date = panel.querySelector('[data-consult-date-select]');
      if (date && record.id) { date.value = record.id; date.dispatchEvent(new Event('change', {bubbles:true})); }
    }
    // Reveal an existing record; never submit or change any appointment fields.
    for (let ancestor=record; ancestor && ancestor !== document.body; ancestor=ancestor.parentElement) {
      if (ancestor.tagName === 'DETAILS') ancestor.open = true;
      if (ancestor.matches('[data-consultation-detail-row]')) {
        ancestor.previousElementSibling?.classList.remove('hidden');
        if (ancestor.previousElementSibling) {
          ancestor.previousElementSibling.hidden = false;
          ancestor.previousElementSibling.style.removeProperty('display');
        }
      }
      ancestor.classList.remove('hidden');
      ancestor.hidden = false;
      if (ancestor.style.display === 'none') ancestor.style.removeProperty('display');
    }
    record.style.outline = '3px solid #14b8a6';
    record.style.outlineOffset = '6px';
    record.style.borderRadius = '10px';
    record.setAttribute('tabindex', '-1');
    record.scrollIntoView({behavior:'smooth',block:'center'});
    record.focus({preventScroll:true});
  }
  if (document.readyState === 'complete') openAppointment();
  else window.addEventListener('load', openAppointment, {once:true});
})();
