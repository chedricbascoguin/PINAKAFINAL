(() => {
  const form = document.querySelector('[data-fp-client-type]');
  if (!form) return;
  const data = JSON.parse(document.getElementById('fp-client-type-history').textContent);
  const resident = form.elements.namedItem('resident_id');
  const method = form.elements.namedItem('contraceptive_method');
  const date = form.elements.namedItem('last_supply_date');
  const type = form.elements.namedItem('acceptor_type');
  const message = form.querySelector('[data-fp-type-message]');
  const reset = form.querySelector('[data-fp-type-auto]');
  let manual = false;
  function suggest() {
    if (manual) return;
    type.value = '';
    if (!resident.value || !method.value || !date.value) {
      message.textContent = 'Select the resident, method, and service date to fill the client type.';
      return;
    }
    if (!data.available) {
      message.textContent = 'History could not be loaded. Select the client type after checking the resident?s previous care.';
      return;
    }
    if (!data.methods.includes(method.value)) {
      message.textContent = 'Select the actual service or counseling type for this method.';
      return;
    }
    const history = data.entries.filter(row => String(row.resident_id) === resident.value && row.date <= date.value)
      .sort((a, b) => b.date.localeCompare(a.date) || (a.source === b.source ? b.id - a.id : (a.source === 'consultations' ? -1 : 1)));
    const previous = history[0];
    if (!previous) {
      type.value = 'New Acceptor';
      message.textContent = 'Suggested: New Acceptor. No previous method use is recorded here. Confirm that the resident has not used a method elsewhere.';
    } else if (previous.dropout) {
      type.value = 'Restarting User';
      message.textContent = 'Suggested: Restarting User. The latest recorded event is a dropout. Confirm that the resident is restarting today.';
    } else if (!data.methods.includes(previous.method)) {
      message.textContent = 'Previous method use is recorded, but its exact subtype is unknown. Review the history and select the client type.';
    } else if (previous.method !== method.value) {
      type.value = 'Changing Method';
      message.textContent = `Suggested: Changing Method. Previous method: ${previous.method} (${previous.date}).`;
    } else {
      type.value = 'Continuing User';
      message.textContent = `Suggested: Continuing User. The same method was recorded on ${previous.date}.`;
    }
  }
  type.addEventListener('change', () => {
    manual = true;
    reset.hidden = false;
    message.textContent = 'Your selection will be saved. Choose ?Use automatic suggestion? to use recorded history again.';
  });
  resident.addEventListener('change', () => { manual = false; reset.hidden = true; suggest(); });
  method.addEventListener('change', suggest);
  date.addEventListener('change', suggest);
  reset.addEventListener('click', () => { manual = false; reset.hidden = true; suggest(); });
  suggest();
})();
